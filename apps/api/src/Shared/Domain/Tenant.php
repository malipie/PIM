<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exception\CannotDisablePrimaryLocaleException;
use App\Shared\Domain\Exception\InvalidLocaleException;
use App\Shared\Domain\Exception\LocaleNotEnabledException;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Tenant aggregate — the single shared kernel of multi-tenant PIM.
 *
 * Lives in Shared/ because every business bounded context (Catalog, Channel,
 * Asset, Integration) carries a `tenant_id` foreign key on its aggregates.
 * Identity owns User/Role/Permission/RefreshToken but no longer owns Tenant
 * itself — those belong to the universal isolation boundary.
 *
 * Mapping kept in XML at Shared/Infrastructure/Doctrine/Orm/Mapping/Tenant.orm.xml
 * so the Domain class stays framework-agnostic.
 */
class Tenant
{
    public const string PLAN_STARTER = 'starter';
    public const string PLAN_PRO = 'pro';
    public const string PLAN_ENTERPRISE = 'enterprise';

    /**
     * RBAC-P5-021 (#711) — tenant lifecycle status.
     *
     *   - `active`    default; logins + scheduled tasks proceed
     *   - `suspended` auth refuses, scheduled tasks (imports/exports/syncs)
     *                 refuse to run. Reversible via reactivate().
     *   - `deleted`   soft-deleted; `deleted_at` carries the recovery clock.
     *                 30 days later `pim:tenants:purge-deleted` hard-deletes.
     */
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_SUSPENDED = 'suspended';
    public const string STATUS_DELETED = 'deleted';

    /**
     * TNT-P4-03 (#2904) — stany cyklu życia INSTANCJI, nie danych.
     *
     * Od ADR-0035 tenant to osobna instancja, która przez chwilę powstaje
     * i może nie powstać wcale. Bez tych stanów panel operatora mógłby pokazać
     * wyłącznie „jest" albo „nie ma", a zakładanie klienta trwa kilkadziesiąt
     * sekund i bywa przerywane.
     *
     *   - `pending`      wpis w rejestrze jest, zlecenie jeszcze nie ruszyło;
     *   - `provisioning` zlecenie w toku (kroki raportuje skrypt #2861);
     *   - `failed`       zlecenie przerwane; instancja może być w stanie
     *                    częściowym, ponowienie jest bezpieczne (#2911).
     *
     * Żaden z nich NIE oznacza instancji zdatnej do pracy — `isActive()`
     * pozostaje jedynym testem na to.
     */
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PROVISIONING = 'provisioning';
    public const string STATUS_FAILED = 'failed';

    private Uuid $id;
    private string $code;
    private string $name;
    private ?string $domain;
    private string $plan;
    private string $status = self::STATUS_ACTIVE;
    private ?DateTimeImmutable $suspendedAt = null;
    private ?DateTimeImmutable $deletedAt = null;
    private DateTimeImmutable $createdAt;

    /**
     * Per-workspace list of enabled locales — drives the LocaleTabsField in
     * the modeling UI (VIEW-01) and any future multilingual surface. Default
     * `['pl', 'en']` matches the seeded demo workspace and the schema column
     * default. The CHECK constraint on `tenants` guarantees `primaryLocale`
     * is always in this list.
     *
     * @deprecated since the Locales feature (#869–#878); use the
     *     `tenant_locales` table instead. This array stays as a read-only
     *     legacy projection until LOC-07 (#875) migrates `/settings/tenant`
     *     to read from `tenant_locales`. LOC-02 (#870) backfilled
     *     `tenant_locales` from this column; production data in the legacy
     *     column stays consistent until removal in a follow-up ticket.
     *
     * @var list<string>
     */
    private array $enabledLocales = ['pl', 'en'];

    /**
     * @deprecated since the Locales feature (#869–#878); use the
     *     `tenant_locales.is_default = true` row instead.
     */
    private string $primaryLocale = 'pl';

    /**
     * IMP2-2.7 (#1483) — per-tenant import guardrails. `null` = fall back to the
     * application default (D10: 200k rows / 100 MB). Set by an operator (admin
     * UI / SQL); enforced in StartImportController + ParsePreviewController with
     * RFC 7807. Kept on the Tenant (not a config file) so each tenant can be
     * tuned independently in the multi-tenant target.
     */
    private ?int $importMaxRows = null;

    /** IMP2-2.7 (#1483) — per-tenant max upload size in bytes; null = app default. */
    private ?int $importMaxFileSize = null;

    public function __construct(
        string $code,
        string $name,
        ?Uuid $id = null,
        ?string $domain = null,
        string $plan = self::PLAN_STARTER,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->code = $code;
        $this->name = $name;
        $this->domain = $domain;
        $this->plan = $plan;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function changeDomain(?string $domain): void
    {
        $this->domain = $domain;
    }

    public function getPlan(): string
    {
        return $this->plan;
    }

    public function changePlan(string $plan): void
    {
        $this->plan = $plan;
    }

    /**
     * AUD-050 (W2-11) — "free tier" for retention purposes is the lowest plan
     * (`starter`). Free-tier exports carry full data / PII and are erased past
     * the retention window (GDPR / RODO); paid tiers (`pro` / `enterprise`)
     * keep exports forever (PRD §11.7). New paid plans are retained-forever by
     * default — only `starter` opts into the cleanup sweep.
     */
    public function isFreeTier(): bool
    {
        return self::PLAN_STARTER === $this->plan;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status && null === $this->deletedAt;
    }

    public function isSuspended(): bool
    {
        return self::STATUS_SUSPENDED === $this->status;
    }

    public function isDeleted(): bool
    {
        return self::STATUS_DELETED === $this->status || null !== $this->deletedAt;
    }

    public function getSuspendedAt(): ?DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /**
     * Suspends the tenant. Idempotent: re-suspending does NOT bump
     * `suspendedAt`, the timestamp stays at the original suspension so
     * the audit chain is clean.
     */
    public function suspend(?DateTimeImmutable $when = null): void
    {
        if ($this->isSuspended()) {
            return;
        }
        $this->status = self::STATUS_SUSPENDED;
        $this->suspendedAt = $when ?? new DateTimeImmutable();
    }

    /**
     * Zlecenie provisioningu ruszyło. Wołane przez platformę przy przyjęciu
     * zadania, zanim provisioner cokolwiek uruchomi.
     */
    public function markProvisioning(): void
    {
        $this->status = self::STATUS_PROVISIONING;
    }

    /**
     * Instancja powstała i przeszła smoke test. Dopiero tutaj tenant staje
     * się aktywny — „kontenery wstały" to za mało, bo instancja, do której
     * nikt się nie zaloguje, nie jest gotowa (kontrakt skryptu z #2861).
     */
    public function markProvisioned(): void
    {
        $this->status = self::STATUS_ACTIVE;
        $this->suspendedAt = null;
    }

    /**
     * Provisioning przerwany. Stan celowo NIE jest `deleted`: instancja może
     * być częściowo utworzona, a ponowienie zlecenia jest normalną ścieżką,
     * nie wyjątkiem.
     */
    public function markProvisioningFailed(): void
    {
        $this->status = self::STATUS_FAILED;
    }

    public function isProvisioning(): bool
    {
        return \in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROVISIONING], true);
    }

    public function hasFailedProvisioning(): bool
    {
        return self::STATUS_FAILED === $this->status;
    }

    public function reactivate(): void
    {
        if (!$this->isSuspended()) {
            return;
        }
        $this->status = self::STATUS_ACTIVE;
        $this->suspendedAt = null;
    }

    /**
     * Soft-delete with a 30-day recovery window. The hard delete is
     * driven by a separate scheduled command which inspects
     * `deletedAt < NOW() - INTERVAL '30 days'`.
     */
    public function softDelete(?DateTimeImmutable $when = null): void
    {
        $this->status = self::STATUS_DELETED;
        $this->deletedAt = $when ?? new DateTimeImmutable();
    }

    /**
     * @return list<string>
     */
    public function getEnabledLocales(): array
    {
        return $this->enabledLocales;
    }

    public function getPrimaryLocale(): string
    {
        return $this->primaryLocale;
    }

    public function getImportMaxRows(): ?int
    {
        return $this->importMaxRows;
    }

    public function setImportMaxRows(?int $importMaxRows): void
    {
        $this->importMaxRows = $importMaxRows;
    }

    public function getImportMaxFileSize(): ?int
    {
        return $this->importMaxFileSize;
    }

    public function setImportMaxFileSize(?int $importMaxFileSize): void
    {
        $this->importMaxFileSize = $importMaxFileSize;
    }

    public function isLocaleEnabled(string $locale): bool
    {
        return \in_array($locale, $this->enabledLocales, true);
    }

    /**
     * Adds a locale to the enabled set. Idempotent — a duplicate add is a
     * no-op so the FE LocaleAddDialog can fire-and-forget without checking
     * server state first. Validates against `LocaleLibrary::CODES` to keep
     * the column from collecting typos.
     */
    public function enableLocale(string $locale): void
    {
        if (!LocaleLibrary::isSupported($locale)) {
            throw new InvalidLocaleException($locale);
        }
        if ($this->isLocaleEnabled($locale)) {
            return;
        }
        $this->enabledLocales[] = $locale;
    }

    /**
     * Removes a locale from the enabled set. Refuses to remove the primary —
     * the operator must change the primary first. Idempotent on already-disabled.
     */
    public function disableLocale(string $locale): void
    {
        if ($locale === $this->primaryLocale) {
            throw new CannotDisablePrimaryLocaleException($locale);
        }
        $this->enabledLocales = array_values(array_filter(
            $this->enabledLocales,
            static fn (string $l): bool => $l !== $locale,
        ));
    }

    public function changePrimaryLocale(string $locale): void
    {
        if (!$this->isLocaleEnabled($locale)) {
            throw new LocaleNotEnabledException($locale);
        }
        $this->primaryLocale = $locale;
    }
}
