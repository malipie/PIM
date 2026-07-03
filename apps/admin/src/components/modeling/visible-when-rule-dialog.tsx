import { Eye } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { HttpError, httpErrorDetail, jsonFetch } from '@/lib/http';

/**
 * DP-08 (#2039) — editor for the per-group-member `visible_when` rule
 * (UI-08.8 #263 substrate; the chip was display-only until now).
 *
 * MVP shape `{field, operator: 'equals', value}`; the backend enforces
 * that the referenced field lives in the SAME group, so the field picker
 * only offers sibling members. Saving PATCHes the junction
 * (`/api/attribute_groups/{gid}/attributes/{aid}`), `null` clears.
 * Callers mount the dialog only while open (fresh state per opening —
 * ADR-0021 guard counts jsonFetch+useEffect co-occurrence).
 */
export function VisibleWhenRuleDialog({
  groupId,
  attributeId,
  attributeCode,
  siblingCodes,
  initialRule,
  onOpenChange,
  onSaved,
}: {
  groupId: string;
  attributeId: string;
  attributeCode: string;
  /** Codes of the OTHER members of this group (valid condition fields). */
  siblingCodes: string[];
  initialRule: { field: string; operator: string; value: unknown } | null;
  onOpenChange: (open: boolean) => void;
  onSaved: () => Promise<void> | void;
}) {
  const { t } = useTranslation();
  const [field, setField] = useState(initialRule?.field ?? '');
  const [rawValue, setRawValue] = useState(() => {
    const value = initialRule?.value;
    if (value === undefined || value === null) return '';
    return typeof value === 'string' ? value : JSON.stringify(value);
  });
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const persist = async (
    visibleWhen: { field: string; operator: string; value: unknown } | null,
  ) => {
    setSubmitting(true);
    setError(null);
    try {
      await jsonFetch(`/api/attribute_groups/${groupId}/attributes/${attributeId}`, {
        method: 'PATCH',
        contentType: 'application/json',
        accept: 'application/json',
        body: { visibleWhen },
      });
      onOpenChange(false);
      await onSaved();
    } catch (err) {
      setError(
        (err instanceof HttpError ? httpErrorDetail(err) : null) ??
          t('modeling.attributeGroups.rules_save_error', {
            defaultValue: 'Nie udało się zapisać reguły.',
          }),
      );
    } finally {
      setSubmitting(false);
    }
  };

  const save = () => {
    if (field === '') return;
    void persist({ field, operator: 'equals', value: parseValue(rawValue) });
  };

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="max-w-[460px] gap-0 p-0">
        <div className="border-b border-zinc-100 px-6 pb-4 pt-5">
          <div className="flex items-center gap-2">
            <Eye className="size-4 text-zinc-500" />
            <h2 className="text-[15px] font-semibold tracking-tight">
              {t('modeling.attributeGroups.rules_dialog_title', {
                defaultValue: 'Widoczność pola „{{code}}"',
                code: attributeCode,
              })}
            </h2>
          </div>
          <p className="mt-1 text-[12.5px] text-zinc-500">
            {t('modeling.attributeGroups.rules_dialog_hint', {
              defaultValue:
                'Pole będzie widoczne w formularzu tylko, gdy wskazany atrybut z tej samej grupy ma podaną wartość. Wartości ukrytych pól nie są czyszczone.',
            })}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2 px-6 py-4">
          <span className="text-[12.5px] text-zinc-600">
            {t('modeling.attributeGroups.rules_dialog_when', { defaultValue: 'Widoczne gdy' })}
          </span>
          <div>
            <Label className="sr-only" htmlFor="visible-when-field">
              {t('modeling.attributeGroups.rules_dialog_field', { defaultValue: 'Atrybut' })}
            </Label>
            <select
              id="visible-when-field"
              value={field}
              onChange={(e) => setField(e.target.value)}
              className="h-9 min-w-[160px] rounded-md border border-input bg-background px-2 font-mono text-sm"
            >
              <option value="">
                {t('modeling.attributeGroups.rules_dialog_pick', { defaultValue: '— wybierz —' })}
              </option>
              {siblingCodes.map((code) => (
                <option key={code} value={code}>
                  {code}
                </option>
              ))}
            </select>
          </div>
          <span className="text-[12.5px] text-zinc-600">
            {t('modeling.attributeGroups.rules_dialog_equals', { defaultValue: 'równa się' })}
          </span>
          <Input
            aria-label={t('modeling.attributeGroups.rules_dialog_value', {
              defaultValue: 'Wartość',
            })}
            value={rawValue}
            onChange={(e) => setRawValue(e.target.value)}
            placeholder="np. true / 5 / zarowka"
            className="h-9 w-36 font-mono text-sm"
          />
        </div>

        {error !== null ? (
          <p className="mx-6 mb-2 rounded-md border border-destructive/50 bg-destructive/5 px-3 py-2 text-[12.5px] text-destructive">
            {error}
          </p>
        ) : null}

        <div className="flex items-center gap-2 border-t border-zinc-100 px-6 py-4">
          {initialRule !== null ? (
            <Button
              variant="ghost"
              size="sm"
              disabled={submitting}
              onClick={() => void persist(null)}
              className="text-red-600 hover:bg-red-50 hover:text-red-700"
            >
              {t('modeling.attributeGroups.rules_dialog_clear', { defaultValue: 'Usuń regułę' })}
            </Button>
          ) : null}
          <div className="ml-auto flex items-center gap-2">
            <Button variant="ghost" size="sm" onClick={() => onOpenChange(false)}>
              {t('app.cancel', { defaultValue: 'Anuluj' })}
            </Button>
            <Button
              size="sm"
              disabled={field === '' || submitting}
              onClick={save}
              className="rounded-xl bg-zinc-900 hover:bg-zinc-800"
            >
              {t('app.save', { defaultValue: 'Zapisz' })}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}

/** `true`/`false`/numbers regain their JSON types; anything else stays a string. */
function parseValue(raw: string): unknown {
  const trimmed = raw.trim();
  if (trimmed === 'true') return true;
  if (trimmed === 'false') return false;
  if (trimmed !== '' && !Number.isNaN(Number(trimmed))) return Number(trimmed);
  return raw;
}
