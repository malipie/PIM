<?php

declare(strict_types=1);

namespace App\Identity\Application\SuperAdmin;

/**
 * #2876 — the Postgres half of cross-tenant mode.
 *
 * Tenant isolation has two layers: the Doctrine filter, which
 * {@see SuperAdminContext} can switch off on its own, and Postgres FORCE
 * ROW LEVEL SECURITY, which cannot be switched off from PHP — it reads the
 * `app.is_super_admin` session variable. This port is how the application
 * layer asks for the second one without reaching into infrastructure, and
 * it keeps the pair mockable: the implementing listener is final, so a test
 * cannot stub the concrete class.
 *
 * Implementations must be symmetric. A caller that enables the bypass and
 * fails to disable it leaves the rest of the request able to write any
 * tenant's rows.
 */
interface RlsBypass
{
    public function enableSuperAdminBypass(): void;

    public function disableSuperAdminBypass(): void;
}
