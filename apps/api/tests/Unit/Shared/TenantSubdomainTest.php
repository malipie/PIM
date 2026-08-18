<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Domain\TenantSubdomain;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TNT-P4-03 (#2904) — kontrakt subdomeny instancji tenanta.
 *
 * Reguły muszą być identyczne po stronie API i skryptów operacyjnych
 * (`scripts/pim-tenant-env.sh`); rozjazd oznaczałby, że panel przyjmuje
 * nazwę, której infrastruktura nie obsłuży.
 */
final class TenantSubdomainTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function acceptedProvider(): iterable
    {
        yield 'zwykła nazwa' => ['acme', 'acme'];
        yield 'z myślnikiem' => ['acme-pl', 'acme-pl'];
        yield 'z cyframi' => ['acme2026', 'acme2026'];
        yield 'wielkie litery sprowadzone do małych' => ['ACME', 'acme'];
        yield 'białe znaki obcięte' => ['  acme  ', 'acme'];
        yield 'minimalna długość' => ['abc', 'abc'];
    }

    #[Test]
    #[DataProvider('acceptedProvider')]
    public function acceptsValidLabels(string $input, string $expected): void
    {
        self::assertSame($expected, TenantSubdomain::fromString($input)->value);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedProvider(): iterable
    {
        yield 'pusta' => [''];
        yield 'za krótka' => ['ab'];
        yield 'za długa' => [str_repeat('a', 33)];
        yield 'podkreślenie' => ['acme_pl'];
        yield 'kropka rozbijałaby etykietę' => ['acme.pl'];
        yield 'myślnik na początku' => ['-acme'];
        yield 'myślnik na końcu' => ['acme-'];
        yield 'spacja w środku' => ['acme pl'];
        yield 'znak spoza ASCII' => ['ącme'];
    }

    #[Test]
    #[DataProvider('rejectedProvider')]
    public function rejectsInvalidLabels(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantSubdomain::fromString($input);
    }

    /** @return iterable<string, array{string}> */
    public static function reservedProvider(): iterable
    {
        yield 'host panelu platformy' => ['admin'];
        yield 'domena bazowa instancji' => ['app'];
        yield 'dokumentacja' => ['docs'];
        yield 'usługa współdzielona' => ['minio'];
        yield 'zastrzeżone mimo wielkich liter' => ['ADMIN'];
    }

    #[Test]
    #[DataProvider('reservedProvider')]
    public function rejectsReservedLabels(string $input): void
    {
        self::assertTrue(TenantSubdomain::isReserved($input));

        $this->expectException(InvalidArgumentException::class);
        TenantSubdomain::fromString($input);
    }

    #[Test]
    public function buildsHostnameFromBaseDomain(): void
    {
        $subdomain = TenantSubdomain::fromString('acme');

        self::assertSame('acme.app.harmonpim.pl', $subdomain->hostname('app.harmonpim.pl'));
        self::assertSame('acme.app.harmonpim.pl', $subdomain->hostname('.app.harmonpim.pl'));
    }

    #[Test]
    public function isValidMirrorsFromString(): void
    {
        self::assertTrue(TenantSubdomain::isValid('acme'));
        self::assertFalse(TenantSubdomain::isValid('admin'));
        self::assertFalse(TenantSubdomain::isValid('a'));
    }

    /**
     * Lista zastrzeżonych nazw żyje w dwóch miejscach z konieczności: PHP
     * waliduje żądania z panelu, a skrypt provisioningu bywa uruchamiany
     * bez API. Ten test pilnuje, żeby nie rozjechały się po cichu.
     */
    #[Test]
    public function reservedListMatchesTheProvisioningScript(): void
    {
        // Katalog `scripts/` leży w korzeniu repozytorium, a testy bywają
        // uruchamiane zarówno stamtąd, jak i z kontenera, do którego
        // podmontowane jest wyłącznie `apps/api`. Szukamy w obu miejscach;
        // gdy pliku nie widać, test jest POMIJANY z wyraźnym powodem —
        // milcząca zieleń byłaby tu gorsza niż brak testu.
        $candidates = [
            __DIR__.'/../../../../../scripts/pim-tenant-env.sh',
            __DIR__.'/../../../../scripts/pim-tenant-env.sh',
        ];

        $script = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $script = file_get_contents($candidate);
                break;
            }
        }

        if (!\is_string($script)) {
            self::markTestSkipped(
                'scripts/pim-tenant-env.sh poza zasięgiem (uruchomienie z kontenera montującego tylko apps/api). '
                .'Kontrakt weryfikuje CI, gdzie dostępne jest całe repozytorium.'
            );
        }

        self::assertSame(
            1,
            preg_match('/RESERVED_SUBDOMAINS="([^"]+)"/', $script, $matches),
            'Skrypt nie deklaruje RESERVED_SUBDOMAINS — kontrakt przestał być wspólny.'
        );

        $raw = $matches[1] ?? '';
        self::assertNotSame('', $raw, 'Regex nie wyłuskał listy nazw zastrzeżonych.');

        $fromScript = preg_split('/\s+/', trim($raw));
        self::assertIsArray($fromScript);
        sort($fromScript);
        $fromPhp = TenantSubdomain::RESERVED;
        sort($fromPhp);

        self::assertSame(
            $fromPhp,
            $fromScript,
            'Lista nazw zastrzeżonych w PHP i w scripts/pim-tenant-env.sh rozjechała się.'
        );
    }
}
