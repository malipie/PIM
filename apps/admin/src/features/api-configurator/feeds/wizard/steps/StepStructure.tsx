import { Braces, Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

import {
  type EditableSlot,
  type EditableStructure,
  emptySlot,
  SLOT_FORMATS,
  SLOT_NODE_KINDS,
  type StructureIssue,
  structureIssues,
} from '../descriptor-edit';

const FIELD_CLASS =
  'h-9 w-full rounded-xl border bg-white px-2.5 font-mono text-[12.5px] outline-none focus:border-zinc-400';
const LABEL_CLASS = 'text-[10.5px] font-medium uppercase tracking-wider text-zinc-500';

/**
 * XMLF-P5-06 — the structure editor step, custom feeds only: root element +
 * namespaces, the item element and its slot list (name, node kind, format,
 * required/maxLength, parent for attribute nodes, wrapIn for repeatable).
 * Validation mirrors the backend descriptor guard inline; the live outline
 * on the right previews the resulting XML shape.
 */
export function StepStructure({
  edit,
  onEdit,
}: {
  edit: EditableStructure;
  onEdit: (next: EditableStructure) => void;
}) {
  const { t } = useTranslation();
  const issues = structureIssues(edit);

  const issueFor = (
    field: StructureIssue['field'],
    slotIndex?: number,
    namespaceIndex?: number,
  ): StructureIssue | undefined =>
    issues.find(
      (issue) =>
        issue.field === field &&
        issue.slotIndex === slotIndex &&
        issue.namespaceIndex === namespaceIndex,
    );

  const patchSlot = (index: number, patch: Partial<EditableSlot>): void => {
    onEdit({
      ...edit,
      slots: edit.slots.map((slot, i) => (i === index ? { ...slot, ...patch } : slot)),
    });
  };

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_300px]">
      <div className="space-y-4">
        <div className="rounded-3xl bg-white p-6 soft-shadow">
          <div className="flex items-center gap-2.5">
            <span className="grid h-7 w-7 place-items-center rounded-xl bg-zinc-100 text-zinc-700">
              <Braces className="h-4 w-4" aria-hidden />
            </span>
            <div className="text-[14.5px] font-semibold tracking-tight">
              {t('api_configurator.feeds.wizard.structure.title')}
            </div>
            <span className="text-[11.5px] text-zinc-500">
              {t('api_configurator.feeds.wizard.structure.subtitle')}
            </span>
          </div>

          <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <NameField
              label={t('api_configurator.feeds.wizard.structure.root_label')}
              value={edit.rootElement}
              onChange={(value) => onEdit({ ...edit, rootElement: value })}
              issue={issueFor('root')}
              t={t}
            />
            <NameField
              label={t('api_configurator.feeds.wizard.structure.item_label')}
              value={edit.itemElement}
              onChange={(value) => onEdit({ ...edit, itemElement: value })}
              issue={issueFor('item')}
              t={t}
            />
          </div>

          <div className="mt-5">
            <div className={LABEL_CLASS}>
              {t('api_configurator.feeds.wizard.structure.namespaces')}
            </div>
            {edit.namespaces.map((ns, index) => {
              const issue = issueFor('namespace', undefined, index);
              return (
                // biome-ignore lint/suspicious/noArrayIndexKey: rows are positional while edited
                <div key={index} className="mt-2 flex items-start gap-2">
                  <div className="w-32 shrink-0">
                    <input
                      value={ns.prefix}
                      onChange={(e) =>
                        onEdit({
                          ...edit,
                          namespaces: edit.namespaces.map((n, i) =>
                            i === index ? { ...n, prefix: e.target.value } : n,
                          ),
                        })
                      }
                      aria-label={t('api_configurator.feeds.wizard.structure.ns_prefix')}
                      placeholder="g"
                      className={cn(FIELD_CLASS, issue ? 'border-rose-400' : 'border-zinc-200')}
                    />
                    {issue && <IssueNote issue={issue} t={t} />}
                  </div>
                  <input
                    value={ns.uri}
                    onChange={(e) =>
                      onEdit({
                        ...edit,
                        namespaces: edit.namespaces.map((n, i) =>
                          i === index ? { ...n, uri: e.target.value } : n,
                        ),
                      })
                    }
                    aria-label={t('api_configurator.feeds.wizard.structure.ns_uri')}
                    placeholder="http://example.com/ns"
                    className={cn(FIELD_CLASS, 'border-zinc-200')}
                  />
                  <button
                    type="button"
                    onClick={() =>
                      onEdit({ ...edit, namespaces: edit.namespaces.filter((_, i) => i !== index) })
                    }
                    aria-label={t('api_configurator.feeds.wizard.structure.ns_remove')}
                    className="grid h-9 w-9 shrink-0 place-items-center rounded-xl text-zinc-400 hover:bg-rose-50 hover:text-rose-600"
                  >
                    <Trash2 className="h-4 w-4" aria-hidden />
                  </button>
                </div>
              );
            })}
            <button
              type="button"
              onClick={() =>
                onEdit({ ...edit, namespaces: [...edit.namespaces, { prefix: '', uri: '' }] })
              }
              className="mt-2 flex items-center gap-1.5 rounded-xl border border-dashed border-zinc-300 px-3 py-1.5 text-[12px] font-medium text-zinc-600 hover:border-zinc-400 hover:text-zinc-900"
            >
              <Plus className="h-3.5 w-3.5" aria-hidden />
              {t('api_configurator.feeds.wizard.structure.ns_add')}
            </button>
          </div>
        </div>

        <div className="rounded-3xl bg-white p-6 soft-shadow">
          <div className="text-[14.5px] font-semibold tracking-tight">
            {t('api_configurator.feeds.wizard.structure.slots_title', {
              item: edit.itemElement || 'item',
            })}
          </div>
          <div className="mt-3 space-y-3">
            {edit.slots.map((slot, index) => (
              <SlotRow
                // biome-ignore lint/suspicious/noArrayIndexKey: rows are positional while edited
                key={index}
                slot={slot}
                nameIssue={issueFor('slot_name', index)}
                parentIssue={issueFor('slot_parent', index)}
                wrapIssue={issueFor('slot_wrap', index)}
                lengthIssue={issueFor('max_length', index)}
                onPatch={(patch) => patchSlot(index, patch)}
                onRemove={() =>
                  onEdit({ ...edit, slots: edit.slots.filter((_, i) => i !== index) })
                }
                t={t}
              />
            ))}
          </div>
          <button
            type="button"
            onClick={() => onEdit({ ...edit, slots: [...edit.slots, emptySlot()] })}
            className="mt-3 flex items-center gap-1.5 rounded-xl border border-dashed border-zinc-300 px-3 py-2 text-[12.5px] font-medium text-zinc-600 hover:border-zinc-400 hover:text-zinc-900"
          >
            <Plus className="h-4 w-4" aria-hidden />
            {t('api_configurator.feeds.wizard.structure.slot_add')}
          </button>
        </div>
      </div>

      <div className="rounded-3xl bg-zinc-900 p-5 text-zinc-100 soft-shadow lg:sticky lg:top-4 lg:self-start">
        <div className="text-[10.5px] font-medium uppercase tracking-wider text-zinc-400">
          {t('api_configurator.feeds.wizard.structure.preview_title')}
        </div>
        <pre className="mt-2 overflow-x-auto font-mono text-[11.5px] leading-6">
          {outline(edit)}
        </pre>
      </div>
    </div>
  );
}

function NameField({
  label,
  value,
  onChange,
  issue,
  t,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  issue: StructureIssue | undefined;
  t: (key: string, params?: Record<string, unknown>) => string;
}) {
  return (
    <div>
      <span className={LABEL_CLASS}>{label}</span>
      <input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-label={label}
        className={cn(FIELD_CLASS, 'mt-1.5', issue ? 'border-rose-400' : 'border-zinc-200')}
      />
      {issue && <IssueNote issue={issue} t={t} />}
    </div>
  );
}

function SlotRow({
  slot,
  nameIssue,
  parentIssue,
  wrapIssue,
  lengthIssue,
  onPatch,
  onRemove,
  t,
}: {
  slot: EditableSlot;
  nameIssue: StructureIssue | undefined;
  parentIssue: StructureIssue | undefined;
  wrapIssue: StructureIssue | undefined;
  lengthIssue: StructureIssue | undefined;
  onPatch: (patch: Partial<EditableSlot>) => void;
  onRemove: () => void;
  t: (key: string, params?: Record<string, unknown>) => string;
}) {
  const selectClass = cn(FIELD_CLASS, 'border-zinc-200 font-sans');
  const formats = SLOT_FORMATS.includes(slot.format as (typeof SLOT_FORMATS)[number])
    ? SLOT_FORMATS
    : ([...SLOT_FORMATS, slot.format] as readonly string[]);

  return (
    <div className="rounded-2xl border border-zinc-100 bg-zinc-50/50 p-3">
      <div className="grid grid-cols-2 items-start gap-2 md:grid-cols-[1.4fr_1fr_1fr_auto_5rem_auto]">
        <div>
          <input
            value={slot.name}
            onChange={(e) => onPatch({ name: e.target.value })}
            aria-label={t('api_configurator.feeds.wizard.structure.slot_name')}
            placeholder="price"
            className={cn(FIELD_CLASS, nameIssue ? 'border-rose-400' : 'border-zinc-200')}
          />
          {nameIssue && <IssueNote issue={nameIssue} t={t} />}
        </div>
        <select
          value={slot.node}
          onChange={(e) => onPatch({ node: e.target.value as EditableSlot['node'] })}
          aria-label={t('api_configurator.feeds.wizard.structure.node')}
          className={selectClass}
        >
          {SLOT_NODE_KINDS.map((kind) => (
            <option key={kind} value={kind}>
              {t(`api_configurator.feeds.wizard.structure.node_kind.${kind}`)}
            </option>
          ))}
        </select>
        <select
          value={slot.format}
          onChange={(e) => onPatch({ format: e.target.value })}
          aria-label={t('api_configurator.feeds.wizard.structure.format')}
          className={selectClass}
        >
          {formats.map((format) => (
            <option key={format} value={format}>
              {t(`api_configurator.feeds.wizard.structure.format_kind.${format}`, {
                defaultValue: format,
              })}
            </option>
          ))}
        </select>
        <label className="flex h-9 items-center gap-1.5 text-[12px] text-zinc-600">
          <input
            type="checkbox"
            checked={slot.required}
            onChange={(e) => onPatch({ required: e.target.checked })}
            className="h-3.5 w-3.5 rounded border-zinc-300"
          />
          {t('api_configurator.feeds.wizard.structure.required')}
        </label>
        <div>
          <input
            value={slot.maxLength}
            onChange={(e) => onPatch({ maxLength: e.target.value })}
            inputMode="numeric"
            aria-label={t('api_configurator.feeds.wizard.structure.max_length')}
            placeholder="max"
            className={cn(FIELD_CLASS, lengthIssue ? 'border-rose-400' : 'border-zinc-200')}
          />
          {lengthIssue && <IssueNote issue={lengthIssue} t={t} />}
        </div>
        <button
          type="button"
          onClick={onRemove}
          aria-label={t('api_configurator.feeds.wizard.structure.slot_remove')}
          className="grid h-9 w-9 place-items-center rounded-xl text-zinc-400 hover:bg-rose-50 hover:text-rose-600"
        >
          <Trash2 className="h-4 w-4" aria-hidden />
        </button>
      </div>
      {slot.node === 'attribute' && (
        <div className="mt-2 w-full md:w-64">
          <span className={LABEL_CLASS}>{t('api_configurator.feeds.wizard.structure.parent')}</span>
          <input
            value={slot.parent}
            onChange={(e) => onPatch({ parent: e.target.value })}
            aria-label={t('api_configurator.feeds.wizard.structure.parent')}
            placeholder="o"
            className={cn(FIELD_CLASS, 'mt-1', parentIssue ? 'border-rose-400' : 'border-zinc-200')}
          />
          {parentIssue && <IssueNote issue={parentIssue} t={t} />}
        </div>
      )}
      {slot.node === 'repeatable' && (
        <div className="mt-2 w-full md:w-64">
          <span className={LABEL_CLASS}>
            {t('api_configurator.feeds.wizard.structure.wrap_in')}
          </span>
          <input
            value={slot.wrapIn}
            onChange={(e) => onPatch({ wrapIn: e.target.value })}
            aria-label={t('api_configurator.feeds.wizard.structure.wrap_in')}
            placeholder="imgs"
            className={cn(FIELD_CLASS, 'mt-1', wrapIssue ? 'border-rose-400' : 'border-zinc-200')}
          />
          {wrapIssue && <IssueNote issue={wrapIssue} t={t} />}
        </div>
      )}
    </div>
  );
}

function IssueNote({
  issue,
  t,
}: {
  issue: StructureIssue;
  t: (key: string, params?: Record<string, unknown>) => string;
}) {
  return (
    <p className="mt-1 text-[11px] text-rose-600" role="alert">
      {t(`api_configurator.feeds.wizard.structure.issue.${issue.key}`, { value: issue.value })}
    </p>
  );
}

/** Plain-text XML outline of the edited structure for the preview panel. */
function outline(edit: EditableStructure): string {
  const ns = edit.namespaces
    .filter((n) => n.prefix.trim() !== '')
    .map((n) => ` xmlns:${n.prefix.trim()}="${n.uri.trim()}"`)
    .join('');
  const root = edit.rootElement.trim() || '?';
  const item = edit.itemElement.trim() || '?';
  const lines = [`<${root}${ns}>`, `  <${item}>`];
  for (const slot of edit.slots) {
    const name = slot.name.trim() || '?';
    if (slot.node === 'attribute') {
      lines.push(`    <${slot.parent.trim() || '?'} ${name}="…"/>`);
    } else if (slot.node === 'repeatable' && slot.wrapIn.trim() !== '') {
      lines.push(`    <${slot.wrapIn.trim()}><${name}>…</${name}>…</${slot.wrapIn.trim()}>`);
    } else {
      lines.push(`    <${name}>…</${name}>${slot.node === 'repeatable' ? ' ×n' : ''}`);
    }
  }
  lines.push(`  </${item}>`, `</${root}>`);
  return lines.join('\n');
}
