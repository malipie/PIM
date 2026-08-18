<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Provisioning;

use App\Identity\Application\SuperAdmin\SuperAdminContext;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Domain\Tenant;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * TNT-P4-08 (#2909 / ADR-0036) — doprowadza rejestr tenantów do stanu, w
 * jakim naprawdę są ich instancje.
 *
 * Provisioner nie ma sieci (`network_mode: none`), więc nie zadzwoni do API
 * z wynikiem. Wynik zostawia plikiem, a ktoś musi go przeczytać i przełożyć
 * na wiersz rejestru. To robi ta klasa.
 *
 * **Dlaczego to jest osobny krok, a nie zapis w kontrolerze.** Zlecenie trwa
 * kilkadziesiąt sekund, a żądanie HTTP kończy się od razu. Gdyby kontroler
 * ustawiał stan docelowy z góry, panel pokazywałby „aktywny" dla instancji,
 * która wcale nie wstała — a to jest dokładnie to, czego zabrania kryterium
 * akceptacji #2909: `reactivate` wraca na `active` **dopiero po** weryfikacji,
 * że instancja odpowiada.
 *
 * Zamierzona asymetria: `suspend` i `delete` zmieniają rejestr od razu w
 * kontrolerze (odmowa dostępu ma obowiązywać natychmiast, nawet gdyby
 * zatrzymanie kontenerów się nie powiodło — fail-closed), a `create`
 * i `reactivate` czekają na potwierdzenie z instancji (fail-open byłby tu
 * kłamstwem wobec operatora).
 */
final readonly class ProvisioningReconciler
{
    public function __construct(
        private ProvisioningQueue $queue,
        private TenantRepositoryInterface $tenants,
        private SuperAdminContext $superAdminContext,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int liczba rozliczonych zleceń
     */
    public function reconcile(): int
    {
        $outcomes = $this->queue->pendingOutcomes();
        if ([] === $outcomes) {
            return 0;
        }

        // Rejestr obejmuje wszystkie tenanty, a filtr Doctrine domyślnie
        // zawęża do bieżącego. Bez trybu cross-tenant rozliczenie nie
        // znalazłoby ani jednego wiersza.
        $callerId = Uuid::v7();

        /** @var int<0, max> $count */
        $count = $this->superAdminContext->runCrossTenant(
            $callerId,
            function () use ($outcomes): int {
                $count = 0;
                foreach ($outcomes as $outcome) {
                    if ($this->apply($outcome)) {
                        ++$count;
                    }
                }

                return $count;
            },
        );

        return $count;
    }

    private function apply(ProvisioningOutcome $outcome): bool
    {
        try {
            $tenantId = Uuid::fromString($outcome->tenantId);
        } catch (InvalidArgumentException) {
            // Notatka właściciela jest nieczytelna — nie ma czego poprawiać,
            // a ponawianie w nieskończoność zaśmieciłoby logi.
            $this->queue->markReconciled($outcome->jobId);

            return false;
        }

        $tenant = $this->tenants->findById($tenantId);
        if (null === $tenant) {
            // Tenant zniknął z rejestru — typowo po `purge`. Zlecenie jest
            // rozliczone przez sam fakt, że nie ma czego aktualizować.
            $this->queue->markReconciled($outcome->jobId);

            return false;
        }

        $changed = match ($outcome->action) {
            'create' => $this->applyCreate($tenant, $outcome),
            'reactivate' => $this->applyReactivate($tenant, $outcome),
            default => $this->applyBookkeeping($tenant, $outcome),
        };

        if ($changed) {
            $this->tenants->save($tenant);
        }

        $this->queue->markReconciled($outcome->jobId);

        return true;
    }

    private function applyCreate(Tenant $tenant, ProvisioningOutcome $outcome): bool
    {
        if (!$tenant->isProvisioning() && !$tenant->hasFailedProvisioning()) {
            // Ktoś w międzyczasie ruszył tenanta ręcznie. Cudzej decyzji nie
            // nadpisujemy wynikiem starego zlecenia.
            return false;
        }

        if ($outcome->succeeded()) {
            $tenant->markProvisioned();

            return true;
        }

        $this->logger->error('Provisioning instancji tenanta nie powiódł się.', [
            'tenant_code' => $tenant->getCode(),
            'job_id' => $outcome->jobId,
            'state' => $outcome->state,
            'error' => $outcome->error,
        ]);
        $tenant->markProvisioningFailed();

        return true;
    }

    private function applyReactivate(Tenant $tenant, ProvisioningOutcome $outcome): bool
    {
        if (!$outcome->succeeded()) {
            // Instancja nie wstała. Tenant ZOSTAJE zawieszony — „aktywny"
            // przy martwej instancji jest gorsze niż widoczna porażka.
            $this->logger->error('Wznowienie instancji tenanta nie powiodło się — tenant zostaje zawieszony.', [
                'tenant_code' => $tenant->getCode(),
                'job_id' => $outcome->jobId,
                'state' => $outcome->state,
                'error' => $outcome->error,
            ]);

            return false;
        }

        if (!$tenant->isSuspended()) {
            return false;
        }

        $tenant->reactivate();

        return true;
    }

    /**
     * `suspend`, `delete`, `purge` — rejestr jest już poprawny, bo kontroler
     * zmienił go przy przyjęciu zlecenia. Zostaje odnotowanie rozjazdu, gdy
     * operacja na stacku padła: wiersz mówi wtedy co innego niż kontenery.
     */
    private function applyBookkeeping(Tenant $tenant, ProvisioningOutcome $outcome): bool
    {
        if (!$outcome->succeeded()) {
            $this->logger->error('Operacja cyklu życia instancji nie powiodła się — rejestr i stack mogą się rozjeżdżać.', [
                'tenant_code' => $tenant->getCode(),
                'action' => $outcome->action,
                'job_id' => $outcome->jobId,
                'state' => $outcome->state,
                'error' => $outcome->error,
            ]);
        }

        return false;
    }
}
