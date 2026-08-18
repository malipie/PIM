<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * TNT-P4-02 (#2903 / ADR-0036) — trasy panelu operatora istnieją wyłącznie
 * w instancji platformowej.
 *
 * Po ADR-0035 każdy klient ma własną instancję z własną bazą. Endpoint
 * `POST /api/admin/tenants` zapisuje do bazy, do której jest podłączony, więc
 * wywołany w instancji klienta założyłby **drugiego tenanta w jego bazie** —
 * dokładnie to, czemu cały epik ma zapobiegać.
 *
 * Uprawnienie `platform.*` (AUD-003) już to blokuje i zostaje pierwszą
 * bramką. Ten listener jest drugą: w instancji tenanta trasy panelu nie
 * istnieją w ogóle.
 *
 * **404, nie 403.** Odpowiedź „brak uprawnień" potwierdzałaby, że taka
 * funkcja tu jest i że warto próbować dalej. Brak trasy nie mówi nic.
 *
 * **Domyślną rolą jest `platform`, nie `tenant`** — i to jest świadome.
 * Istniejące wdrożenie jednoinstancyjne ma dziś panel operatora pod swoim
 * adresem; gdyby domyślną wartością był `tenant`, samo wdrożenie tej zmiany
 * odcięłoby operatora od panelu, zanim instancja platformowa w ogóle
 * powstanie. Szablon stacku tenanta (`docker-compose.tenant.yml`) ustawia
 * `tenant` jawnie, więc każda instancja klienta jest zamknięta z konstrukcji,
 * a nie z powodu wartości domyślnej.
 */
final readonly class PlatformOnlyRoutesListener implements EventSubscriberInterface
{
    public const string ROLE_PLATFORM = 'platform';
    public const string ROLE_TENANT = 'tenant';

    /**
     * Prefiksy tras dostępnych wyłącznie w instancji platformowej.
     *
     * Break-glass jest tu razem z zarządzaniem tenantami: to procedura
     * ratunkowa operatora platformy, a nie funkcja instancji klienta.
     *
     * @var list<string>
     */
    private const array PLATFORM_ONLY_PREFIXES = [
        '/api/admin/tenants',
        '/api/admin/break-glass',
    ];

    public function __construct(
        private string $instanceRole = self::ROLE_PLATFORM,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priorytet wyższy niż firewall (8): odmowa nie powinna zależeć od
        // tego, czy żądanie niesie poprawny token. Trasa albo tu jest, albo
        // jej nie ma.
        return [KernelEvents::REQUEST => ['onRequest', 32]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (self::ROLE_PLATFORM === $this->instanceRole) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        foreach (self::PLATFORM_ONLY_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                throw new NotFoundHttpException();
            }
        }
    }
}
