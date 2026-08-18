<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Provisioning;

use App\Shared\Domain\Tenant;
use App\Shared\Domain\TenantSubdomain;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

use const DATE_ATOM;
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
    /**
     * Akcje cyklu życia, które panel może zlecić provisionerowi.
     *
     * `delete` i `purge` są celowo rozdzielone. `delete` to reakcja na
     * skasowanie tenanta w panelu: zrzut końcowy i wygaszenie instancji, bez
     * niszczenia czegokolwiek — przez 30 dni klient jest do odzyskania.
     * `purge` niszczy stack i wolumeny i zleca go WYŁĄCZNIE
     * `pim:tenants:purge-deleted` po wygaśnięciu tego okna (#2909).
     *
     * Lista MUSI zgadzać się ze zbiorem `ACTIONS` w docker/provisioner/provisioner.py.
     *
     * @var list<string>
     */
    public const array LIFECYCLE_ACTIONS = ['suspend', 'reactivate', 'delete', 'purge'];

    /** Stany, po których zlecenie już się nie zmieni. */
    private const array TERMINAL_STATES = ['done', 'failed', 'rejected'];

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
        return $this->write($tenant, [
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
        if (!\in_array($action, self::LIFECYCLE_ACTIONS, true)) {
            throw new RuntimeException(\sprintf('Nieobsługiwana akcja cyklu życia: %s', $action));
        }

        return $this->write($tenant, [
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

        // Przepisanie zamiast adnotacji: plik statusu pisze inny proces, wiec
        // ksztalt jest zewnetrzny i lepiej go zawezic w kodzie niz obiecac
        // komentarzem. Klucze nietekstowe sa odrzucane.
        $status = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key)) {
                $status[$key] = $value;
            }
        }

        return $status;
    }

    /**
     * Zlecenia gotowe do rozliczenia w rejestrze: terminalne i jeszcze nie
     * rozliczone.
     *
     * Zestawiane są DWA pliki. `*.owner.json` pisze API przy zlecaniu i tylko
     * ono wie, którego wiersza rejestru dotyczy zadanie; `*.status.json` pisze
     * provisioner i tylko on wie, jak się skończyło. Mapowanie po stronie API
     * jest konieczne, bo zlecenie **odrzucone** nie ma w statusie ani kodu, ani
     * akcji — bez tego tenant zostawałby w stanie `provisioning` na zawsze.
     *
     * @return list<ProvisioningOutcome>
     */
    public function pendingOutcomes(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $outcomes = [];
        $ownerPaths = glob($this->spoolDir.'/*.owner.json');
        foreach (false === $ownerPaths ? [] : $ownerPaths as $ownerPath) {
            $jobId = basename($ownerPath, '.owner.json');
            if (1 !== preg_match('/^[0-9a-f-]{36}$/', $jobId)) {
                continue;
            }
            if (is_file($this->reconciledMarker($jobId))) {
                continue;
            }

            $owner = $this->readJson($ownerPath);
            $status = $this->status($jobId);
            if (null === $owner || null === $status) {
                continue;
            }

            $state = $status['state'] ?? null;
            if (!\is_string($state) || !\in_array($state, self::TERMINAL_STATES, true)) {
                continue;
            }

            $tenantId = $owner['tenant_id'] ?? null;
            $action = $owner['action'] ?? null;
            if (!\is_string($tenantId) || !\is_string($action)) {
                continue;
            }

            $error = $status['error'] ?? null;
            $outcomes[] = new ProvisioningOutcome(
                jobId: $jobId,
                tenantId: $tenantId,
                action: $action,
                state: $state,
                error: \is_string($error) ? $error : null,
            );
        }

        return $outcomes;
    }

    /**
     * Oznacza zlecenie jako rozliczone. Znacznik jest osobnym plikiem, a nie
     * skasowaniem statusu, bo panel czyta status jeszcze długo po tym, jak
     * rejestr został już poprawiony.
     */
    public function markReconciled(string $jobId): void
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $jobId) || !$this->isAvailable()) {
            return;
        }

        @file_put_contents($this->reconciledMarker($jobId), new DateTimeImmutable()->format(DATE_ATOM));
    }

    private function reconciledMarker(string $jobId): string
    {
        return $this->spoolDir.'/'.$jobId.'.reconciled';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (false === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return null;
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function write(Tenant $tenant, array $job): string
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
        // Notatka właściciela POWSTAJE PRZED wrzuceniem zlecenia do kolejki.
        // Odwrotna kolejność zostawia okno, w którym provisioner zdążył już
        // zlecenie wykonać, a rejestr nie wie, czyje ono było.
        $ownerPayload = json_encode([
            'job_id' => $jobId,
            'tenant_id' => $tenant->getId()->toRfc4122(),
            'code' => $tenant->getCode(),
            'action' => $job['action'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (false === file_put_contents(\sprintf('%s/%s.owner.json', $this->spoolDir, $jobId), $ownerPayload)) {
            @unlink($tmp);
            throw new RuntimeException('Nie udało się zapisać notatki właściciela zlecenia.');
        }

        if (!rename($tmp, $final)) {
            @unlink($tmp);
            throw new RuntimeException('Nie udało się odłożyć zlecenia provisioningu do kolejki.');
        }

        return $jobId;
    }
}
