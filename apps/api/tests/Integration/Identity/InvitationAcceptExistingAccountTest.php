<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\InvitationService;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\Invitation;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * #2962 — przejęcie konta, które JUŻ ISTNIEJE, przez zaproszenie.
 *
 * Przy tenancie zakładanym z panelu operatora konto właściciela istnieje
 * **zawsze**: `pim:tenant:bootstrap` tworzy je z hasłem tymczasowym, żeby
 * instancja w ogóle wstała i przeszła smoke test. Hasła nikt nie ogląda —
 * jest losowane i nigdzie nie pokazywane — więc zaproszenie jest jedyną drogą,
 * którą właściciel przejmuje swoje konto.
 *
 * Zanim to powstało, akceptacja próbowała WSTAWIĆ drugiego użytkownika z tym
 * samym adresem:
 *
 *     SQLSTATE[23505]: duplicate key value violates constraint "users_email_uniq"
 *
 * i kończyła się błędem 500 — przy JEDNOCZEŚNIE zużytym już zaproszeniu, bo
 * jego stan zapisywany był przed utworzeniem konta. Token przepadał, hasło
 * zostawało losowe i do świeżo założonej instancji nie dało się wejść.
 * Zgłoszone przez operatora na produkcji przy piątym tenancie.
 */
final class InvitationAcceptExistingAccountTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string EMAIL = 'wlasciciel@instancja.localhost';
    private const string HASLO_TYMCZASOWE = 'losowe-haslo-z-bootstrapu';
    private const string HASLO_WLASCICIELA = 'haslo-ktore-ustawia-wlasciciel';

    #[Test]
    public function zaproszenieUstawiaHasloNaIstniejacymKoncieZamiastPadac(): void
    {
        self::bootKernel();
        [$tenant, $service] = $this->przygotujTenanta();

        // Stan jak po `pim:tenant:bootstrap`: konto właściciela już jest.
        $this->utworzKontoZHaslemTymczasowym($tenant);

        ['token' => $token] = $service->create(
            tenant: $tenant,
            email: self::EMAIL,
            roleCode: 'tenant_owner',
            invitedBy: $this->dowolnyUzytkownik(),
        );

        $user = $service->accept($token, self::HASLO_WLASCICIELA);

        self::assertSame(self::EMAIL, $user->getEmail());

        // JEDNO konto, nie dwa — to jest sedno: wstawienie drugiego łamało
        // ograniczenie unikalności i wywracało całą operację.
        self::assertCount(1, $this->wszyscyUzytkownicy(), 'Zaproszenie nie może tworzyć drugiego konta na ten sam adres.');

        // Hasło właściciela działa, tymczasowe już nie.
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, self::HASLO_WLASCICIELA), 'Właściciel musi móc wejść swoim hasłem.');
        self::assertFalse($hasher->isPasswordValid($user, self::HASLO_TYMCZASOWE), 'Hasło tymczasowe musi przestać działać.');
    }

    /**
     * Drugie kliknięcie w ten sam link nie może ruszyć konta. Ochrona
     * z AUD-024 zostaje — tyle że jako jawne sprawdzenie stanu, a nie jako
     * efekt uboczny kolejności zapisów.
     */
    #[Test]
    public function drugieUzycieTokenaNieZmieniaHasla(): void
    {
        self::bootKernel();
        [$tenant, $service] = $this->przygotujTenanta();
        $this->utworzKontoZHaslemTymczasowym($tenant);

        ['token' => $token] = $service->create(
            tenant: $tenant,
            email: self::EMAIL,
            roleCode: 'tenant_owner',
            invitedBy: $this->dowolnyUzytkownik(),
        );
        $service->accept($token, self::HASLO_WLASCICIELA);

        try {
            $service->accept($token, 'haslo-podstawione-przez-kogos-innego');
            self::fail('Zużyty token musi zostać odrzucony.');
        } catch (LogicException) {
            // oczekiwane
        }

        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail(self::EMAIL);
        self::assertNotNull($user);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue(
            $hasher->isPasswordValid($user, self::HASLO_WLASCICIELA),
            'Hasło ustawione przy pierwszym użyciu musi przetrwać drugą próbę.',
        );
    }

    /**
     * Ścieżka bez istniejącego konta — zaproszenie zwykłego użytkownika do
     * działającego tenanta. Musi działać tak jak dotąd.
     */
    #[Test]
    public function zaproszenieNowegoUzytkownikaNadalTworzyKonto(): void
    {
        self::bootKernel();
        [$tenant, $service] = $this->przygotujTenanta();

        ['token' => $token] = $service->create(
            tenant: $tenant,
            email: 'ktos-nowy@instancja.localhost',
            roleCode: 'tenant_owner',
            invitedBy: $this->dowolnyUzytkownik(),
        );
        $user = $service->accept($token, self::HASLO_WLASCICIELA);

        self::assertSame('ktos-nowy@instancja.localhost', $user->getEmail());
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, self::HASLO_WLASCICIELA));
    }

    /**
     * Adres zajęty przez konto z innego tenanta w tej samej bazie: kolumna
     * `users.email` jest unikalna globalnie, więc zapis i tak by nie przeszedł.
     * Ma polec czytelnym 400, z zaproszeniem NIETKNIĘTYM — żeby dało się je
     * wystawić ponownie na inny adres.
     */
    #[Test]
    public function adresZajetyPrzezInnegoTenantaJestOdrzucanyBezZuzyciaZaproszenia(): void
    {
        self::bootKernel();
        [$tenant, $service] = $this->przygotujTenanta();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $obcy = new Tenant('obcy', 'Obcy Tenant');
        $em->persist($obcy);
        $em->flush();

        $stub = new User($obcy, self::EMAIL, '');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $em->persist(new User($obcy, self::EMAIL, $hasher->hashPassword($stub, 'haslo-obcego-tenanta')));
        $em->flush();

        ['invitation' => $zaproszenie, 'token' => $token] = $service->create(
            tenant: $tenant,
            email: self::EMAIL,
            roleCode: 'tenant_owner',
            invitedBy: $this->dowolnyUzytkownik(),
        );

        try {
            $service->accept($token, self::HASLO_WLASCICIELA);
            self::fail('Adres zajęty przez innego tenanta musi zostać odrzucony.');
        } catch (LogicException) {
            // oczekiwane — 400, nie 500 z bazy
        }

        $em->clear();
        $poProbie = $em->getRepository(Invitation::class)->find($zaproszenie->getId());
        self::assertInstanceOf(Invitation::class, $poProbie);
        self::assertFalse($poProbie->isAccepted(), 'Nieudana próba nie może zużyć zaproszenia.');
    }

    /**
     * @return array{Tenant, InvitationService}
     */
    private function przygotujTenanta(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(RbacSeeder::class)->seed();

        $tenant = new Tenant('instancja', 'Instancja Klienta');
        $em->persist($tenant);
        $em->flush();

        // Rola per tenant, nie globalna — seeder PRD tworzy komplet na tenanta.
        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);

        return [$tenant, self::getContainer()->get(InvitationService::class)];
    }

    private function utworzKontoZHaslemTymczasowym(Tenant $tenant): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $stub = new User($tenant, self::EMAIL, '');
        $user = new User($tenant, self::EMAIL, $hasher->hashPassword($stub, self::HASLO_TYMCZASOWE));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function dowolnyUzytkownik(): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => 'instancja']);
        self::assertInstanceOf(Tenant::class, $tenant);

        $stub = new User($tenant, 'zapraszajacy@instancja.localhost', '');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User($tenant, 'zapraszajacy@instancja.localhost', $hasher->hashPassword($stub, 'dowolne-haslo-testowe'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * @return list<User>
     */
    private function wszyscyUzytkownicy(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        /** @var list<User> $users */
        $users = $em->getRepository(User::class)->findBy(['email' => self::EMAIL]);

        return $users;
    }
}
