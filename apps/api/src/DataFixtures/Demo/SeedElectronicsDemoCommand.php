<?php

declare(strict_types=1);

namespace App\DataFixtures\Demo;

use App\Channel\Domain\Entity\Channel;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

use const PHP_BINARY;

/**
 * Operator test-data command: electronics-shop demo dataset.
 *
 * Replaces the tenant's catalog objects with the electronics dataset built
 * by {@see ElectronicsDemoSeeder}: 20 product categories, a custom
 * `service` ObjectType with its own tree, 14 attribute groups (7 pinned
 * to categories), 3 sales channels (BaseLinker / Shopify / Magento) and
 * N products with generated images + PL/EN values.
 *
 * By default the tenant's existing catalog objects / assets / channels
 * are purged first (FK cascades cover the value/junction tables) — the
 * base fixtures' DEMO-* catalog would otherwise sit next to the
 * electronics data and confuse smoke testing. Attributes, ObjectTypes,
 * users, roles and locales are never purged.
 *
 * Run `pim:search:reindex` afterwards to rebuild Meilisearch.
 */
#[AsCommand(
    name: 'pim:demo:seed-electronics',
    description: 'Seed the electronics-shop demo dataset (categories, services, channels, N products with images).',
)]
final class SeedElectronicsDemoCommand extends Command
{
    private const array CHANNELS = [
        'baselinker' => 'BaseLinker',
        'shopify' => 'Shopify',
        'magento' => 'Magento',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly ElectronicsDemoSeeder $seeder,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant code to seed into', 'demo')
            ->addOption('products', null, InputOption::VALUE_REQUIRED, 'Number of products to generate', '1000')
            ->addOption('keep-existing', null, InputOption::VALUE_NONE, 'Skip the purge of existing catalog objects / assets / channels');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Same elevation hop as pim:db:reset (GOLIVE #2178): the runtime
        // `pim_app` role is NOBYPASSRLS and a CLI run never sets the
        // per-request tenant GUC, so cross-tenant seeding must run on the
        // owner DSN. Re-exec once in a child process when available.
        $ownerDsnRaw = $_SERVER['DATABASE_URL_OWNER'] ?? $_ENV['DATABASE_URL_OWNER'] ?? null;
        $currentDsnRaw = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;
        $ownerDsn = \is_string($ownerDsnRaw) ? $ownerDsnRaw : '';
        $currentDsn = \is_string($currentDsnRaw) ? $currentDsnRaw : '';
        $elevated = '1' === ($_SERVER['PIM_DEMO_SEED_ELEVATED'] ?? $_ENV['PIM_DEMO_SEED_ELEVATED'] ?? '');

        if (!$elevated && '' !== $ownerDsn && $ownerDsn !== $currentDsn) {
            $io->note('Re-running on the owner connection (DATABASE_URL_OWNER) — the seed path bypasses FORCE RLS.');
            $childCommand = [PHP_BINARY, $this->projectDir.'/bin/console', $this->getName() ?? 'pim:demo:seed-electronics'];
            foreach (['tenant', 'products'] as $passthrough) {
                $value = $input->getOption($passthrough);
                if (\is_string($value) || \is_int($value)) {
                    $childCommand[] = '--'.$passthrough;
                    $childCommand[] = (string) $value;
                }
            }
            if (true === $input->getOption('keep-existing')) {
                $childCommand[] = '--keep-existing';
            }
            $process = new Process(
                $childCommand,
                $this->projectDir,
                ['DATABASE_URL' => $ownerDsn, 'PIM_DEMO_SEED_ELEVATED' => '1'],
                null,
                null,
            );
            $process->run(static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            });

            return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
        }

        $tenantCode = $input->getOption('tenant');
        if ('' === $tenantCode) {
            $io->error('--tenant must be a non-empty string.');

            return Command::INVALID;
        }
        $productCount = max(1, (int) $input->getOption('products'));

        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['code' => $tenantCode]);
        if (null === $tenant) {
            $io->error(\sprintf('Tenant "%s" not found — run fixtures first (pim:db:reset --with-fixtures).', $tenantCode));

            return Command::FAILURE;
        }

        if (true !== $input->getOption('keep-existing')) {
            $this->purge($tenant, $io);
            // purge() clears the EntityManager — re-fetch so the channels
            // and seeder work with a managed Tenant instance.
            $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['code' => $tenantCode]);
            if (null === $tenant) {
                $io->error('Tenant vanished after purge — aborting.');

                return Command::FAILURE;
            }
        }

        $channelIds = $this->ensureChannels($tenant);
        $io->writeln(\sprintf('Channels ready: %s.', implode(', ', array_keys($channelIds))));

        $started = microtime(true);
        $this->seeder->seed(
            $tenant,
            $channelIds,
            $productCount,
            static function (string $message) use ($io): void {
                $io->writeln($message);
            },
            reuseExisting: true === $input->getOption('keep-existing'),
        );

        $io->success(\sprintf(
            'Electronics demo dataset seeded for tenant "%s" in %.1fs (%d products). Run pim:search:reindex to rebuild Meilisearch.',
            $tenantCode,
            microtime(true) - $started,
            $productCount,
        ));

        return Command::SUCCESS;
    }

    /**
     * Purge catalog objects, DAM assets and channels of the tenant. FK
     * `ON DELETE CASCADE` covers object_values / object_categories /
     * category_attribute_groups / product_assets / asset_variants /
     * channel_* rows (verified against the live schema). Runs on the CLI
     * connection which, like `doctrine:fixtures:load`, is not subject to
     * the per-request RLS tenant GUC.
     */
    private function purge(Tenant $tenant, SymfonyStyle $io): void
    {
        // tenant-safe: explicit tenant_id filter in WHERE (demo tooling purge)
        $params = ['tenant' => $tenant->getId()->toRfc4122()];
        $objects = $this->connection->executeStatement('DELETE FROM objects WHERE tenant_id = :tenant', $params);
        $assets = $this->connection->executeStatement('DELETE FROM assets WHERE tenant_id = :tenant', $params);
        $channels = $this->connection->executeStatement('DELETE FROM channels WHERE tenant_id = :tenant', $params);
        $this->em->clear();
        $io->writeln(\sprintf('Purged: %d objects, %d assets, %d channels.', $objects, $assets, $channels));
    }

    /**
     * @return array<string, Uuid> channel code => id
     */
    private function ensureChannels(Tenant $tenant): array
    {
        $repository = $this->em->getRepository(Channel::class);
        $ids = [];
        foreach (self::CHANNELS as $code => $name) {
            $channel = $repository->findOneBy(['code' => $code, 'tenant' => $tenant]);
            if (null === $channel) {
                $channel = new Channel($code, $name);
                $channel->assignTenant($tenant);
                $this->em->persist($channel);
            }
            $ids[$code] = $channel->getId();
        }
        $this->em->flush();

        return $ids;
    }
}
