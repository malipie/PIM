<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Command;

use App\Identity\Application\InvitationService;
use App\Identity\Domain\Entity\User;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * TNT-P4-05 (#2906 / ADR-0036) — zaproszenie właściciela instancji.
 *
 * Zakładanie klienta z panelu nie może polegać na tym, że operator ustawia
 * hasło i przekazuje je klientowi kanałem, nad którym nikt nie panuje.
 * Provisioning tworzy właściciela z hasłem losowym, którego **nikt nie widzi**,
 * a ta komenda wysyła mu zaproszenie do ustawienia własnego.
 *
 * Komenda działa WEWNĄTRZ instancji klienta i to jest istota rzeczy: link
 * w mailu budowany jest z `APP_BASE_URL` **tej** instancji. Zaproszenie
 * wysłane przez platformę prowadziłoby pod adres panelu operatora, a nie pod
 * adres klienta (ADR-0036).
 */
#[AsCommand(
    name: 'pim:tenant:invite-owner',
    description: 'Wysyła właścicielowi instancji zaproszenie do ustawienia hasła.',
)]
final class InviteTenantOwnerCommand extends Command
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly InvitationService $invitations,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('code', null, InputOption::VALUE_REQUIRED, 'Kod tenanta tej instancji')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Adres właściciela')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Kod roli nadawanej z zaproszeniem', 'tenant_owner');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $code = $input->getOption('code');
        $email = $input->getOption('email');
        $roleCode = $input->getOption('role');

        if (!\is_string($code) || '' === $code || !\is_string($email) || '' === $email) {
            $io->error('Wymagane: --code oraz --email.');

            return Command::INVALID;
        }
        if ('' === $roleCode) {
            // Opcja ma wartość domyślną, ale konsola dopuszcza jej wyzerowanie
            // (`--role=`), a rola pusta wywróciłaby zaproszenie dopiero w
            // serwisie, z komunikatem o nieistniejącej roli.
            $roleCode = 'tenant_owner';
        }

        $tenant = $this->tenants->findByCode($code);
        if (null === $tenant) {
            $io->error(\sprintf('Tenant "%s" nie istnieje w tej instancji.', $code));

            return Command::FAILURE;
        }

        // Zapraszającym jest konto właściciela utworzone przy bootstrapie —
        // instancja nie zna operatora platformy, a `invitedBy` musi wskazywać
        // użytkownika TEJ instancji.
        $inviter = $this->em->getRepository(User::class)->findOneBy([
            'email' => $email,
            'tenant' => $tenant,
        ]);
        if (!$inviter instanceof User) {
            $io->error(\sprintf('Konto %s nie istnieje w tenancie "%s" — uruchom najpierw bootstrap.', $email, $code));

            return Command::FAILURE;
        }

        try {
            $this->invitations->create(
                tenant: $tenant,
                email: $email,
                roleCode: $roleCode,
                invitedBy: $inviter,
            );
        } catch (Throwable $exception) {
            $io->error(\sprintf('Nie udało się wysłać zaproszenia: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Zaproszenie dla %s wysłane (rola %s).', $email, $roleCode));

        return Command::SUCCESS;
    }
}
