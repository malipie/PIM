<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\Security;

use App\Agent\Domain\Entity\BrandVoiceProfile;
use App\Agent\Domain\Entity\ContentRecipe;
use App\Identity\Contracts\Policy\PermissionCheckerInterface;
use App\Shared\Application\UserIdentityAware;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * AICG-P1-03 (#2329, ADR-0030) — maps the API Platform security
 * attributes of /api/content-recipes + /api/brand-voice-profiles onto
 * the settings.ai_content permission module:
 *
 *   READ   → settings.ai_content.read
 *   CREATE → settings.ai_content.create
 *   ADMIN  → settings.ai_content.admin   (edit / delete / set-default)
 *
 * Lives in the Agent BC — NOT next to the other PRD voters in Identity
 * — because core must never import App\Agent\* (removability,
 * ADR-0024 a): an Identity voter referencing these entities would break
 * the build after `rm -rf src/Agent`. Registered via services_agent.yaml,
 * it disappears with the module together with its resources.
 *
 * Fails closed: no token user, an API-key principal (not user-scoped)
 * or an unknown permission code all deny.
 *
 * @extends Voter<string, ContentRecipe|BrandVoiceProfile|class-string>
 */
final class AiContentSettingsVoter extends Voter
{
    private const array PERMISSION_MAP = [
        'READ' => 'settings.ai_content.read',
        'CREATE' => 'settings.ai_content.create',
        'ADMIN' => 'settings.ai_content.admin',
    ];

    public function __construct(private readonly PermissionCheckerInterface $permissions)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\array_key_exists($attribute, self::PERMISSION_MAP)) {
            return false;
        }

        if (\is_string($subject)) {
            return \in_array($subject, [ContentRecipe::class, BrandVoiceProfile::class], true);
        }

        return $subject instanceof ContentRecipe || $subject instanceof BrandVoiceProfile;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof UserIdentityAware) {
            return false;
        }

        return $this->permissions->userHasPermission($user->getId(), self::PERMISSION_MAP[$attribute]);
    }
}
