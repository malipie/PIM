<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security\Prd;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Domain\Entity\User;
use App\Shared\Domain\Tenant;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * #2881 — PRD §3.2 authorization for the API Platform resources whose
 * only voter was the legacy grid.
 *
 * The #[RequiresPermission] sweep in #2885 covered custom controllers.
 * API Platform resources gate through `security="is_granted('READ',
 * object)"` instead, and a separate inventory found eleven of them
 * answered only to legacy codes: every channel, import-profile,
 * import-schedule, import-source, connection, endpoint, mapping, sync,
 * webhook-delivery and api-key screen returned 403 to every role a
 * tenant can create through the panel — regardless of which PRD codes
 * that role held. It was found by watching the browser's network tab as
 * a PRD role, not by reading code, which is the point: two inventories
 * that look complete can still miss each other's surface.
 *
 * One class with a table rather than eleven near-identical siblings.
 * The existing per-entity `Prd\*Voter` classes each carry a distinct
 * rule — a kind discriminator, a scope, a secrets split — and earn their
 * file. These eleven do not: they are the same "resource → codes"
 * statement repeated, and a table is easier to audit against the PRD
 * matrix than eleven docblocks.
 *
 * Subject classes are named as strings, so Deptrac keeps
 * `Identity_Internals` out of the other contexts' Domain layers without
 * a single `skip_violations` entry.
 *
 * @extends Voter<string, object|string>
 */
final class EntityResourceVoter extends Voter
{
    private const string READ = 'READ';
    private const string WRITE = 'WRITE';

    /**
     * Subject FQCN → [READ codes, WRITE codes]. Any one code grants.
     *
     * The mapping follows the surface's own screen in the admin
     * (`ROUTE_PERMISSIONS` / `MENU_PERMISSIONS`), the same rule the
     * controller sweep used:
     *
     *   - channels are the Publications surface,
     *   - import profiles / schedules / sources are the Imports surface,
     *     where reading is `view_own`-or-`view_all` and touching anything
     *     means being allowed to run an import,
     *   - connections, endpoints, mappings, syncs, webhook deliveries and
     *     API keys are the integrations configurator, which has exactly
     *     one PRD code.
     *
     * @var array<string, array{0: list<string>, 1: list<string>}>
     */
    private const array RESOURCES = [
        // Channels. PRD has no separate "delete a channel" code, so
        // removing one follows the same grant as publishing to it —
        // the closest honest match in the matrix rather than an invented
        // code.
        'App\\Channel\\Domain\\Entity\\Channel' => [
            ['publications.view'],
            ['publications.publish_unpublish'],
        ],

        'App\\Import\\Domain\\Entity\\ImportProfile' => [
            ['imports.view_own', 'imports.view_all'],
            ['imports.run'],
        ],
        'App\\Import\\Domain\\Entity\\ImportSchedule' => [
            ['imports.view_own', 'imports.view_all'],
            ['imports.run'],
        ],
        'App\\Import\\Domain\\Entity\\ImportSource' => [
            ['imports.view_own', 'imports.view_all'],
            ['imports.run'],
        ],

        'App\\Integration\\Generic\\Domain\\Entity\\Connection' => [
            ['settings.integrations.manage'],
            ['settings.integrations.manage'],
        ],
        'App\\Integration\\Generic\\Domain\\Entity\\RemoteEndpoint' => [
            ['settings.integrations.manage'],
            ['settings.integrations.manage'],
        ],
        'App\\Integration\\Generic\\Domain\\Entity\\RemoteField' => [
            ['settings.integrations.manage'],
            ['settings.integrations.manage'],
        ],
        'App\\Integration\\Generic\\Domain\\Entity\\FieldMapping' => [
            ['settings.integrations.manage'],
            ['settings.integrations.manage'],
        ],
        'App\\Integration\\Generic\\Domain\\Entity\\SyncBinding' => [
            ['settings.integrations.manage'],
            ['settings.integrations.manage'],
        ],
        'App\\Integration\\Generic\\Domain\\Entity\\SyncRun' => [
            ['settings.integrations.manage'],
            ['settings.integrations.manage'],
        ],
        'App\\Integration\\Generic\\Domain\\Entity\\SyncRunLog' => [
            ['settings.integrations.manage'],
            ['settings.integrations.manage'],
        ],
        'App\\ApiConfigurator\\Domain\\Entity\\WebhookDelivery' => [
            ['settings.integrations.manage'],
            ['settings.integrations.manage'],
        ],
        'App\\ApiConfigurator\\Domain\\Entity\\ApiProfile' => [
            ['settings.integrations.manage'],
            ['settings.integrations.manage'],
        ],
        // An API key's own row is configuration; the raw secret is a
        // separate concern already split off by Prd\IntegrationVoter
        // (`settings.integration_secrets.read`) and is not widened here.
        'App\\ApiConfigurator\\Domain\\Entity\\ApiKey' => [
            ['settings.integrations.manage'],
            ['settings.integrations.manage'],
        ],
    ];

    /**
     * Voter attribute → whether it is a read or a write. Mirrors the
     * legacy voters' own attribute maps so no operation changes meaning
     * on the way over.
     *
     * @var array<string, self::READ|self::WRITE>
     */
    private const array ATTRIBUTES = [
        'READ' => self::READ,
        'CREATE' => self::WRITE,
        'UPDATE' => self::WRITE,
        'WRITE' => self::WRITE,
        'DELETE' => self::WRITE,
    ];

    public function __construct(
        private readonly PermissionResolverInterface $resolver,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\array_key_exists($attribute, self::ATTRIBUTES)) {
            return false;
        }

        return null !== $this->subjectClass($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $class = $this->subjectClass($subject);
        if (null === $class) {
            return false;
        }

        [$readCodes, $writeCodes] = self::RESOURCES[$class];
        $codes = self::READ === self::ATTRIBUTES[$attribute] ? $readCodes : $writeCodes;

        $permissions = $this->resolver->resolve($user);
        $granted = false;
        foreach ($codes as $code) {
            if ($permissions->has($code)) {
                $granted = true;
                break;
            }
        }
        if (!$granted) {
            return false;
        }

        // Class-level subject (collection / POST): no instance to scope,
        // and the Doctrine TenantFilter narrows the rows anyway.
        if (!\is_object($subject)) {
            return true;
        }

        $subjectTenant = $this->extractTenant($subject);

        return null === $subjectTenant
            || $subjectTenant->getId()->toRfc4122() === $user->getTenant()->getId()->toRfc4122();
    }

    /**
     * The mapped FQCN this subject stands for, or null when the subject
     * is not one of them.
     */
    private function subjectClass(mixed $subject): ?string
    {
        if (\is_string($subject)) {
            return \array_key_exists($subject, self::RESOURCES) ? $subject : null;
        }

        if (!\is_object($subject)) {
            return null;
        }

        return \array_key_exists($subject::class, self::RESOURCES) ? $subject::class : null;
    }

    private function extractTenant(object $subject): ?Tenant
    {
        if (!method_exists($subject, 'getTenant')) {
            return null;
        }

        $tenant = $subject->getTenant();

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
