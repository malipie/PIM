<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application;

use App\Export\Catalog\Domain\HtmlSlotPolicy;
use HTMLPurifier;
use HTMLPurifier_Config;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Neutralises product values before they are bound into a catalog HTML template
 * (ADR-0027, CPDF-P0-04). Every value a template slot renders passes through
 * here, so garbage data (a name carrying `<script>`, a description with a
 * `javascript:` href, a stray `\x0B`) can neither break the layout nor inject
 * content into the rendered PDF — the same class of defect as CSV/XML formula
 * injection.
 *
 * Mirrors the write-path {@see \App\Catalog\Application\HtmlSanitizer} config
 * (cross-BC reuse is not allowed, so the HTMLPurifier setup is duplicated here
 * behind the Export seam). Twig autoescape still guards the {@see HtmlSlotPolicy::Escape}
 * path in the template; this class is the value-level defence and the single
 * place the rich-text allow-list lives for catalogs.
 */
final class HtmlValueSanitizer
{
    /** Conservative rich-text allow-list — mirrors the wysiwyg write-path set. */
    private const string ALLOWED_HTML =
        'p,br,strong,b,em,i,u,s,sub,sup,'
        .'ul,ol,li,'
        .'a[href|title|target|rel],'
        .'h1,h2,h3,h4,h5,h6,'
        .'blockquote,pre,code,hr,'
        .'table,thead,tbody,tr,th,td';

    /** `javascript:` / `data:` are absent by design — the stored-XSS vectors. */
    private const string ALLOWED_URI_SCHEMES = 'http,https,mailto';

    private ?HTMLPurifier $purifier = null;

    public function sanitize(string $value, HtmlSlotPolicy $policy): string
    {
        $value = $this->stripControlChars($value);

        if ('' === $value) {
            return '';
        }

        return match ($policy) {
            HtmlSlotPolicy::Escape => htmlspecialchars(
                $value,
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8',
            ),
            HtmlSlotPolicy::RichText => $this->purifier()->purify($value),
            HtmlSlotPolicy::Strip => trim(strip_tags($value)),
        };
    }

    /**
     * Remove XML/HTML-illegal C0 control chars (keep tab/newline/carriage
     * return). Applied to every policy before anything else so a `\x0B` cannot
     * survive into the layout.
     */
    private function stripControlChars(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? $value;
    }

    private function purifier(): HTMLPurifier
    {
        if (null !== $this->purifier) {
            return $this->purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::ALLOWED_HTML);
        $config->set('URI.AllowedSchemes', array_fill_keys(
            explode(',', self::ALLOWED_URI_SCHEMES),
            true,
        ));
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow']);
        $config->set('HTML.Nofollow', true);
        // FrankenPHP worker mode: no on-disk serialiser cache. The definition is
        // cheap to rebuild and the instance is reused for the worker lifetime.
        $config->set('Cache.DefinitionImpl', null);

        return $this->purifier = new HTMLPurifier($config);
    }
}
