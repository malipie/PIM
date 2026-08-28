<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\HtmlSanitizer;
use App\Catalog\Application\Validation\AttributeValueValidator;
use App\Catalog\Application\ValueWriteCore;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * AUD-032 / W2-1 (C-3) — the JSONB envelope contract from
 * `docs/api/jsonb-schemas.md` §6 must be enforced on the WRITE path for
 * EVERY AttributeType, not just the five that used to sit in
 * `VALUE_VALIDATED_TYPES`. The audit proved 12/17 types accepted arbitrary
 * garbage (`number = "abc OR 1=1"`, `price.amount = "lol"`, extra keys
 * `__proto__` / `evil`, raw `<script>`) verbatim into `object_values.value`.
 *
 * This test pins the adversarial contract directly on
 * {@see ValueWriteCore::formatViolations()}: garbage is rejected (non-empty
 * violation list) and the canonical per-type envelope is accepted (empty
 * list). formatViolations() reads only the injected AttributeValueValidator,
 * so the (final, DI-heavy) constructor is skipped and the collaborator is set
 * reflectively — same pattern as {@see ValueWriteCoreTest}.
 */
final class ValueWriteCoreFormatViolationsTest extends TestCase
{
    private function core(): ValueWriteCore
    {
        $reflection = new ReflectionClass(ValueWriteCore::class);
        $core = $reflection->newInstanceWithoutConstructor();
        // Bare default() — no option repository, so select/multiselect check
        // shape only (option membership needs the DB and is covered in Api tests).
        $reflection->getProperty('valueValidator')->setValue($core, AttributeValueValidator::default());
        // AUD-033 — normalise() sanitises wysiwyg HTML, so the collaborator
        // must be present for the wysiwyg cases below.
        $reflection->getProperty('htmlSanitizer')->setValue($core, new HtmlSanitizer());

        return $core;
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function attribute(AttributeType $type, array $rules = []): Attribute
    {
        $attribute = new Attribute('attr_'.$type->value, ['en' => $type->value], $type);
        if ([] !== $rules) {
            $attribute->updateValidationRules($rules);
        }

        return $attribute;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    #[Test]
    #[DataProvider('garbageProvider')]
    public function formatViolationsRejectsGarbage(AttributeType $type, array $envelope): void
    {
        $core = $this->core();
        $violations = $core->formatViolations($this->attribute($type), $core->normalise($type, $envelope));

        self::assertNotSame(
            [],
            $violations,
            \sprintf('garbage for "%s" must be rejected, got: %s', $type->value, json_encode($envelope)),
        );
    }

    /**
     * Each entry is a shape the canon (jsonb-schemas.md §6) does NOT allow:
     * a wrong value type or an extra envelope key (additionalProperties:false).
     *
     * @return iterable<string, array{AttributeType, array<string, mixed>}>
     */
    public static function garbageProvider(): iterable
    {
        // ── Wrong value type per AttributeType ──────────────────────────────
        yield 'number string injection' => [AttributeType::Number, ['value' => 'abc OR 1=1']];
        yield 'number nested object' => [AttributeType::Number, ['value' => ['x' => 1]]];
        yield 'metric non-numeric value' => [AttributeType::Metric, ['value' => 'lol', 'unit' => 'kg']];
        yield 'metric missing unit' => [AttributeType::Metric, ['value' => 5]];
        yield 'price non-numeric amount' => [AttributeType::Price, ['amount' => 'lol', 'currency' => 'PLN']];
        yield 'price bad currency' => [AttributeType::Price, ['amount' => 9.99, 'currency' => ['x']]];
        yield 'boolean object' => [AttributeType::Boolean, ['value' => ['a' => 'b']]];
        yield 'boolean string truthy' => [AttributeType::Boolean, ['value' => 'true']];
        yield 'text nested object' => [AttributeType::Text, ['value' => ['deep' => ['x' => 1]]]];
        yield 'textarea non-string' => [AttributeType::Textarea, ['value' => 123]];
        yield 'wysiwyg non-string' => [AttributeType::Wysiwyg, ['value' => ['html' => 'x']]];
        yield 'date unparseable' => [AttributeType::Date, ['value' => 'not-a-date']];
        yield 'datetime unparseable' => [AttributeType::Datetime, ['value' => 'garbage']];
        yield 'color non-string' => [AttributeType::Color, ['value' => 123]];
        yield 'email malformed' => [AttributeType::Email, ['value' => 'not-an-email']];
        yield 'asset non-uuid' => [AttributeType::Asset, ['asset_id' => 'not-a-uuid']];
        yield 'asset list with garbage' => [AttributeType::Asset, ['asset_id' => ['not-a-uuid']]];
        yield 'relation non-uuid' => [AttributeType::Relation, ['object_id' => 'lol']];

        // ── additionalProperties:false — extra envelope keys are garbage ─────
        yield 'text extra evil key' => [AttributeType::Text, ['value' => 'ok', 'evil' => '<script>alert(1)</script>']];
        yield 'text proto pollution key' => [AttributeType::Text, ['value' => 'ok', '__proto__' => 'x']];
        yield 'number extra key' => [AttributeType::Number, ['value' => 42, 'rogue' => 1]];
        yield 'price extra key' => [AttributeType::Price, ['amount' => 9.99, 'currency' => 'PLN', 'evil' => 1]];
        yield 'metric extra key' => [AttributeType::Metric, ['value' => 5, 'unit' => 'kg', 'x' => 1]];
        yield 'asset extra key' => [AttributeType::Asset, ['asset_id' => '019edb88-9ed8-7638-94dc-be4a2a721a91', 'evil' => 1]];
        yield 'relation extra key' => [AttributeType::Relation, ['object_id' => '019edb88-9ed8-7638-94dc-be4a2a721a91', 'evil' => 1]];
        yield 'select extra key' => [AttributeType::Select, ['option_code' => 'red', 'evil' => 1]];
        yield 'multiselect extra key' => [AttributeType::Multiselect, ['option_codes' => ['a'], 'evil' => 1]];
        yield 'reference extra key' => [AttributeType::Reference, ['object_id' => '019edb88-9ed8-7638-94dc-be4a2a721a91', 'evil' => 1]];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    #[Test]
    #[DataProvider('validProvider')]
    public function formatViolationsAcceptsCanonicalEnvelopes(AttributeType $type, array $envelope): void
    {
        $core = $this->core();
        $violations = $core->formatViolations($this->attribute($type), $core->normalise($type, $envelope));

        self::assertSame(
            [],
            $violations,
            \sprintf('canonical "%s" must pass, got: %s', $type->value, implode('; ', $violations)),
        );
    }

    /**
     * One canonical, valid envelope per AttributeType (jsonb-schemas.md §6).
     * `reference` carries no validator (system, listener-written) — its shape
     * is still checked, so a clean `{object_id}` must pass.
     *
     * @return iterable<string, array{AttributeType, array<string, mixed>}>
     */
    public static function validProvider(): iterable
    {
        $uuid = '019edb88-9ed8-7638-94dc-be4a2a721a91';
        $uuid2 = '019edb88-9ed8-7638-94dc-be4a2a721a92';

        yield 'text' => [AttributeType::Text, ['value' => 'Stalowy uchwyt M8']];
        yield 'textarea' => [AttributeType::Textarea, ['value' => "Linia 1\nLinia 2"]];
        yield 'wysiwyg' => [AttributeType::Wysiwyg, ['value' => '<p>Opis <b>HTML</b></p>']];
        yield 'number int' => [AttributeType::Number, ['value' => 42]];
        yield 'number float' => [AttributeType::Number, ['value' => 12.5]];
        yield 'date' => [AttributeType::Date, ['value' => '2026-03-15']];
        yield 'datetime' => [AttributeType::Datetime, ['value' => '2026-03-15T10:30:00+00:00']];
        yield 'boolean true' => [AttributeType::Boolean, ['value' => true]];
        yield 'boolean false' => [AttributeType::Boolean, ['value' => false]];
        yield 'color hex' => [AttributeType::Color, ['value' => '#ff8800']];
        yield 'email' => [AttributeType::Email, ['value' => 'kontakt@example.com']];
        yield 'identifier' => [AttributeType::Identifier, ['value' => '5901234123457']];
        yield 'select' => [AttributeType::Select, ['option_code' => 'red']];
        yield 'multiselect' => [AttributeType::Multiselect, ['option_codes' => ['new', 'sale']]];
        yield 'price' => [AttributeType::Price, ['amount' => 249.99, 'currency' => 'PLN']];
        yield 'metric' => [AttributeType::Metric, ['value' => 0.75, 'unit' => 'kg']];
        yield 'asset single' => [AttributeType::Asset, ['asset_id' => $uuid]];
        yield 'asset gallery list' => [AttributeType::Asset, ['asset_id' => [$uuid, $uuid2]]];
        yield 'relation' => [AttributeType::Relation, ['object_id' => $uuid]];
        yield 'reference' => [AttributeType::Reference, ['object_id' => $uuid]];

        // Clearing a value (empty envelope) is always allowed for every type.
        yield 'empty clear' => [AttributeType::Text, []];
        yield 'empty string clear' => [AttributeType::Number, ['value' => '']];
    }
}
