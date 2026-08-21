<?php

declare(strict_types=1);

namespace App\DataFixtures\Bootstrap;

use App\Catalog\Application\BuiltInSmartFilterPresetsSeeder;
use App\Catalog\Contracts\Service\TenantCatalogBootstrap;
use App\Channel\Domain\Entity\Locale;
use App\Channel\Domain\Entity\TenantLocale;
use App\Identity\Application\PrdPermissionSeeder;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\PermissionRepositoryInterface;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Process\Process;

use const PHP_BINARY;

/**
 * Production-safe first-run bootstrap: tenant + owner account + the
 * idempotent seed baseline that fixtures provide in dev.
 *
 * Fixtures (DoctrineFixturesBundle) are dev/test-only and bake the public
 * 'changeme' password, so a fresh production database had no supported way
 * to create the first tenant/owner (#2138 go-live gap). This command is the
 * prod path:
 *
 *   PIM_OWNER_PASSWORD='<strong password>' php bin/console pim:tenant:bootstrap \
 *       --code=acme --name="Acme" --owner-email=owner@example.com
 *
 * Idempotent: an existing tenant is reused, seeders are re-run (all
 * built-in seeders check before insert), an existing owner is reported and
 * left untouched. The password never travels through argv — only through
 * the environment variable named by --owner-password-env.
 *
 * Like pim:demo:seed-electronics, the command re-execs itself once on
 * DATABASE_URL_OWNER when available: `pim_app` is NOBYPASSRLS and a CLI run
 * has no tenant GUC, so cross-tenant writes need the owner DSN.
 */
#[AsCommand(
    name: 'pim:tenant:bootstrap',
    description: 'Create (or complete) a tenant with an owner account and the built-in seed baseline — prod-safe.',
)]
final class TenantBootstrapCommand extends Command
{
    private const int MIN_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PrdPermissionSeeder $prdPermissionSeeder,
        private readonly RbacSeeder $rbacSeeder,
        private readonly RoleRepositoryInterface $roleRepository,
        private readonly PermissionRepositoryInterface $permissionRepository,
        private readonly SeedTenantPrdRolesService $tenantPrdRolesSeeder,
        private readonly TenantCatalogBootstrap $tenantCatalogBootstrap,
        private readonly BuiltInSmartFilterPresetsSeeder $smartFilterPresetsSeeder,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('code', null, InputOption::VALUE_REQUIRED, 'Tenant code (slug, e.g. "demo")')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Tenant display name (defaults to the code)')
            ->addOption('owner-email', null, InputOption::VALUE_REQUIRED, 'E-mail of the owner account to create')
            ->addOption(
                'owner-password-env',
                null,
                InputOption::VALUE_REQUIRED,
                'Name of the environment variable holding the owner password (never pass the password itself in argv)',
                'PIM_OWNER_PASSWORD',
            )
            ->addOption('locale-default', null, InputOption::VALUE_REQUIRED, 'Default tenant locale (full code)', 'pl_PL')
            ->addOption(
                'locale-secondary',
                null,
                InputOption::VALUE_REQUIRED,
                'Secondary tenant locale with fallback to the default; empty string disables',
                'en_US',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // `pim_app` is NOBYPASSRLS and a CLI run never sets the per-request
        // tenant GUC — cross-tenant bootstrap must run on the owner DSN.
        $ownerDsnRaw = $_SERVER['DATABASE_URL_OWNER'] ?? $_ENV['DATABASE_URL_OWNER'] ?? null;
        $currentDsnRaw = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;
        $ownerDsn = \is_string($ownerDsnRaw) ? $ownerDsnRaw : '';
        $currentDsn = \is_string($currentDsnRaw) ? $currentDsnRaw : '';
        $elevated = '1' === ($_SERVER['PIM_BOOTSTRAP_ELEVATED'] ?? $_ENV['PIM_BOOTSTRAP_ELEVATED'] ?? '');

        if (!$elevated && '' !== $ownerDsn && $ownerDsn !== $currentDsn) {
            $io->note('Re-running on the owner connection (DATABASE_URL_OWNER) — bootstrap bypasses FORCE RLS.');
            $childCommand = [PHP_BINARY, $this->projectDir.'/bin/console', $this->getName() ?? 'pim:tenant:bootstrap'];
            foreach (['code', 'name', 'owner-email', 'owner-password-env', 'locale-default', 'locale-secondary'] as $passthrough) {
                $value = $input->getOption($passthrough);
                if (\is_string($value) && '' !== $value) {
                    $childCommand[] = '--'.$passthrough;
                    $childCommand[] = $value;
                }
            }
            $process = new Process(
                $childCommand,
                $this->projectDir,
                ['DATABASE_URL' => $ownerDsn, 'PIM_BOOTSTRAP_ELEVATED' => '1'],
                null,
                null,
            );
            $process->run(static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            });

            return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
        }

        $code = $input->getOption('code');
        $ownerEmail = $input->getOption('owner-email');
        if (!\is_string($code) || '' === $code || !\is_string($ownerEmail) || '' === $ownerEmail) {
            $io->error('Both --code and --owner-email are required.');

            return Command::INVALID;
        }
        $nameOpt = $input->getOption('name');
        $name = \is_string($nameOpt) && '' !== $nameOpt ? $nameOpt : $code;

        // Options below declare defaults, so phpstan-symfony types them as
        // plain string — only emptiness needs guarding.
        $passwordEnvOpt = $input->getOption('owner-password-env');
        $passwordEnv = '' !== $passwordEnvOpt ? $passwordEnvOpt : 'PIM_OWNER_PASSWORD';
        $passwordRaw = $_SERVER[$passwordEnv] ?? $_ENV[$passwordEnv] ?? null;
        $password = \is_string($passwordRaw) ? $passwordRaw : '';
        if (\strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(\sprintf(
                'Environment variable %s must hold the owner password (min %d characters). '.
                'Example: %s=$(openssl rand -base64 18) php bin/console %s ...',
                $passwordEnv,
                self::MIN_PASSWORD_LENGTH,
                $passwordEnv,
                $this->getName() ?? 'pim:tenant:bootstrap',
            ));

            return Command::INVALID;
        }

        // 1. Global RBAC baseline. The PRD §3.2 permission catalogue comes
        //    first: SeedTenantPrdRolesService below resolves its role
        //    templates against these codes, and on a migrations-only
        //    database they do not exist yet (the codes used to ship only in
        //    dev fixtures — see PrdPermissionSeeder).
        $created = $this->prdPermissionSeeder->seed();
        if ($created > 0) {
            $io->text(\sprintf('PRD permission catalogue: %d code(s) added.', $created));
        }
        $this->rbacSeeder->seed();

        // 2. Tenant (reuse when present — idempotent re-runs).
        $tenantRepo = $this->em->getRepository(Tenant::class);
        $tenant = $tenantRepo->findOneBy(['code' => $code]);
        if (null === $tenant) {
            $tenant = new Tenant($code, $name);
            $this->em->persist($tenant);
            $this->em->flush();
            $io->text(\sprintf('Tenant "%s" created.', $code));
        } else {
            $io->text(\sprintf('Tenant "%s" already exists — reusing.', $code));
        }

        // 3. Per-tenant built-in baseline. #2942 — this used to repeat the
        //    seeder chain by hand and drifted from `TenantCatalogBootstrapper`
        //    the moment a step was added there (the `name` label attribute was
        //    exactly such a step). Call the shared bootstrap instead, so a
        //    CLI-provisioned tenant and a panel-provisioned one cannot differ.
        $this->tenantCatalogBootstrap->bootstrap($tenant);
        $this->tenantPrdRolesSeeder->seed($tenant);
        $this->smartFilterPresetsSeeder->seed();

        // 4. Tenant locales — only on first run (an operator may have
        //    reconfigured them since; never fight explicit configuration).
        $this->seedTenantLocales($io, $tenant, $input);

        // 5. super_admin carries every tenant-scoped permission (platform.*
        //    stays operator-only — AUD-003). Mirrors the dev fixtures grant.
        $superAdmin = $this->roleRepository->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        if (null === $superAdmin) {
            $io->error('RbacSeeder did not produce the super_admin role — aborting.');

            return Command::FAILURE;
        }
        $existingCodes = [];
        foreach ($superAdmin->getPermissions() as $existing) {
            $existingCodes[] = $existing->getCode();
        }
        foreach ($this->permissionRepository->findAllOrdered() as $permission) {
            if (str_starts_with($permission->getCode(), 'platform.')) {
                continue;
            }
            if (\in_array($permission->getCode(), $existingCodes, true)) {
                continue;
            }
            $superAdmin->getPermissions()->add($permission);
        }

        // 6. Owner account (super_admin + tenant_owner, like the dev admin —
        //    both permission graphs until Phase 6 consolidates them).
        $existingUser = $this->em->getRepository(User::class)->findOneBy(['email' => $ownerEmail, 'tenant' => $tenant]);
        if (null !== $existingUser) {
            $io->warning(\sprintf(
                'User %s already exists in tenant "%s" — leaving the account (and its password) untouched.',
                $ownerEmail,
                $code,
            ));
        } else {
            $stub = new User($tenant, $ownerEmail, '', []);
            $owner = new User($tenant, $ownerEmail, $this->passwordHasher->hashPassword($stub, $password), []);
            $owner->addRole($superAdmin);
            $tenantOwner = $this->roleRepository->findByCode('tenant_owner', $tenant);
            if (null === $tenantOwner) {
                $io->error('SeedTenantPrdRolesService did not produce tenant_owner — aborting before creating a half-privileged account.');

                return Command::FAILURE;
            }
            $owner->addRole($tenantOwner);
            $this->em->persist($owner);
            $io->text(\sprintf('Owner %s created with super_admin + tenant_owner.', $ownerEmail));
        }

        $this->em->flush();

        $io->success(\sprintf('Tenant "%s" bootstrapped.', $code));
        $io->listing([
            'Demo catalog (optional): pim:demo:seed-electronics --tenant='.$code,
            'Search index: pim:search:reindex',
            'Completeness: pim:catalog:recalculate-completeness',
        ]);

        return Command::SUCCESS;
    }

    private function seedTenantLocales(SymfonyStyle $io, Tenant $tenant, InputInterface $input): void
    {
        $existing = $this->em->getRepository(TenantLocale::class)->count(['tenant' => $tenant]);
        if ($existing > 0) {
            $io->text(\sprintf('Tenant locales already configured (%d) — skipping.', $existing));

            return;
        }

        $defaultOpt = $input->getOption('locale-default');
        $defaultCode = '' !== $defaultOpt ? $defaultOpt : 'pl_PL';
        $secondaryCode = $input->getOption('locale-secondary');

        $localeRepo = $this->em->getRepository(Locale::class);
        $default = $localeRepo->findOneBy(['code' => $defaultCode]);
        if (!$default instanceof Locale) {
            throw new RuntimeException(\sprintf(
                'Locale "%s" is missing — run doctrine:migrations:migrate first (the locale seed migration provides it).',
                $defaultCode,
            ));
        }
        $this->em->persist(new TenantLocale($default, true, true, null, 0, $tenant));

        if ('' !== $secondaryCode && $secondaryCode !== $defaultCode) {
            $secondary = $localeRepo->findOneBy(['code' => $secondaryCode]);
            if (!$secondary instanceof Locale) {
                throw new RuntimeException(\sprintf('Locale "%s" is missing — run migrations first.', $secondaryCode));
            }
            $this->em->persist(new TenantLocale($secondary, false, true, $default, 1, $tenant));
        }

        $io->text(\sprintf('Tenant locales seeded (%s default%s).', $defaultCode, '' !== $secondaryCode ? ', '.$secondaryCode : ''));
    }
}
