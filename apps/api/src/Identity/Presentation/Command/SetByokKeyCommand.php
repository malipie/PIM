<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Command;

use App\Identity\Application\ByokKeyManager;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * `pim:byok:set` — operator alternative to the (future) admin UI.
 *
 * Sets / rotates a tenant's Anthropic API key. The plaintext is read
 * from stdin (interactive prompt without echo) so it never lands in
 * shell history. Saves the encrypted ciphertext per ADR-0017 and
 * prints only the safe display prefix.
 */
#[AsCommand(
    name: 'pim:byok:set',
    description: 'Set or rotate an Anthropic API key for a tenant (BYOK, AES-256-GCM at rest).',
)]
final class SetByokKeyCommand extends Command
{
    public function __construct(
        private readonly ByokKeyManager $manager,
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantScopeBinder $tenantScope,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant code or UUID.')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Disable BYOK for the tenant (keeps the ciphertext for re-enable).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $tenantOption */
        $tenantOption = $input->getOption('tenant');
        if ('' === $tenantOption) {
            $io->error('Missing required --tenant option.');

            return Command::INVALID;
        }

        $tenant = Uuid::isValid($tenantOption)
            ? $this->tenants->findById(Uuid::fromString($tenantOption))
            : $this->tenants->findByCode($tenantOption);

        if (null === $tenant) {
            $io->error(\sprintf('Tenant "%s" not found.', $tenantOption));

            return Command::FAILURE;
        }

        $this->tenantScope->bind($tenant);

        if (true === $input->getOption('disable')) {
            $this->manager->disable($tenant);
            $io->success(\sprintf('BYOK disabled for tenant "%s".', $tenant->getCode()));

            return Command::SUCCESS;
        }

        $question = new Question('Anthropic API key (paste, hidden): ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $answer = $io->askQuestion($question);
        $plaintext = \is_string($answer) ? $answer : '';
        if ('' === $plaintext) {
            $io->error('Empty API key — refusing to save.');

            return Command::INVALID;
        }
        if (\strlen($plaintext) < 16) {
            $io->error('API key looks too short — refusing to save.');

            return Command::INVALID;
        }

        $config = $this->manager->setKey($tenant, $plaintext);

        $io->success(\sprintf(
            'BYOK key stored for tenant "%s" (prefix %s, encryption v%d).',
            $tenant->getCode(),
            $config->getKeyPrefix(),
            $config->getEncryptionKeyVersion(),
        ));

        return Command::SUCCESS;
    }
}
