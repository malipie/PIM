import { useOne } from '@refinedev/core';
import { ArrowLeft, FolderTree, Pencil } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';

import { AuditLogIndicator } from '@/components/modeling/audit-log-indicator';
import { BuiltInLockBadge } from '@/components/modeling/built-in-lock-badge';
import { CategoryDangerZone } from '@/components/modeling/category-danger-zone';
import { categoryLabelFromAttributes } from '@/components/modeling/category-tree';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { HttpError, jsonFetch } from '@/lib/http';

interface CategoryDetail {
  id: string;
  code: string;
  path?: string | null;
  enabled?: boolean;
  status?: string;
  attributesIndexed?: Record<string, unknown>;
  createdAt?: string;
}

export function CategoryShowPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const params = useParams<{ id: string }>();
  const id = params.id ?? '';
  const { result, query } = useOne<CategoryDetail>({
    resource: 'categories',
    id,
    queryOptions: { enabled: id.length > 0 },
  });

  // #1137 — inline category rename (writes the `name` attribute value).
  const [editing, setEditing] = useState(false);
  const [draftName, setDraftName] = useState('');
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  if (query.isLoading || !result) {
    return <p className="text-sm text-muted-foreground">{t('app.loading')}</p>;
  }

  const category = result;
  // #2942 — `name` is a localizable attribute, so its reading can be a
  // `{pl, en}` map rather than a plain string. Testing for `string` alone
  // dropped straight through to the code, which is the same "the name I
  // typed turned into ksiazki" the tree used to show. Share the tree's
  // resolver so both surfaces agree on what a category is called.
  const name = categoryLabelFromAttributes(category.attributesIndexed) ?? category.code;

  const startEdit = () => {
    setDraftName(name);
    setSaveError(null);
    setEditing(true);
  };

  const saveName = async () => {
    const trimmed = draftName.trim();
    if (trimmed === '' || trimmed === name) {
      setEditing(false);
      return;
    }
    setSaving(true);
    setSaveError(null);
    try {
      await jsonFetch(`/api/categories/${category.id}`, {
        method: 'PATCH',
        contentType: 'application/merge-patch+json',
        accept: 'application/ld+json',
        body: { attributes: { name: trimmed } },
      });
      await query.refetch();
      setEditing(false);
    } catch (err) {
      setSaveError(
        err instanceof HttpError
          ? `HTTP ${err.status}`
          : t('categories.rename_error', { defaultValue: 'Nie udało się zmienić nazwy.' }),
      );
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <div className="flex items-center justify-between">
          <Button asChild variant="ghost" size="sm" className="-ml-3">
            <Link to="/modeling/categories">
              <ArrowLeft className="size-4" />
              {t('categories.back')}
            </Link>
          </Button>
          <AuditLogIndicator />
        </div>
        {editing ? (
          <div className="flex flex-wrap items-center gap-2">
            <Input
              value={draftName}
              onChange={(e) => setDraftName(e.target.value)}
              autoFocus
              aria-label={t('categories.rename', { defaultValue: 'Zmień nazwę kategorii' })}
              className="h-11 max-w-md text-[20px] font-semibold"
              onKeyDown={(e) => {
                if (e.key === 'Enter') void saveName();
                if (e.key === 'Escape') setEditing(false);
              }}
            />
            <Button size="sm" onClick={() => void saveName()} disabled={saving}>
              {saving
                ? t('app.saving', { defaultValue: 'Zapisywanie…' })
                : t('app.save', { defaultValue: 'Zapisz' })}
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setEditing(false)} disabled={saving}>
              {t('app.cancel', { defaultValue: 'Anuluj' })}
            </Button>
          </div>
        ) : (
          <div className="flex items-center gap-2">
            <h1 className="display text-[28px] font-semibold leading-tight text-ink">{name}</h1>
            <Button
              size="icon"
              variant="ghost"
              className="size-8"
              onClick={startEdit}
              aria-label={t('categories.rename', { defaultValue: 'Zmień nazwę kategorii' })}
            >
              <Pencil className="size-4" />
            </Button>
          </div>
        )}
        {saveError !== null ? (
          <p className="text-sm text-destructive" role="alert">
            {saveError}
          </p>
        ) : null}
        <p className="font-mono text-[12px] text-ink-2">{category.code}</p>
        {category.path ? (
          <p className="flex items-center gap-2 text-[12px] text-muted-foreground">
            <span>
              {t('categories.fields.path')}: <code className="font-mono">{category.path}</code>
            </span>
            {/* DP-01 (#2031) — the Move dialog needs the full per-OT tree,
              which only the list page has loaded; deep-link there with
              `?move=1` so it auto-opens for this node. */}
            <Button asChild size="sm" variant="ghost" className="h-6 gap-1 rounded-lg px-2">
              <Link to={`/modeling/categories?selected=${category.id}&move=1`}>
                <FolderTree className="size-3" />
                {t('categories.move_action', { defaultValue: 'Przenieś' })}
              </Link>
            </Button>
          </p>
        ) : null}
      </div>

      <EffectiveAttributesPreview categoryId={category.id} />

      <CategoryDangerZone
        categoryId={category.id}
        categoryLabel={name}
        onDeleted={() => navigate('/modeling/categories')}
      />
    </div>
  );
}

interface EffectiveGroupRow {
  id: string;
  code: string;
  label: Record<string, string> | string;
  is_system_group: boolean;
  auto_attached: boolean;
  attributes: { id: string; code: string; type: string; is_system: boolean }[];
}

interface EffectiveResponse {
  categoryId: string;
  objectType: { id: string; code: string; kind: string; label: Record<string, string> };
  effectiveGroups: EffectiveGroupRow[];
}

/**
 * UI-08.14 (#269) — `<EffectiveAttributesPreview>`.
 *
 * Hits /api/categories/{id}/effective-groups?objectTypeId=... and renders the
 * deduplicated AttributeGroup list a hypothetical object of the picked
 * ObjectType would see if placed under this category. The killer feature
 * competitors (Akeneo, Pimcore) lack natively.
 *
 * ADR-015 (#1127) — picks the target by ObjectType id (not kind) and lists
 * every categorizable ObjectType (built-in + custom), so custom-OT category
 * trees can be previewed (kind='custom' has no single built-in OT).
 */
function EffectiveAttributesPreview({ categoryId }: { categoryId: string }) {
  const { t } = useTranslation();

  const [data, setData] = useState<EffectiveResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    setData(null);

    // #1134 — no per-kind selector: a category belongs to exactly one tree,
    // so the backend resolves the preview against the category's own target
    // ObjectType.
    jsonFetch<EffectiveResponse>(`/api/categories/${categoryId}/effective-groups`, {
      accept: 'application/json',
    })
      .then((payload) => {
        if (!cancelled) setData(payload);
      })
      .catch((err) => {
        if (cancelled) return;
        setError(
          err instanceof HttpError && err.status === 404
            ? t('modeling.inheritance_preview.not_found')
            : t('modeling.inheritance_preview.error'),
        );
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [categoryId, t]);

  return (
    <Card className="border-orange-500/30 bg-orange-500/5 soft-shadow">
      <CardContent className="space-y-4 pt-6">
        <div className="space-y-1">
          <div className="inline-flex items-center gap-1.5 rounded-full bg-orange-500/10 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-orange-700">
            effective preview
          </div>
          <h2 className="text-[15px] font-semibold text-ink">
            {t('modeling.inheritance_preview.title')}
          </h2>
          <p className="text-[12px] text-muted-foreground">
            {t('modeling.inheritance_preview.description')}
          </p>
        </div>

        {loading ? (
          <p className="text-sm text-muted-foreground">{t('app.loading')}</p>
        ) : error !== null ? (
          <p className="text-sm text-destructive">{error}</p>
        ) : data === null || data.effectiveGroups.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('modeling.inheritance_preview.empty')}</p>
        ) : (
          <ol className="space-y-2">
            {data.effectiveGroups.map((group) => (
              <li
                key={group.id}
                className="rounded-md border bg-card px-3 py-2"
                style={
                  typeof group.label === 'object' && group.id !== ''
                    ? { borderLeftColor: 'transparent' }
                    : undefined
                }
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-mono text-xs text-muted-foreground">{group.code}</span>
                  <span className="text-sm font-medium">{groupLabel(group.label)}</span>
                  {group.is_system_group ? <BuiltInLockBadge tone="quiet" /> : null}
                  {group.auto_attached ? (
                    <span className="rounded bg-blue-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-blue-900">
                      {t('modeling.attribute_groups.auto_attached')}
                    </span>
                  ) : null}
                  <span className="ml-auto text-xs text-muted-foreground">
                    {t('modeling.inheritance_preview.attribute_count', {
                      count: group.attributes.length,
                    })}
                  </span>
                </div>
                {group.attributes.length > 0 ? (
                  <ul className="mt-2 flex flex-wrap gap-1.5">
                    {group.attributes.map((attr) => (
                      <li
                        key={attr.id}
                        className="rounded bg-muted px-2 py-0.5 text-[11px] font-mono"
                      >
                        {attr.is_system ? '🔒 ' : ''}
                        {attr.code}
                      </li>
                    ))}
                  </ul>
                ) : null}
              </li>
            ))}
          </ol>
        )}
      </CardContent>
    </Card>
  );
}

function groupLabel(label: Record<string, string> | string): string {
  if (typeof label === 'string') return label;
  return label.en ?? label.pl ?? Object.values(label)[0] ?? '—';
}
