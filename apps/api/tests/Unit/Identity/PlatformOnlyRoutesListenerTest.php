<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Infrastructure\Http\PlatformOnlyRoutesListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * TNT-P4-02 (#2903 / ADR-0036) — panel operatora istnieje wyłącznie
 * w instancji platformowej.
 */
final class PlatformOnlyRoutesListenerTest extends TestCase
{
    private function event(string $path): RequestEvent
    {
        return new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function platformOnlyPaths(): iterable
    {
        yield 'lista tenantów' => ['/api/admin/tenants'];
        yield 'pojedynczy tenant' => ['/api/admin/tenants/019f7ba4-af2d-75af-8d59-67505832ff05'];
        yield 'zawieszenie tenanta' => ['/api/admin/tenants/abc/suspend'];
        yield 'break-glass' => ['/api/admin/break-glass/rescue'];
    }

    #[Test]
    #[DataProvider('platformOnlyPaths')]
    public function tenantInstanceHidesPlatformRoutes(string $path): void
    {
        $listener = new PlatformOnlyRoutesListener(PlatformOnlyRoutesListener::ROLE_TENANT);

        // 404, nie 403: odpowiedź „brak uprawnień" potwierdzałaby, że funkcja
        // tu jest i że warto próbować dalej.
        $this->expectException(NotFoundHttpException::class);
        $listener->onRequest($this->event($path));
    }

    #[Test]
    #[DataProvider('platformOnlyPaths')]
    public function platformInstanceServesPlatformRoutes(string $path): void
    {
        $listener = new PlatformOnlyRoutesListener(PlatformOnlyRoutesListener::ROLE_PLATFORM);

        $listener->onRequest($this->event($path));
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function untouchedPaths(): iterable
    {
        yield 'logowanie' => ['/api/auth/login'];
        yield 'katalog' => ['/api/objects'];
        yield 'ustawienia tenanta' => ['/api/workspaces/current'];
        yield 'użytkownicy tenanta' => ['/api/users'];
        // Prefiks jest sprawdzany od początku ścieżki, więc trasa, która
        // jedynie ZAWIERA nazwę panelu, nie może zostać ukryta.
        yield 'ścieżka zawierająca nazwę panelu' => ['/api/objects?q=/api/admin/tenants'];
    }

    #[Test]
    #[DataProvider('untouchedPaths')]
    public function tenantInstanceLeavesEverythingElseAlone(string $path): void
    {
        $listener = new PlatformOnlyRoutesListener(PlatformOnlyRoutesListener::ROLE_TENANT);

        $listener->onRequest($this->event($path));
        $this->addToAssertionCount(1);
    }

    /**
     * Wartość domyślna zachowuje zachowanie istniejącego wdrożenia
     * jednoinstancyjnego. Gdyby domyślną rolą był `tenant`, samo wdrożenie
     * tej zmiany odcięłoby operatora od panelu, zanim instancja platformowa
     * w ogóle powstanie.
     */
    #[Test]
    public function defaultRoleKeepsThePanelReachable(): void
    {
        $listener = new PlatformOnlyRoutesListener();

        $listener->onRequest($this->event('/api/admin/tenants'));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function subRequestsAreIgnored(): void
    {
        $listener = new PlatformOnlyRoutesListener(PlatformOnlyRoutesListener::ROLE_TENANT);

        $event = new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('/api/admin/tenants'),
            HttpKernelInterface::SUB_REQUEST,
        );

        $listener->onRequest($event);
        $this->addToAssertionCount(1);
    }
}
