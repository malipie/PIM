<?php

declare(strict_types=1);

namespace App\Catalog\Contracts;

/**
 * Catalog of attribute types supported in MVP.
 *
 * Per ADR-006 (hybrid attribute model): every catalog attribute carries a
 * type that drives storage, validation, and rendering. The cases below
 * cover MVP needs — adding a new type is a coordinated change across:
 *   - this enum (new case);
 *   - per-type validator (#39 / 0.3.9);
 *   - admin form renderer (#56 / 0.6.3);
 *   - attribute-indexed serializer (#38 / 0.3.8).
 *
 * `usesOptions()` is the only behaviour exposed in #31. It powers invariant
 * checks ("an attribute of type=number cannot have AttributeOption rows")
 * that #39 will enforce at the validator layer; storing it as enum logic
 * keeps the answer next to the cases.
 *
 * The enum is `string`-backed so the value lives in Postgres as VARCHAR,
 * round-trips through Doctrine's `enumType:` mapping, and stays grep-able
 * in fixtures + integration calls.
 */
enum AttributeType: string
{
    case Text = 'text';
    case Number = 'number';
    case Select = 'select';
    case Multiselect = 'multiselect';
    case Date = 'date';
    case Boolean = 'boolean';
    case Asset = 'asset';
    case Relation = 'relation';
    case Price = 'price';
    case Metric = 'metric';

    /**
     * VIEW-07.2 (#423) — rich-text content stored as HTML.
     *
     * The frontend renders this with `@udecode/plate` (Slate-based
     * editor) so editors can format paragraphs, headings, lists, and
     * links without leaving the catalog form. Storage stays as a
     * single `string` value in `object_values.value->>'value'`; the
     * backend validator only checks length + string type — the Plate
     * AI extension (`@udecode/plate-ai`) is a Faza 2 follow-up tied to
     * the agent layer.
     */
    case Wysiwyg = 'wysiwyg';

    /**
     * UI-08.3 (#258) — date + time value.
     *
     * Originally introduced as a read-only *system* type backing
     * `created_at`/`updated_at`. Since #1177 it is also a user-facing,
     * writable type (release date + time, promotion deadline, delivery
     * schedule) with a Catalog datetime-validator binding.
     * The system fields stay read-only via the instance-level
     * Attribute entity's `isSystem()` flag, not via the type, so the two
     * use-cases coexist on one enum case.
     */
    case Datetime = 'datetime';

    /**
     * UI-08.3 (#258) — system attribute type. Used by `created_by`/
     * `updated_by` (Reference, with `validation_rules.target_entity = 'user'`
     * per epik plan §12.2).
     *
     * Read-only in MVP: no AttributeValueValidator binding because the
     * reference values are stamped on the `objects` row by Doctrine
     * listeners and surfaced in the form schema for display only. The
     * dispatcher's "no validator registered" branch is the safe fallback
     * if anyone ever tries to POST a value against it.
     */
    case Reference = 'reference';

    /**
     * #1177 — short plain-text without HTML. Distinct from `wysiwyg`
     * (rich HTML) and `text` (single line): renders a multi-line
     * `<textarea>` and keeps CSV export readable. Stored as a single
     * `string` in `object_values.value->>'value'`.
     */
    case Textarea = 'textarea';

    /**
     * #1177 — colour value as a hex (`#RRGGBB`) or `rgb(...)` string.
     * UI renders a colour picker + swatch chip; usable as a visual filter.
     */
    case Color = 'color';

    /**
     * #1177 — email address (manufacturer/supplier contact). Validated
     * against an RFC 5322-lite pattern; UI renders an `<input type="email">`.
     */
    case Email = 'email';

    /**
     * #1179 — unique identifier (EAN-13, GTIN-14, ISBN, internal SKU).
     * Stored as a single `string` in `object_values.value->>'value'`.
     *
     * Value uniqueness is enforced **per ObjectType at the DB level** via
     * trigger-maintained columns + a partial unique index on
     * `object_values` (migration #1179), with an application pre-check
     * (Catalog's identifier-uniqueness validator)
     * for a clean 409 before the constraint. Identifier attributes are
     * coerced to non-localizable / non-scopable on create so exactly one
     * value exists per object.
     */
    case Identifier = 'identifier';

    public function usesOptions(): bool
    {
        return self::Select === $this || self::Multiselect === $this;
    }

    public function isSystemType(): bool
    {
        return self::Reference === $this;
    }
}
