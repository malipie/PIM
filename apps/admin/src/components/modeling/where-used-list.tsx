import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Card, CardContent } from '@/components/ui/card';
import { HttpError, jsonFetch } from '@/lib/http';
import type { AttributeGroupUsage, AttributeUsage, ObjectTypeUsage } from '@/lib/modeling-usage';

/**
 * UI-08.10 (#265) — `<WhereUsedList>` widget.
 *
 * Reads UI-08.7 endpoints and renders the deduplicated list of
 * (groups, objectTypes, categories) + instance count for any of the
 * three modeling resources.
 *
 *   resource = 'attributes'        → /api/attributes/{id}/usage
 *   resource = 'attribute_groups'  → /api/attribute_groups/{id}/usage
 *   resource = 'object_types'      → /api/object_types/{id}/usage
 *
 * The widget is read-only and tolerant of partial payloads — counts
 * default to zero, missing arrays render as "—" so a half-finished
 * tenant (no junctions yet) still shows a clean detail panel rather
 * than `undefined`.
 *
 * #3034 — the payload shapes moved to `@/lib/modeling-usage`. They used to be
 * duplicated here and at three call sites, and two of those copies had drifted
 * from the API (silently rendering 0 forever).
 */
type Resource = 'attributes' | 'attribute_groups' | 'object_types';

type UsagePayload = AttributeUsage | AttributeGroupUsage | ObjectTypeUsage;

interface WhereUsedListProps {
  resource: Resource;
  id: string;
}

export function WhereUsedList({ resource, id }: WhereUsedListProps) {
  const { t } = useTranslation();
  const [data, setData] = useState<UsagePayload | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    setData(null);

    jsonFetch<UsagePayload>(`/api/${resource}/${id}/usage`, { accept: 'application/json' })
      .then((payload) => {
        if (!cancelled) setData(payload);
      })
      .catch((err) => {
        if (cancelled) return;
        setError(
          err instanceof HttpError && err.status === 404
            ? t('modeling.where_used.not_found')
            : t('modeling.where_used.error'),
        );
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [resource, id, t]);

  return (
    <Card>
      <CardContent className="space-y-3 pt-6">
        <h3 className="text-sm font-semibold">{t('modeling.where_used.title')}</h3>
        {loading ? (
          <p className="text-sm text-muted-foreground">{t('app.loading')}</p>
        ) : error !== null ? (
          <p className="text-sm text-destructive">{error}</p>
        ) : data === null ? (
          <p className="text-sm text-muted-foreground">{t('modeling.where_used.empty')}</p>
        ) : (
          <UsageBody resource={resource} payload={data} />
        )}
      </CardContent>
    </Card>
  );
}

function UsageBody({ resource, payload }: { resource: Resource; payload: UsagePayload }) {
  const { t } = useTranslation();

  if (resource === 'attributes') {
    const usage = payload as AttributeUsage;
    return (
      <dl className="grid gap-2 text-sm">
        <UsageRow label={t('modeling.where_used.groups')} count={usage.groups.length} />
        <UsageRow label={t('modeling.where_used.object_types')} count={usage.objectTypes.length} />
        <UsageRow label={t('modeling.where_used.categories')} count={usage.categories.length} />
        <UsageRow label={t('modeling.where_used.instances')} count={usage.instanceCount} emphasis />
      </dl>
    );
  }

  if (resource === 'attribute_groups') {
    const usage = payload as AttributeGroupUsage;
    return (
      <dl className="grid gap-2 text-sm">
        <UsageRow
          label={t('modeling.where_used.attached_object_types')}
          count={usage.directlyAttachedTo.objectTypes.length}
        />
        <UsageRow
          label={t('modeling.where_used.attached_categories')}
          count={usage.directlyAttachedTo.categories.length}
        />
        <UsageRow label={t('modeling.where_used.member_attributes')} count={usage.attributeCount} />
        <UsageRow
          label={t('modeling.where_used.affected_instances')}
          count={usage.affectedInstanceCount}
          emphasis
        />
      </dl>
    );
  }

  const usage = payload as ObjectTypeUsage;
  return (
    <dl className="grid gap-2 text-sm">
      <UsageRow label={t('modeling.where_used.instances')} count={usage.instanceCount} emphasis />
      <UsageRow
        label={t('modeling.where_used.attached_attributes')}
        count={usage.attributesAttachedCount}
      />
      <UsageRow
        label={t('modeling.where_used.attached_groups')}
        count={usage.attributeGroupsAttachedCount}
      />
      <UsageRow
        label={t('modeling.where_used.api_profiles')}
        count={usage.referencedByApiProfileCount}
      />
      <UsageRow
        label={t('modeling.where_used.category_attachments')}
        count={usage.referencedByCategoryAttachmentCount}
      />
    </dl>
  );
}

function UsageRow({
  label,
  count,
  emphasis,
}: {
  label: string;
  count: number;
  emphasis?: boolean;
}) {
  return (
    <div className="flex items-center justify-between">
      <dt className="text-muted-foreground">{label}</dt>
      <dd
        className={
          emphasis
            ? 'font-mono text-sm font-semibold tabular-nums'
            : 'font-mono text-sm tabular-nums'
        }
      >
        {count}
      </dd>
    </div>
  );
}
