<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Provisioning;

use App\Shared\Domain\Tenant;
use App\Shared\Domain\TenantSubdomain;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * TNT-P4-05 (#2906 / ADR-0036) — kolejka zleceń provisioningu.
 *
 * Platforma zapisuje zlecenie plikiem, provisioner (#2905) je przejmuje.
 * Kolejka plikowa zamiast HTTP jest wyborem bezpieczeństwa: komponent
 * z uprawnieniami roota na hoście nie musi wtedy nasłuchiwać na żadnym porcie.
 *
 * API **nie wykonuje** provisioningu i nie dotyka Dockera — tylko odkłada
 * zlecenie i czyta status.
 */
final readonly class ProvisioningQueue
{
    public function __construct(
        private string $spoolDir,
    ) {
    }

    public function isAvailable(): bool
    {
        return '' !== $this->spoolDir && is_dir($this->spoolDir) && is_writable($this->spoolDir);
    }

    /**
     * Odkłada zlecenie utworzenia instancji i zwraca jego identyfikator.
     *
     * Hasło właściciela jest losowane tutaj i **nikt go nie ogląda** — ani
     * operator, ani klient. Właściciel dostaje dostęp zaproszeniem wysłanym
     * przez swoją instancję (`pim:tenant:invite-owner`), więc hasło tymczasowe
     * jest wyłącznie sposobem na przejście bootstrapu.
     */
    public function enqueueCreate(
        Tenant $tenant,
        TenantSubdomain $subdomain,
        string $ownerEmail,
        Uuid $requestedBy,
    ): string {
        return $this->write([
            'action' => 'create',
            'code' => $tenant->getCode(),
            'subdomain' => $subdomain->value,
            'name' => $tenant->getName(),
            'owner_email' => $ownerEmail,
            'owner_password' => bin2hex(random_bytes(16)),
            'invite_owner' => true,
            'requested_by' => $requestedBy->toRfc4122(),
        ]);
    }

    public function enqueueLifecycle(Tenant $tenant, string $action, Uuid $requestedBy): string
    {
        if (!\in_array($action, ['suspend', 'reactivate', 'delete'], true)) {
            throw new RuntimeException(\sprintf('Nieobsługiwana akcja cyklu życia: %s', $action));
        }

        return $this->write([
            'action' => $action,
            'code' => $tenant->getCode(),
            'requested_by' => $requestedBy->toRfc4122(),
        ]);
    }

    /**
     * @return array<string, mixed>|null null, gdy provisioner jeszcze nie
     *                                   zaczął albo plik statusu zniknął
     */
    public function status(string $jobId): ?array
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $jobId)) {
            // Identyfikator wchodzi do ścieżki pliku. Kształt sprawdzany jest
            // zanim cokolwiek zostanie sklejone — bez tego `../` w parametrze
            // czytałby dowolny plik.
            return null;
        }

        $path = $this->spoolDir.'/'.$jobId.'.status.json';
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (false === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return null;
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function write(array $job): string
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException(\sprintf(
                'Katalog kolejki provisioningu (%s) jest niedostępny — instancja platformowa nie może zlecić zadania.',
                $this->spoolDir,
            ));
        }

        $jobId = Uuid::v4()->toRfc4122();
        $payload = json_encode($job + ['job_id' => $jobId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        // Zapis atomowy: provisioner skanuje katalog równolegle, a plik
        // częściowo zapisany zostałby przejęty jako zlecenie nieczytelne.
        $tmp = \sprintf('%s/.%s.tmp', $this->spoolDir, $jobId);
        $final = \sprintf('%s/%s.job.json', $this->spoolDir, $jobId);

        if (false === file_put_contents($tmp, $payload)) {
            throw new RuntimeException('Nie udało się zapisać zlecenia provisioningu.');
        }
        if (!rename($tmp, $final)) {
            @unlink($tmp);
            throw new RuntimeException('Nie udało się odłożyć zlecenia provisioningu do kolejki.');
        }

        return $jobId;
    }
}
