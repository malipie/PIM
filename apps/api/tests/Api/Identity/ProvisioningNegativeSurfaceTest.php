<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Identity\Application\RbacSeeder;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * TNT-P4-09 (#2910 / ADR-0036) — testy negatywne granicy: żądanie HTTP →
 * kolejka → provisioner → demon Dockera.
 *
 * Powierzchnia jest poważna: aplikacja webowa **pośrednio uruchamia kontenery
 * na hoście**. Ten plik pilnuje pierwszego odcinka tej drogi — tego, że żadne
 * żądanie, które nie powinno, **nie zostawia zlecenia w kolejce**.
 *
 * Bramką jest tu obecność albo brak PLIKU, nie kod odpowiedzi. 400 przy
 * jednoczesnym odłożeniu zlecenia byłoby porażką, a samo 400 tego nie widać.
 * Walidację po drugiej stronie (provisioner nie ufa wołającemu i sprawdza
 * wszystko ponownie) pokrywa `docker/provisioner/test_provisioner.py`.
 */
final class ProvisioningNegativeSurfaceTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string PLATFORM_OPERATOR_EMAIL = 'ops@platform.localhost';
    private const string TENANT_OWNER_EMAIL = 'owner@acme.localhost';

    private string $spool = '';

    protected function setUp(): void
    {
        $this->spool = sys_get_temp_dir().'/pim-spool-neg-'.bin2hex(random_bytes(6));
        mkdir($this->spool);
        $_ENV['PROVISIONER_SPOOL'] = $this->spool;
        $_SERVER['PROVISIONER_SPOOL'] = $this->spool;

        parent::setUp();

        self::getContainer()->get('limiter.auth_login')->create('127.0.0.1')->reset();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();

        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $platformOperator = $roles->findGlobalByCode(RbacMatrix::ROLE_PLATFORM_OPERATOR);
        $superAdmin = $roles->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        \assert(null !== $platformOperator && null !== $superAdmin);

        $tenant = new Tenant('acme', 'Acme Industries');
        $em->persist($tenant);
        $em->flush();

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $operator = $this->makeUser($tenant, self::PLATFORM_OPERATOR_EMAIL, $hasher);
        $operator->addRole($platformOperator);
        $em->persist($operator);

        // Właściciel tenanta z globalnym `super_admin` — dokładnie ten kształt,
        // który fixtures nadają administratorowi każdego klienta. To NIE jest
        // operator platformy i do kolejki dotrzeć nie może.
        $owner = $this->makeUser($tenant, self::TENANT_OWNER_EMAIL, $hasher);
        $owner->addRole($superAdmin);
        $em->persist($owner);

        $em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->spoolFiles() as $file) {
            unlink($file);
        }
        if ('' !== $this->spool && is_dir($this->spool)) {
            rmdir($this->spool);
        }
        unset($_ENV['PROVISIONER_SPOOL'], $_SERVER['PROVISIONER_SPOOL']);

        parent::tearDown();
    }

    /**
     * Kod tenanta trafia — po drugiej stronie kolejki — do argumentów
     * `docker compose -p pim-<kod>`. Gdyby wyszedł stąd nietknięty, jedno pole
     * formularza sięgałoby demona Dockera.
     *
     * @return iterable<string, array{string}>
     */
    public static function hostileCodes(): iterable
    {
        yield 'średnik i polecenie' => ['acme; rm -rf /'];
        yield 'podstawienie polecenia' => ['x$(id)'];
        yield 'odwrotny apostrof' => ['acme`whoami`'];
        yield 'potok' => ['acme | cat /etc/passwd'];
        yield 'nowa linia' => ["acme\nrm -rf /"];
        yield 'wyjście ze ścieżki' => ['../../etc'];
        yield 'spacja i flaga' => ['acme --volume /:/host'];
        yield 'null byte' => ["acme\0evil"];
    }

    #[Test]
    #[DataProvider('hostileCodes')]
    public function aHostileTenantCodeNeverReachesTheQueue(string $code): void
    {
        $client = $this->clientFor(self::PLATFORM_OPERATOR_EMAIL);
        $client->request('POST', '/api/admin/tenants', ['json' => [
            'code' => $code,
            'name' => 'Hostile',
            'owner_email' => 'owner@hostile.pl',
        ]]);

        // Kolejność ma znaczenie: pusty katalog zleceń jest niezmiennikiem
        // WAŻNIEJSZYM niż kod odpowiedzi. Asercja na status pierwsza maskowałaby
        // najgorszy przypadek — 4xx dla operatora przy zleceniu już odłożonym.
        self::assertSame([], $this->spoolFiles(), 'Odrzucone żądanie nie może zostawić zlecenia.');
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Subdomena jest osobnym polem od kodu (#2904), więc ma osobną walidację —
     * i osobny test. Kształt niepoprawny to 422, nie 400: pole istnieje
     * i jest zrozumiałe, tylko jego wartość nie spełnia kontraktu.
     *
     * @return iterable<string, array{string}>
     */
    public static function hostileSubdomains(): iterable
    {
        yield 'podstawienie polecenia' => ['acme$(id)'];
        yield 'ukośnik' => ['acme/../platform'];
        yield 'nazwa zastrzeżona' => ['admin'];
        yield 'kropka — cudza domena' => ['acme.evil.example'];
        yield 'myślnik na końcu' => ['acme-'];
    }

    #[Test]
    #[DataProvider('hostileSubdomains')]
    public function aHostileSubdomainNeverReachesTheQueue(string $subdomain): void
    {
        $client = $this->clientFor(self::PLATFORM_OPERATOR_EMAIL);
        $client->request('POST', '/api/admin/tenants', ['json' => [
            'code' => 'legit',
            'name' => 'Legit',
            'owner_email' => 'owner@legit.pl',
            'subdomain' => $subdomain,
        ]]);

        self::assertSame([], $this->spoolFiles(), 'Odrzucone żądanie nie może zostawić zlecenia.');
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Nazwy zastrzeżone chronią przed przejęciem adresu, pod którym stoi coś
     * innego — `admin` to panel operatora, `pim` to stack współdzielony.
     */
    #[Test]
    public function reservedSubdomainsAreRefusedAcrossTheBoard(): void
    {
        foreach (['admin', 'platform', 'api', 'www', 'mail', 'pim'] as $reserved) {
            $client = $this->clientFor(self::PLATFORM_OPERATOR_EMAIL);
            $client->request('POST', '/api/admin/tenants', ['json' => [
                'code' => 'tenant-'.$reserved,
                'name' => 'Reserved',
                'owner_email' => 'owner@reserved.pl',
                'subdomain' => $reserved,
            ]]);

            self::assertResponseStatusCodeSame(422, \sprintf('Subdomena `%s` musi być odrzucona.', $reserved));
        }

        self::assertSame([], $this->spoolFiles());
    }

    /**
     * Kluczowy test bramki: 403 ma paść **przed** kolejką. Sam kod odpowiedzi
     * tego nie dowodzi — dowodzi tego pusty katalog zleceń.
     */
    #[Test]
    public function aTenantOwnerCannotPutAnythingIntoTheQueue(): void
    {
        $client = $this->clientFor(self::TENANT_OWNER_EMAIL);
        $client->request('POST', '/api/admin/tenants', ['json' => [
            'code' => 'sneaky',
            'name' => 'Sneaky',
            'owner_email' => 'owner@sneaky.pl',
        ]]);

        self::assertSame([], $this->spoolFiles(), 'Odmowa uprawnień musi wyprzedzać kolejkę.');
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function anAnonymousRequestCannotPutAnythingIntoTheQueue(): void
    {
        static::createClient()->request('POST', '/api/admin/tenants', ['json' => [
            'code' => 'anon',
            'name' => 'Anon',
            'owner_email' => 'owner@anon.pl',
        ]]);

        self::assertSame([], $this->spoolFiles());
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Pola spoza kontraktu są **ignorowane**, nie przepisywane do zlecenia.
     * Gdyby `project` przechodziło, żądanie mogłoby wskazać stack cudzego
     * klienta albo stack współdzielony — a provisioner wykonuje operacje
     * Compose'a właśnie na projekcie.
     */
    #[Test]
    public function anAttackerCannotNameTheProjectTheJobWillTouch(): void
    {
        $client = $this->clientFor(self::PLATFORM_OPERATOR_EMAIL);
        $client->request('POST', '/api/admin/tenants', ['json' => [
            'code' => 'newbie',
            'name' => 'Newbie',
            'owner_email' => 'owner@newbie.pl',
            'project' => 'pim-harmon',
            'action' => 'purge',
            'owner_password' => 'attacker-chosen-password',
        ]]);

        self::assertResponseStatusCodeSame(202);

        $job = $this->singleJob();
        self::assertSame('create', $job['action'], 'Akcję wybiera endpoint, nie ładunek.');
        self::assertSame('newbie', $job['code']);
        self::assertArrayNotHasKey('project', $job, 'Projekt wyprowadza provisioner z kodu — nigdy z żądania.');
        self::assertNotSame(
            'attacker-chosen-password',
            $job['owner_password'] ?? null,
            'Hasło tymczasowe losuje kolejka; wartość z żądania nie może go nadpisać.',
        );
    }

    /**
     * Identyfikator zlecenia wchodzi do ścieżki pliku. Bez sprawdzenia kształtu
     * `../` w parametrze czytałby dowolny plik widoczny dla procesu API.
     */
    #[Test]
    public function theProgressEndpointCannotBeUsedToReadArbitraryFiles(): void
    {
        $client = $this->clientFor(self::PLATFORM_OPERATOR_EMAIL);

        foreach (['../../../../etc/passwd', '..%2F..%2Fetc%2Fpasswd', 'nie-uuid'] as $evil) {
            $client->request('GET', '/api/admin/tenants/provisioning/'.$evil);

            $response = $client->getResponse();
            \assert(null !== $response);
            self::assertContains(
                $response->getStatusCode(),
                [404, 405],
                \sprintf('Identyfikator `%s` nie może trafić do budowania ścieżki.', $evil),
            );
        }
    }

    #[Test]
    public function aTenantOwnerCannotReadProvisioningProgress(): void
    {
        $client = $this->clientFor(self::TENANT_OWNER_EMAIL);
        $client->request('GET', '/api/admin/tenants/provisioning/11111111-1111-4111-8111-111111111111');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function singleJob(): array
    {
        $files = $this->spoolFiles('*.job.json');
        self::assertCount(1, $files);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode((string) file_get_contents($files[0]), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function spoolFiles(string $pattern = '*.job.json'): array
    {
        $found = glob($this->spool.'/'.$pattern);

        return false === $found ? [] : $found;
    }

    private function makeUser(Tenant $tenant, string $email, UserPasswordHasherInterface $hasher): User
    {
        $stub = new User($tenant, $email, '');

        return new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'));
    }

    private function clientFor(string $email): Client
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail($email);
        \assert(null !== $user);
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $client = static::createClient();
        $client->setDefaultOptions(['headers' => ['authorization' => 'Bearer '.$jwt]]);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
