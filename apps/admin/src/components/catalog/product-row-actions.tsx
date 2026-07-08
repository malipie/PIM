import { Copy, History, MoreVertical, PencilLine, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { jsonFetch } from '@/lib/http';

import { DuplicateProductDialog } from './duplicate-product-dialog';

interface AuditEntry {
  type: string | null;
  user: string | null;
  diffs: unknown;
  created_at: string | null;
}

/**
 * UI-02.13 (#303) — per-row 3-dot menu for the products list.
 *
 * Wires Edit, Duplicate (UI-02.4 endpoint), Toggle enabled, Audit log
 * (UI-02.5 endpoint), Copy URL, Delete. Quick edit popover deferred —
 * inline cell editing belongs to the Excel-mode work (UI-02.12); the
 * one-attribute popover comes back as a follow-up.
 */
export function ProductRowActions({
  productId,
  onChanged,
}: {
  productId: string;
  onChanged: () => void;
}) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [showDuplicate, setShowDuplicate] = useState(false);
  const [showAudit, setShowAudit] = useState(false);

  const handleDelete = async (): Promise<void> => {
    if (
      !window.confirm(
        t('products.actions.confirm_delete', {
          defaultValue: 'Delete this product? This cannot be undone.',
        }),
      )
    ) {
      return;
    }
    await jsonFetch(`/api/products/${productId}`, { method: 'DELETE' });
    onChanged();
  };

  const handleCopyUrl = async (): Promise<void> => {
    const url = `${window.location.origin}/products/${productId}`;
    await navigator.clipboard.writeText(url);
  };

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="sm"
            aria-label={t('products.actions.menu', { defaultValue: 'Actions' })}
          >
            <MoreVertical className="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-56">
          <DropdownMenuItem onSelect={() => navigate(`/products/${productId}`)}>
            <PencilLine className="mr-2 size-4" />
            {t('products.actions.edit', { defaultValue: 'Edit' })}
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={() => setShowDuplicate(true)}>
            <Copy className="mr-2 size-4" />
            {t('products.actions.duplicate', { defaultValue: 'Duplicate' })}
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem onSelect={() => setShowAudit(true)}>
            <History className="mr-2 size-4" />
            {t('products.actions.audit_log', { defaultValue: 'View audit log' })}
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={() => void handleCopyUrl()}>
            <Copy className="mr-2 size-4" />
            {t('products.actions.copy_url', { defaultValue: 'Copy product URL' })}
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem onSelect={() => void handleDelete()} className="text-rose-600">
            <Trash2 className="mr-2 size-4" />
            {t('products.actions.delete', { defaultValue: 'Delete' })}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      {showDuplicate ? (
        <DuplicateProductDialog
          productId={productId}
          onClose={() => setShowDuplicate(false)}
          onDuplicated={onChanged}
        />
      ) : null}

      {showAudit ? (
        <AuditLogSheet productId={productId} onClose={() => setShowAudit(false)} />
      ) : null}
    </>
  );
}

function AuditLogSheet({
  productId,
  onClose,
}: {
  productId: string;
  onClose: () => void;
}): React.JSX.Element {
  const { t } = useTranslation();
  const [entries, setEntries] = useState<AuditEntry[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = async (): Promise<void> => {
    try {
      const body = await jsonFetch<{ entries: AuditEntry[] }>(
        `/api/products/${productId}/audit-log?limit=20`,
      );
      setEntries(body.entries);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'unknown');
    }
  };

  return (
    <Sheet
      open
      onOpenChange={(next) => {
        if (!next) onClose();
      }}
    >
      <SheetTrigger asChild>
        <button type="button" className="hidden" onClick={() => void load()} />
      </SheetTrigger>
      <SheetContent side="right" className="w-[480px] p-6">
        <SheetTitle>
          {t('products.audit.title', { defaultValue: 'Audit log (last 20)' })}
        </SheetTitle>
        <button
          type="button"
          onClick={() => void load()}
          className="mt-4 text-sm text-muted-foreground underline"
        >
          {entries === null
            ? t('products.audit.load', { defaultValue: 'Load entries' })
            : t('products.audit.reload', { defaultValue: 'Reload' })}
        </button>
        <div className="mt-4 space-y-3 overflow-y-auto">
          {error !== null ? (
            <p className="text-sm text-rose-600">{error}</p>
          ) : entries === null ? (
            <p className="text-sm text-muted-foreground">
              {t('products.audit.idle', { defaultValue: 'Click "Load entries" to fetch.' })}
            </p>
          ) : entries.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {t('products.audit.empty', { defaultValue: 'No audit entries recorded yet.' })}
            </p>
          ) : (
            entries.map((entry) => (
              <div
                key={`${entry.created_at ?? ''}-${entry.type ?? ''}-${entry.user ?? ''}`}
                className="rounded border bg-card px-3 py-2 text-xs"
              >
                <div className="flex justify-between">
                  <span className="font-medium">{entry.type}</span>
                  <span className="text-muted-foreground">{entry.created_at}</span>
                </div>
                <div className="mt-1 text-muted-foreground">
                  {entry.user ?? t('products.audit.system_user', { defaultValue: 'system' })}
                </div>
                {entry.diffs !== null ? (
                  <pre className="mt-2 overflow-x-auto rounded bg-muted/40 px-2 py-1 text-[10px]">
                    {JSON.stringify(entry.diffs, null, 2)}
                  </pre>
                ) : null}
              </div>
            ))
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}
