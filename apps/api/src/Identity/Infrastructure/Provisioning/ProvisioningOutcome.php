<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Provisioning;

/**
 * TNT-P4-08 (#2909) — jak skończyło się jedno zlecenie provisioningu.
 *
 * Sklejka dwóch źródeł, z których żadne samo nie wystarcza: notatki
 * właściciela pisanej przez API (który wiersz rejestru, jaka akcja) i pliku
 * statusu pisanego przez provisionera (czy się udało).
 */
final readonly class ProvisioningOutcome
{
    public function __construct(
        public string $jobId,
        public string $tenantId,
        public string $action,
        public string $state,
        public ?string $error = null,
    ) {
    }

    public function succeeded(): bool
    {
        return 'done' === $this->state;
    }
}
