<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Command;

use App\Shared\Application\Crypto\SecretCipher;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * #2726 — one-shot sweep that encrypts TOTP secrets enrolled before the column
 * was protected.
 *
 * The read path tolerates legacy plaintext, so this is not required for the
 * feature to work — it exists so an operator can close the exposure window on
 * existing enrolments instead of waiting for each user to re-enrol. Idempotent:
 * rows already carrying the `enc:v` envelope are skipped, so a re-run (or a run
 * against a half-swept database) is a no-op.
 *
 * Deliberately a command rather than a Doctrine migration: encryption needs the
 * application's master key, which migrations run without.
 */
#[AsCommand(
    name: 'pim:identity:encrypt-totp-secrets',
    description: 'Encrypt TOTP secrets still stored as plaintext (#2726).',
)]
final class EncryptTotpSecretsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SecretCipher $cipher,
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

        /** @var list<array{id: string, totp_secret: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, totp_secret FROM users WHERE totp_secret IS NOT NULL AND totp_secret <> '' AND totp_secret NOT LIKE 'enc:v%'",
        );

        if ([] === $rows) {
            $io->success('No plaintext TOTP secrets found — nothing to do.');

            return Command::SUCCESS;
        }

        foreach ($rows as $row) {
            if ($dryRun) {
                continue;
            }
            $this->connection->executeStatement(
                'UPDATE users SET totp_secret = :secret WHERE id = :id',
                ['secret' => $this->cipher->protect($row['totp_secret']), 'id' => $row['id']],
            );
        }

        $io->success(\sprintf(
            $dryRun ? '%d plaintext TOTP secrets would be encrypted.' : '%d TOTP secrets encrypted.',
            \count($rows),
        ));

        return Command::SUCCESS;
    }
}
