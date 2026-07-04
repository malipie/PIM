<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * GOLIVE #2179 — allow a role-scoped cache directory via APP_CACHE_DIR.
     *
     * The api (APP_DEBUG=1) and the worker (APP_DEBUG=0) share the /app/var
     * volume but compile DIFFERENT DI containers into the SAME var/cache/dev.
     * The worker's boot-time `cache:clear` (needed because non-debug skips
     * container-freshness checks) therefore wiped the debug container out
     * from under the serving FrankenPHP api — every worker restart risked
     * the "HTTP 200 + HTML Fatal getXxxService.php: No such file" corruption
     * (README → Troubleshooting). docker-compose points the worker at
     * /app/var/cache-worker, so each role clears only its own tree.
     *
     * Unset (api, CI, prod, tests) → stock behaviour.
     */
    public function getCacheDir(): string
    {
        $dirRaw = $_SERVER['APP_CACHE_DIR'] ?? $_ENV['APP_CACHE_DIR'] ?? null;
        $dir = \is_string($dirRaw) ? $dirRaw : '';

        return '' !== $dir ? $dir.'/'.$this->environment : parent::getCacheDir();
    }
}
