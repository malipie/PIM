<?php

declare(strict_types=1);

namespace App\Agent\Application\Content;

use App\Agent\Domain\Entity\BrandVoiceProfile;
use App\Agent\Domain\Entity\ContentRecipe;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\RlsTenantGuard;
use Doctrine\ORM\EntityManagerInterface;

/**
 * AICG-P1-04 (#2330, ADR-0030) — idempotent per-tenant seeder of the
 * built-in content-generation defaults (the Akeneo built-in AI
 * Configurations counterpart): an editor starts from working templates,
 * not an empty form.
 *
 *   - "Opis produktu" — HTML product description grounded in the
 *     standard fact attributes,
 *   - "Meta SEO" — plain-text meta description with the default SEO
 *     length budget (title ~60 / meta ~155),
 *   - one default BrandVoiceProfile ("ekspercki, zwięzły").
 *
 * Built-in recipes are read-only in the CRUD (AICG-P1-03) — editors
 * clone them. The voice profile is seeded as default only when the
 * tenant has no default yet (never steals an operator's choice).
 * Lives in the removable Agent BC and is invoked from the module's own
 * console command — core fixtures must not import App\Agent\*
 * (ADR-0024 a); dev/demo tenants get the defaults via that command.
 *
 * Idempotent: matching by recipe code / built-in flag, re-runs are
 * no-ops.
 */
final readonly class AiContentDefaultsSeeder
{
    public const string RECIPE_PRODUCT_DESCRIPTION = 'product_description';
    public const string RECIPE_META_SEO = 'meta_seo';

    public function __construct(
        private EntityManagerInterface $em,
        private TenantContext $tenantContext,
        private RlsTenantGuard $rls,
    ) {
    }

    /**
     * @return int number of rows actually created (0 = idempotent no-op)
     */
    public function seed(Tenant $tenant): int
    {
        $previous = $this->tenantContext->get();
        $this->tenantContext->set($tenant);
        // Console invocations have no HTTP listener / worker middleware to
        // bind the RLS GUC - without it FORCE RLS rejects the INSERTs.
        $this->rls->reassert($tenant);

        try {
            $created = 0;
            $created += $this->seedRecipes($tenant);
            $created += $this->seedDefaultVoice($tenant);

            if ($created > 0) {
                $this->em->flush();
            }

            return $created;
        } finally {
            if (null === $previous) {
                $this->tenantContext->clear();
            } else {
                $this->tenantContext->set($previous);
            }
        }
    }

    private function seedRecipes(Tenant $tenant): int
    {
        $recipes = $this->em->getRepository(ContentRecipe::class);
        $created = 0;

        if (null === $recipes->findOneBy(['code' => self::RECIPE_PRODUCT_DESCRIPTION, 'tenant' => $tenant])) {
            $description = new ContentRecipe(
                code: self::RECIPE_PRODUCT_DESCRIPTION,
                name: 'Opis produktu',
                targetAttribute: 'description',
                sourceAttributes: ['material', 'dimensions', 'features', 'color', 'category', 'brand'],
                constraints: ['format' => ContentRecipe::FORMAT_HTML, 'max_len' => 1500],
            );
            $description->updateToneHint('neutralny, rzeczowy');
            $description->markBuiltIn();
            $this->em->persist($description);
            ++$created;
        }

        if (null === $recipes->findOneBy(['code' => self::RECIPE_META_SEO, 'tenant' => $tenant])) {
            $seo = new ContentRecipe(
                code: self::RECIPE_META_SEO,
                name: 'Meta SEO',
                targetAttribute: 'meta_description',
                sourceAttributes: ['name', 'category', 'brand', 'features'],
                constraints: [
                    'format' => ContentRecipe::FORMAT_PLAIN,
                    'seo' => ['title_len' => 60, 'meta_len' => 155],
                ],
            );
            $seo->markBuiltIn();
            $this->em->persist($seo);
            ++$created;
        }

        return $created;
    }

    private function seedDefaultVoice(Tenant $tenant): int
    {
        $voices = $this->em->getRepository(BrandVoiceProfile::class);
        if (null !== $voices->findOneBy(['isDefault' => true, 'tenant' => $tenant])) {
            return 0;
        }

        $voice = new BrandVoiceProfile(
            name: 'Domyślny głos marki',
            tone: 'ekspercki, zwięzły',
            examples: [[
                'good' => 'Rama z anodowanego aluminium utrzymuje 120 kg przy wadze własnej 1,8 kg.',
                'bad' => 'Mega lekka i super wytrzymała rama w okazyjnej cenie!!!',
            ]],
        );
        $voice->markDefault(true);
        $this->em->persist($voice);

        return 1;
    }
}
