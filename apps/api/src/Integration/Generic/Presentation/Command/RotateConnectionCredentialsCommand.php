<?php

declare(strict_types=1);

namespace App\Integration\Generic\Presentation\Command;

use App\Integration\Generic\Application\ConnectionCredentialsCipher;
use App\Integration\Generic\Domain\Repository\ConnectionRepositoryInterface;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * APIC-P5-03 (ADR-0022, epic APIC) — re-encrypts every stored connection
 * credential blob that was sealed with a BYOK key version older than the active
 * one ({@see ConnectionCredentialsCipher::rotateIfNeeded()}).
 *
 * Operator workflow (see `docs/operations/connection-credentials-rotation-runbook.md`):
 * add `APP_BYOK_KEY_V{n}`, deploy (new writes immediately use vN, old rows keep
 * decrypting with their original key), then run this sweep to upgrade the
 * at-rest rows so the previous key can be retired. The sweep is idempotent —
 * a connection already on the active version is skipped.
 *
 * Tenant scoping mirrors {@see DispatchDueSyncBindingsCommand}: FORCE RLS hides
 * `integration_connections` until the `app.current_tenant` GUC is set, so the
 * scan runs per tenant, binding the tenant through {@see TenantScopeBinder} and the
 * Postgres GUC (RLS).
 */
#[AsCommand(
    name: 'integration:credentials:rotate',
    description: 'Re-encrypt connection credentials sealed with a stale BYOK key version (all tenants).',
)]
final class RotateConnectionCredentialsCommand extends Command
{
    public function __construct(
        private readonly TenantScopeBinder $tenantScope,
        private readonly TenantRepositoryInterface $tenants,
        private readonly ConnectionRepositoryInterface $connections,
        private readonly ConnectionCredentialsCipher $cipher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report how many connections need rotation without re-encrypting anything.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');

        $rotated = 0;
        $scanned = 0;
        foreach ($this->tenants->findAllOrderedByCode() as $tenant) {
            $this->tenantScope->bind($tenant);
            try {
                foreach ($this->connections->findByTenant($tenant) as $connection) {
                    ++$scanned;
                    if ($dryRun) {
                        if ($this->cipher->needsRotation($connection)) {
                            ++$rotated;
                        }

                        continue;
                    }

                    if ($this->cipher->rotateIfNeeded($connection)) {
                        $this->connections->save($connection);
                        ++$rotated;
                    }
                }
            } finally {
                $this->tenantScope->release();
            }
        }

        $io->success($dryRun
            ? \sprintf('%d of %d connection(s) need credential rotation.', $rotated, $scanned)
            : \sprintf('Rotated %d of %d connection(s) to the active key version.', $rotated, $scanned));

        return Command::SUCCESS;
    }
}
