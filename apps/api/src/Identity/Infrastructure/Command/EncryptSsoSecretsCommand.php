<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Command;

use App\Identity\Application\Sso\SsoConfigCipher;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * #2725 — one-shot sweep that encrypts SSO provider credentials stored before
 * the config's secret leaves were protected.
 *
 * Same shape as {@see EncryptTotpSecretsCommand}: not required for the feature
 * to work (the read path tolerates legacy plaintext), but it closes the
 * exposure window on existing providers instead of waiting for an operator to
 * re-save each one. Idempotent — leaves already carrying the `enc:v` envelope
 * are left alone, so a re-run is a no-op.
 *
 * A command rather than a migration because encryption needs the application's
 * master key, which migrations run without.
 */
#[AsCommand(
    name: 'pim:identity:encrypt-sso-secrets',
    description: 'Encrypt SSO provider credentials still stored as plaintext (#2725).',
)]
final class EncryptSsoSecretsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SsoConfigCipher $cipher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        /** @var list<array{id: string, config: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, config::text AS config FROM sso_providers');

        $touched = 0;
        foreach ($rows as $row) {
            /** @var array<string, mixed> $config */
            $config = json_decode($row['config'], true, 512, JSON_THROW_ON_ERROR);
            $protected = $this->cipher->protect($config);
            if ($protected === $config) {
                continue;
            }
            ++$touched;
            if ($dryRun) {
                continue;
            }
            $this->connection->executeStatement(
                'UPDATE sso_providers SET config = CAST(:config AS jsonb) WHERE id = :id',
                [
                    'config' => json_encode($protected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'id' => $row['id'],
                ],
            );
        }

        if (0 === $touched) {
            $io->success('No plaintext SSO credentials found — nothing to do.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf(
            $dryRun ? '%d SSO providers would have their credentials encrypted.' : '%d SSO providers encrypted.',
            $touched,
        ));

        return Command::SUCCESS;
    }
}
