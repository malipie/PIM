<?php

declare(strict_types=1);

namespace App\ApiConfigurator\Presentation\Console;

use App\ApiConfigurator\Application\ApiKeyGenerator;
use App\ApiConfigurator\Domain\Entity\ApiKey;
use App\ApiConfigurator\Domain\Repository\ApiKeyRepositoryInterface;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

use const DATE_ATOM;

/**
 * Mints a new ApiKey for a given tenant.
 *
 * Per ADR-0016 the raw key is echoed exactly once — the operator MUST
 * capture it before the command exits. The persisted row carries only
 * the Argon2id digest + the 12-character display prefix.
 *
 * Example:
 *
 *     pim:apikey:generate \
 *         --tenant=acme \
 *         --name="Storefront partner X" \
 *         --scopes=storefront,sitemap \
 *         --rate-limit=2000 \
 *         --expires-in-days=365
 *
 * Output is plain text — no JSON wrapper — so an operator can pipe the
 * raw key straight into a credential store without parsing.
 */
#[AsCommand(
    name: 'pim:apikey:generate',
    description: 'Mint a new API key for a tenant. The raw secret is echoed once and never stored.',
)]
final class GenerateApiKeyCommand extends Command
{
    public function __construct(
        private readonly ApiKeyGenerator $generator,
        private readonly ApiKeyRepositoryInterface $repository,
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly TenantScopeBinder $tenantScope,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant code or UUID')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name for the key')
            ->addOption('scopes', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of ApiProfile codes', '')
            ->addOption('rate-limit', null, InputOption::VALUE_REQUIRED, 'Hourly request budget for this key', '1000')
            ->addOption('expires-in-days', null, InputOption::VALUE_REQUIRED, 'Optional TTL in days (no value = never expires)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenantOption = (string) $input->getOption('tenant');
        $name = (string) $input->getOption('name');

        if ('' === $tenantOption) {
            $io->error('Missing required --tenant option.');

            return Command::INVALID;
        }

        if ('' === $name) {
            $io->error('Missing required --name option.');

            return Command::INVALID;
        }

        $tenant = $this->resolveTenant($tenantOption);
        if (null === $tenant) {
            $io->error(\sprintf('Tenant "%s" not found.', $tenantOption));

            return Command::FAILURE;
        }

        /** @var string $scopesRaw */
        $scopesRaw = $input->getOption('scopes');
        $scopes = '' === $scopesRaw
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $scopesRaw)), static fn (string $s): bool => '' !== $s));

        /** @var string $rateLimitRaw */
        $rateLimitRaw = $input->getOption('rate-limit');
        $rateLimit = (int) $rateLimitRaw;
        if ($rateLimit <= 0) {
            $io->error('--rate-limit must be a positive integer.');

            return Command::INVALID;
        }

        $expiresAt = null;
        $expiresInDaysRaw = $input->getOption('expires-in-days');
        if (null !== $expiresInDaysRaw && '' !== $expiresInDaysRaw) {
            $days = (int) $expiresInDaysRaw;
            if ($days <= 0) {
                $io->error('--expires-in-days must be a positive integer.');

                return Command::INVALID;
            }
            $expiresAt = new DateTimeImmutable(\sprintf('+%d days', $days));
        }

        // Bind the tenant context for the duration of the command so the
        // TenantAssignmentListener can stamp the key on persist.
        $this->tenantScope->bind($tenant);

        $generated = $this->generator->generate();

        $apiKey = new ApiKey(
            keyHash: $generated->keyHash,
            keyPrefix: $generated->keyPrefix,
            name: $name,
            scopes: $scopes,
            expiresAt: $expiresAt,
            rateLimitPerHour: $rateLimit,
        );

        $this->repository->save($apiKey);

        $io->success('API key generated. Capture the raw key now — it will not be shown again.');
        $io->definitionList(
            ['Key ID' => $apiKey->getId()->toRfc4122()],
            ['Tenant' => $tenant->getCode()],
            ['Name' => $name],
            ['Prefix (safe to log)' => $generated->keyPrefix],
            ['Scopes' => '' === $scopesRaw ? '(none)' : $scopesRaw],
            ['Rate limit/hour' => (string) $rateLimit],
            ['Expires at' => null !== $expiresAt ? $expiresAt->format(DATE_ATOM) : '(never)'],
        );

        $io->section('Raw API key (copy now — shown once)');
        $output->writeln($generated->rawKey);

        return Command::SUCCESS;
    }

    private function resolveTenant(string $codeOrUuid): ?Tenant
    {
        if (Uuid::isValid($codeOrUuid)) {
            return $this->tenantRepository->findById(Uuid::fromString($codeOrUuid));
        }

        return $this->tenantRepository->findByCode($codeOrUuid);
    }
}
