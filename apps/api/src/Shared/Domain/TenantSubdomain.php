<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use InvalidArgumentException;

/**
 * Subdomena, pod którą działa instancja tenanta (epik TNT, #2904).
 *
 * Od ADR-0035 subdomena nie jest ozdobnikiem — adresuje konkretną instancję,
 * a jej nazwa wchodzi do nazw kontenerów (`pim-<kod>-api`), stanzy pgBackRest
 * i konfiguracji edge proxy. Reguły muszą więc być identyczne po stronie API
 * i skryptów operacyjnych; rozjazd oznaczałby, że panel przyjmuje nazwę,
 * której infrastruktura nie obsłuży.
 *
 * Kontrakt jest celowo węższy niż RFC 1123: bez kropek (subdomena jest
 * pojedynczą etykietą), bez wielkich liter (DNS jest case-insensitive, ale
 * nazwy kontenerów i wiaderek już nie).
 */
final readonly class TenantSubdomain
{
    /**
     * Pojedyncza etykieta DNS: zaczyna się i kończy znakiem alfanumerycznym,
     * w środku dopuszcza myślnik. Długość 3–32 znaki.
     */
    public const string PATTERN = '/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/';

    /**
     * Nazwy kolidujące z hostami platformy albo usługami współdzielonymi.
     *
     * Lista MUSI pozostać zgodna z `RESERVED_SUBDOMAINS` w
     * `scripts/pim-tenant-env.sh` — to ten sam kontrakt po dwóch stronach.
     *
     * @var list<string>
     */
    public const array RESERVED = [
        'admin', 'api', 'app', 'docs', 'mail', 'meili', 'mercure', 'minio',
        'pim', 'platform', 'staging', 'status', 'test', 'www',
    ];

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidArgumentException gdy nazwa nie spełnia kontraktu
     */
    public static function fromString(string $raw): self
    {
        $value = strtolower(trim($raw));

        if ('' === $value) {
            throw new InvalidArgumentException('Subdomena nie może być pusta.');
        }

        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new InvalidArgumentException(
                'Subdomena musi mieć 3–32 znaki, składać się z małych liter, cyfr i myślników, '
                .'oraz zaczynać i kończyć się znakiem alfanumerycznym.'
            );
        }

        if (self::isReserved($value)) {
            throw new InvalidArgumentException(
                \sprintf('Subdomena "%s" jest zastrzeżona dla platformy lub usługi współdzielonej.', $value)
            );
        }

        return new self($value);
    }

    public static function isValid(string $raw): bool
    {
        try {
            self::fromString($raw);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function isReserved(string $value): bool
    {
        return \in_array(strtolower(trim($value)), self::RESERVED, true);
    }

    /**
     * Pełny adres instancji dla podanej domeny bazowej.
     */
    public function hostname(string $baseDomain): string
    {
        return $this->value.'.'.ltrim(trim($baseDomain), '.');
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
