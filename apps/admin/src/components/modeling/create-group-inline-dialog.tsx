import { Check, FolderPlus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { SettingToggleRow } from '@/components/modeling/setting-toggle-row';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { HttpError, jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

const SWATCHES = [
  '#71717a',
  '#3b82f6',
  '#8b5cf6',
  '#10b981',
  '#f59e0b',
  '#ef4444',
  '#06b6d4',
  '#ec4899',
];

const ICONS = ['📦', '📐', '🔧', '⚙️', '🛡️', '💧', '🌡️', '🏗️', '📋', '🎨', '🔌', '📡', '🪛', '🧰'];

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Called with the created group `{ id, code }` after BE confirmed creation. */
  onCreated: (group: { id: string; code: string }) => void;
}

interface CreatedAttributeGroupResponse {
  id?: string;
  '@id'?: string;
  code?: string;
}

/**
 * Skrócony create-group dialog wywoływany z attribute create flow. Po sukcesie
 * parent dostaje `code` i może go dorzucić do swojego `pickedGroupCodes`, żeby
 * post-attribute-create attach pokrył też świeżo utworzoną grupę.
 */
export function CreateGroupInlineDialog({ open, onOpenChange, onCreated }: Props) {
  const { t } = useTranslation();
  const [code, setCode] = useState('');
  const [namePl, setNamePl] = useState('');
  const [descPl, setDescPl] = useState('');
  const [color, setColor] = useState(SWATCHES[0] ?? '#71717a');
  const [icon, setIcon] = useState(ICONS[0] ?? '📦');
  // #1139 — Behavior parity with the full-page form (shared / required section).
  const [shared, setShared] = useState(true);
  const [requiredSection, setRequiredSection] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setCode('');
      setNamePl('');
      setDescPl('');
      setColor(SWATCHES[0] ?? '#71717a');
      setIcon(ICONS[0] ?? '📦');
      setShared(true);
      setRequiredSection(false);
      setError(null);
    }
  }, [open]);

  const valid = code.trim().length > 0 && namePl.trim().length > 0;

  const submit = async () => {
    if (!valid) return;
    setSubmitting(true);
    setError(null);
    try {
      const body: Record<string, unknown> = {
        code: code.trim(),
        label: { pl: namePl.trim() },
        color,
        icon,
        shared,
        requiredSection,
      };
      if (descPl.trim().length > 0) body.description = { pl: descPl.trim() };
      const created = await jsonFetch<CreatedAttributeGroupResponse>('/api/attribute_groups', {
        method: 'POST',
        contentType: 'application/ld+json',
        accept: 'application/ld+json',
        body,
      });
      const newId =
        typeof created.id === 'string' && created.id.length > 0
          ? created.id
          : typeof created['@id'] === 'string'
            ? (created['@id'].split('/').pop() ?? '')
            : '';
      onCreated({ id: newId, code: code.trim() });
      onOpenChange(false);
    } catch (err) {
      if (err instanceof HttpError) {
        const detail =
          err.body && typeof err.body === 'object' && 'detail' in err.body
            ? String((err.body as Record<string, unknown>).detail)
            : null;
        setError(detail ?? `HTTP ${err.status}`);
      } else {
        setError(
          t('modeling.attributes.create_group_inline.error', {
            defaultValue: 'Nie udało się utworzyć grupy',
          }),
        );
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-[640px] gap-0 p-0">
        <div className="flex items-start gap-3 border-b border-zinc-100 px-7 pb-4 pt-6">
          <div className="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-orange-100 text-orange-700">
            <FolderPlus className="size-4" />
          </div>
          <div className="min-w-0 flex-1">
            <div className="font-display text-[18px] font-semibold tracking-tight">
              {t('modeling.attributes.create_group_inline.title', {
                defaultValue: 'Nowa grupa atrybutów',
              })}
            </div>
            <div className="mt-0.5 text-[12.5px] text-muted-foreground">
              {t('modeling.attributes.create_group_inline.desc', {
                defaultValue:
                  'Atrybut zostanie automatycznie dołączony do tej grupy po utworzeniu.',
              })}
            </div>
          </div>
          <button
            type="button"
            onClick={() => onOpenChange(false)}
            className="grid size-9 shrink-0 place-items-center rounded-xl text-muted-foreground hover:bg-zinc-100"
            aria-label={t('app.close', { defaultValue: 'Zamknij' })}
          >
            <X className="size-4" />
          </button>
        </div>

        <div className="space-y-5 px-7 py-5">
          <div className="grid grid-cols-2 gap-x-6 gap-y-4">
            <div>
              <Label className="text-[11.5px] font-medium text-muted-foreground">Code</Label>
              <Input
                value={code}
                onChange={(e) => setCode(e.target.value)}
                placeholder="np. wymiary"
                className="mt-1.5 h-10 font-mono"
              />
              <p className="mt-1 text-[11px] text-muted-foreground">
                slug · niezmienialny po utworzeniu
              </p>
            </div>
            <div>
              <Label className="text-[11.5px] font-medium text-muted-foreground">Nazwa (PL)</Label>
              <Input
                value={namePl}
                onChange={(e) => setNamePl(e.target.value)}
                placeholder="np. Wymiary"
                className="mt-1.5 h-10"
              />
            </div>
          </div>

          <div>
            <Label className="text-[11.5px] font-medium text-muted-foreground">
              Opis (opcjonalny)
            </Label>
            <Textarea
              rows={2}
              value={descPl}
              onChange={(e) => setDescPl(e.target.value)}
              placeholder={t('modeling.attributes.create_group_inline.description_placeholder')}
              className="mt-1.5"
            />
          </div>

          <div className="grid grid-cols-2 gap-x-6">
            <div>
              <Label className="text-[11.5px] font-medium text-muted-foreground">Kolor</Label>
              <div className="mt-2 flex flex-wrap gap-2">
                {SWATCHES.map((s) => (
                  <button
                    key={s}
                    type="button"
                    onClick={() => setColor(s)}
                    aria-label={`Color ${s}`}
                    className={cn(
                      'size-9 rounded-xl ring-offset-2 transition',
                      color === s ? 'ring-2 ring-zinc-900' : 'hover:scale-105',
                    )}
                    style={{ background: s }}
                  />
                ))}
              </div>
            </div>
            <div>
              <Label className="text-[11.5px] font-medium text-muted-foreground">Ikona</Label>
              <div className="mt-2 grid grid-cols-7 gap-1.5">
                {ICONS.map((ic) => (
                  <button
                    key={ic}
                    type="button"
                    onClick={() => setIcon(ic)}
                    className={cn(
                      'grid size-9 place-items-center rounded-xl text-[18px] transition',
                      icon === ic
                        ? 'bg-zinc-900 text-white'
                        : 'border border-zinc-200 bg-white hover:bg-zinc-50',
                    )}
                  >
                    {ic}
                  </button>
                ))}
              </div>
            </div>
          </div>

          <div className="space-y-2 border-t border-zinc-100 pt-4">
            <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
              {t('modeling.attributeGroups.behavior_title', { defaultValue: 'Zachowanie' })}
            </div>
            <SettingToggleRow
              label={t('modeling.attributeGroups.behavior_shared_label', {
                defaultValue: 'Współdzielona',
              })}
              description={t('modeling.attributeGroups.behavior_shared_desc', {
                defaultValue: 'Może być dołączona do wielu ObjectType',
              })}
              checked={shared}
              onChange={setShared}
            />
            <SettingToggleRow
              label={t('modeling.attributeGroups.behavior_required_section_label', {
                defaultValue: 'Wymagana sekcja',
              })}
              description={t('modeling.attributeGroups.behavior_required_section_desc', {
                defaultValue: 'Grupa zawsze widoczna w formularzu',
              })}
              checked={requiredSection}
              onChange={setRequiredSection}
            />
          </div>
        </div>

        <div className="flex items-center justify-between border-t border-zinc-100 bg-zinc-50/60 px-7 py-4">
          <div className="text-[11.5px]">
            {error !== null ? (
              <span className="text-destructive">{error}</span>
            ) : !valid ? (
              <span className="text-amber-700">Wymagane: code i nazwa PL</span>
            ) : (
              <span className="text-muted-foreground">
                Audit log: <span className="font-mono">attribute_group.create</span>
              </span>
            )}
          </div>
          <div className="flex items-center gap-2">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-9 rounded-xl"
              onClick={() => onOpenChange(false)}
              disabled={submitting}
            >
              {t('app.cancel', { defaultValue: 'Anuluj' })}
            </Button>
            <Button
              type="button"
              size="sm"
              disabled={!valid || submitting}
              onClick={() => {
                void submit();
              }}
              className="h-9 rounded-xl bg-zinc-900 hover:bg-zinc-800"
            >
              <Check className="size-4" />
              {t('modeling.attributes.create_group_inline.submit_action', {
                defaultValue: 'Utwórz grupę',
              })}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
