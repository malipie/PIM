import { useList } from '@refinedev/core';
import { useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { HttpError, jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

interface CategoryEntry {
  id: string;
  code: string;
  path?: string | null;
  attributesIndexed?: Record<string, unknown>;
}

interface ObjectTypeRow {
  id: string;
  code: string;
  kind: string;
}

const CODE_RE = /^[a-z][a-z0-9_]*$/;

/**
 * VIEW-04 (#408) — minimal Create page wired to the new BE landing in
 * the same PR. Fields:
 *   - Code (required, lowercase snake_case so the listener autobuilds path)
 *   - Parent (optional Select; empty → root)
 *   - Name PL/EN (stored in attributes.name JSONB via separate flow —
 *     for MVP the BE accepts it inside the create payload only when an
 *     `attributes` upserter is wired; here we keep it form-local and
 *     persist after redirect via the Show page)
 *   - Live ltree path preview
 *
 * Pixel-perfect of the Create flow + Save-on-create attributes mirroring
 * the wizard pattern lands in VIEW-04b. This page covers the operator-
 * blocking gap (CTA had no destination route).
 */
export function CategoryCreatePage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [searchParams] = useSearchParams();
  // ADR-015 — which ObjectType tree this new category joins (carried from
  // the categories list selector via ?targetObjectTypeId=).
  const targetObjectTypeId = searchParams.get('targetObjectTypeId') ?? '';

  const [code, setCode] = useState('');
  const [parentId, setParentId] = useState<string>('');
  const [namePl, setNamePl] = useState('');
  const [nameEn, setNameEn] = useState('');
  const [descPl, setDescPl] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { result: parents } = useList<CategoryEntry>({
    resource: 'categories',
    pagination: { mode: 'off' },
    // ADR-015 — parents must come from the same tree as the new category.
    filters: targetObjectTypeId
      ? [{ field: 'categoryTargetObjectType', operator: 'eq', value: targetObjectTypeId }]
      : [],
  });

  const { result: objectTypes } = useList<ObjectTypeRow>({
    resource: 'object_types',
    pagination: { mode: 'off' },
  });

  const categoryObjectTypeId = useMemo(
    () => (objectTypes.data ?? []).find((t) => t.kind === 'category')?.id ?? null,
    [objectTypes.data],
  );

  const parentPath = useMemo(() => {
    if (!parentId) return null;
    return (parents.data ?? []).find((c) => c.id === parentId)?.path ?? null;
  }, [parentId, parents.data]);

  const previewPath = useMemo(() => {
    if (!code) return null;
    if (!CODE_RE.test(code)) return null;
    return parentPath ? `${parentPath}.${code}` : code;
  }, [code, parentPath]);

  const codeError = code !== '' && !CODE_RE.test(code);

  const handleSubmit = async () => {
    if (!CODE_RE.test(code)) {
      setError(
        t('categories.create_code_error', {
          defaultValue: 'Code musi być snake_case z małych liter (np. "ortopeda").',
        }),
      );
      return;
    }
    if (!categoryObjectTypeId) {
      setError(
        t('categories.create_object_type_missing', {
          defaultValue: 'Brak built-in ObjectType dla kategorii w tym tenancie.',
        }),
      );
      return;
    }

    setSubmitting(true);
    setError(null);
    try {
      const body: Record<string, unknown> = {
        code,
        objectTypeId: categoryObjectTypeId,
      };
      // #1358 — persist the typed name so the category tree shows a
      // readable label instead of the raw snake_case code. Previously the
      // name was captured but never sent (the VIEW-04b upserter wiring was
      // never finished), so every operator-created category fell back to
      // its code in the tree.
      const name = namePl.trim() || nameEn.trim();
      if (name !== '') {
        body.attributes = { name };
      }
      // ADR-015 — the categorizable ObjectType tree this category joins.
      if (targetObjectTypeId) {
        body.categoryTargetObjectTypeId = targetObjectTypeId;
      }
      if (parentId) {
        body.parentId = parentId;
      }
      const created = await jsonFetch<{ id?: string }>('/api/categories', {
        method: 'POST',
        contentType: 'application/ld+json',
        accept: 'application/ld+json',
        body,
      });
      const newId = created.id;
      await queryClient.invalidateQueries({ queryKey: ['categories'] });
      // ADR-015 — return to the SAME tree the category was created in, else
      // the list defaults to the first tree (Product) and the freshly created
      // category is invisible until the operator re-picks its ObjectType.
      const params = new URLSearchParams();
      if (targetObjectTypeId) {
        params.set('targetObjectTypeId', targetObjectTypeId);
        const targetKind = (objectTypes.data ?? []).find((t) => t.id === targetObjectTypeId)?.kind;
        if (targetKind) params.set('targetType', targetKind);
      }
      if (newId) params.set('selected', newId);
      const qs = params.toString();
      navigate(qs ? `/modeling/categories?${qs}` : '/modeling/categories');
    } catch (err) {
      setError(
        err instanceof HttpError
          ? typeof err.body === 'object' && err.body !== null && 'detail' in err.body
            ? String((err.body as { detail: unknown }).detail)
            : err.message
          : err instanceof Error
            ? err.message
            : 'unknown',
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="space-y-6">
      <header className="space-y-3">
        <p className="text-[12px] font-medium uppercase tracking-wider text-muted-foreground">
          {t('categories.create_caption', { defaultValue: 'Categories' })}
        </p>
        <h1 className="display text-[32px] font-semibold leading-tight text-ink">
          {t('categories.create_title', { defaultValue: 'Nowa kategoria' })}
        </h1>
        <p className="max-w-3xl text-[14px] leading-relaxed text-ink-2">
          {t('categories.create_description', {
            defaultValue:
              'Hierarchiczny węzeł w drzewie ltree. Operatorzy obiektów typu objektowego (Service / Product / ...) deklarują na nim grupy atrybutów; dziedziczone w dół.',
          })}
        </p>
      </header>

      <Button asChild variant="ghost" size="sm" className="-ml-3">
        <Link to="/modeling/categories">
          <ArrowLeft className="size-4" />
          {t('categories.create_back', { defaultValue: 'Wstecz do drzewa' })}
        </Link>
      </Button>

      <Card className="space-y-6 p-6">
        <section className="space-y-4">
          <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
            {t('categories.fields.section_identification', {
              defaultValue: 'Identyfikacja',
            })}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="cat-code">{t('categories.fields.code', { defaultValue: 'Kod' })}</Label>
            <Input
              id="cat-code"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="ortopeda"
              className={cn('h-10 rounded-xl font-mono', codeError && 'border-rose-500')}
            />
            <p className="text-[11px] text-zinc-500">
              {t('categories.fields.code_help', {
                defaultValue: 'snake_case z małych liter — zostanie wpisany w ścieżkę ltree.',
              })}
            </p>
            {codeError ? (
              <p className="text-[11px] text-rose-600">
                {t('categories.create_code_error', {
                  defaultValue: 'Code musi być snake_case z małych liter.',
                })}
              </p>
            ) : null}
          </div>

          <div className="grid gap-2">
            <Label htmlFor="cat-parent">
              {t('categories.fields.parent', { defaultValue: 'Rodzic' })}
            </Label>
            <select
              id="cat-parent"
              value={parentId}
              onChange={(e) => setParentId(e.target.value)}
              className="h-10 rounded-xl border border-input bg-background px-3 text-sm"
            >
              <option value="">
                — {t('categories.fields.parent_root_option', { defaultValue: 'root (brak)' })} —
              </option>
              {(parents.data ?? [])
                .slice()
                .sort((a, b) => (a.path ?? a.code).localeCompare(b.path ?? b.code))
                .map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.path ?? p.code}
                  </option>
                ))}
            </select>
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="cat-name-pl">
                {t('categories.fields.name_pl', { defaultValue: 'Nazwa PL' })}
              </Label>
              <Input
                id="cat-name-pl"
                value={namePl}
                onChange={(e) => setNamePl(e.target.value)}
                placeholder="Ortopeda"
                className="h-10 rounded-xl"
              />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="cat-name-en">
                {t('categories.fields.name_en', { defaultValue: 'Nazwa EN' })}
              </Label>
              <Input
                id="cat-name-en"
                value={nameEn}
                onChange={(e) => setNameEn(e.target.value)}
                placeholder="Orthopedist"
                className="h-10 rounded-xl"
              />
            </div>
          </div>

          <div className="grid gap-2">
            <Label htmlFor="cat-desc-pl">
              {t('categories.fields.description', { defaultValue: 'Opis (PL, opcjonalny)' })}
            </Label>
            <Textarea
              id="cat-desc-pl"
              value={descPl}
              onChange={(e) => setDescPl(e.target.value)}
              rows={3}
              className="rounded-xl"
            />
          </div>
        </section>

        <section className="space-y-2 rounded-xl bg-zinc-50 px-4 py-3">
          <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
            {t('categories.create_path_preview_label', {
              defaultValue: 'Ścieżka po zapisie',
            })}
          </div>
          <code className="font-mono text-[13px] text-zinc-700">
            {previewPath ??
              t('categories.create_path_preview_empty', {
                defaultValue: 'Wpisz code aby zobaczyć ścieżkę.',
              })}
          </code>
        </section>

        {error !== null ? (
          <p className="rounded-md bg-rose-50 px-3 py-2 text-[12px] text-rose-700">{error}</p>
        ) : null}

        <div className="flex items-center justify-end gap-2 border-t border-zinc-100 pt-4">
          <Button asChild variant="ghost" disabled={submitting}>
            <Link to="/modeling/categories">
              {t('categories.create_cancel', { defaultValue: 'Anuluj' })}
            </Link>
          </Button>
          <Button onClick={handleSubmit} disabled={submitting || !code || codeError}>
            {t('categories.create_submit', { defaultValue: 'Utwórz kategorię' })}
          </Button>
        </div>
      </Card>
    </div>
  );
}
