/**
 * XMLF-P5-06 — the editable projection of a custom feed's descriptor
 * (FeedDescriptor VO, ADR-0023 §6.3). The UI edits this flat shape; the
 * builder folds it back into the canonical descriptor, preserving the parts
 * the editor does not touch (channel header, enums/html/requiredOneOf rules).
 * Validation mirrors the backend guard (XMLF-P1-04 / P2-07) so illegal names
 * and duplicates are caught inline instead of as a PATCH 400.
 */

/** Mirror of FeedSlot::XML_NAME — optional single prefix + NCName, no dots. */
export const XML_NAME = /^([A-Za-z_][A-Za-z0-9_-]*:)?[A-Za-z_][A-Za-z0-9_-]*$/;

export const SLOT_NODE_KINDS = ['element', 'attribute', 'repeatable', 'keyvalue'] as const;
export type SlotNodeKindId = (typeof SLOT_NODE_KINDS)[number];

/** Formats offered by the editor — enum/category need companion config the
 * structure step does not edit, so existing values are preserved but not
 * offered for new slots. */
export const SLOT_FORMATS = ['text', 'html', 'url', 'price', 'number', 'date'] as const;

export interface EditableSlot {
  /** Logical key == XML element name in the editor (custom feeds never split them). */
  name: string;
  node: SlotNodeKindId;
  parent: string;
  wrapIn: string;
  required: boolean;
  maxLength: string;
  format: string;
  /** Untouched rule fields (enums, html, requiredOneOf) carried through verbatim. */
  rest: Record<string, unknown>;
}

export interface EditableStructure {
  rootElement: string;
  namespaces: Array<{ prefix: string; uri: string }>;
  itemElement: string;
  slots: EditableSlot[];
}

export interface StructureIssue {
  /** i18n key under api_configurator.feeds.wizard.structure.issue.* */
  key: 'invalid_name' | 'duplicate_slot' | 'parent_required' | 'invalid_max_length';
  /** Which field the issue belongs to, for inline highlighting. */
  field: 'root' | 'item' | 'namespace' | 'slot_name' | 'slot_parent' | 'slot_wrap' | 'max_length';
  slotIndex?: number;
  namespaceIndex?: number;
  value: string;
}

const EDITED_KEYS = new Set([
  'target',
  'slot',
  'node',
  'element',
  'parent',
  'wrapIn',
  'required',
  'maxLength',
  'fmt',
]);

export function descriptorToEdit(descriptor: Record<string, unknown>): EditableStructure {
  const root = asRecord(descriptor.root);
  const channel = asRecord(descriptor.channel);
  const item = asRecord(channel?.item ?? descriptor.item);

  const namespaces = Object.entries(asRecord(root?.namespaces) ?? {}).map(([prefix, uri]) => ({
    prefix,
    uri: String(uri),
  }));

  const slots: EditableSlot[] = [];
  const rawSlots = Array.isArray(item?.slots) ? item.slots : [];
  for (const raw of rawSlots) {
    const slot = asRecord(raw);
    if (slot === null) {
      continue;
    }
    const rest: Record<string, unknown> = {};
    for (const [key, value] of Object.entries(slot)) {
      if (!EDITED_KEYS.has(key)) {
        rest[key] = value;
      }
    }
    slots.push({
      name: String(slot.element ?? slot.target ?? slot.slot ?? ''),
      node: (SLOT_NODE_KINDS as readonly string[]).includes(String(slot.node))
        ? (String(slot.node) as SlotNodeKindId)
        : 'element',
      parent: typeof slot.parent === 'string' ? slot.parent : '',
      wrapIn: typeof slot.wrapIn === 'string' ? slot.wrapIn : '',
      required: slot.required === true,
      maxLength: typeof slot.maxLength === 'number' ? String(slot.maxLength) : '',
      format: typeof slot.fmt === 'string' ? slot.fmt : 'text',
      rest,
    });
  }

  return {
    rootElement: String(root?.element ?? ''),
    namespaces,
    itemElement: String(item?.element ?? ''),
    slots,
  };
}

/**
 * Fold the edited structure back into a canonical descriptor. `original`
 * supplies everything the editor does not own: root attributes and the
 * optional channel envelope (kept verbatim, only its item is replaced).
 */
export function editToDescriptor(
  edit: EditableStructure,
  original: Record<string, unknown>,
): Record<string, unknown> {
  const originalRoot = asRecord(original.root) ?? {};
  const root: Record<string, unknown> = { ...originalRoot, element: edit.rootElement.trim() };
  const namespaces: Record<string, string> = {};
  for (const ns of edit.namespaces) {
    if (ns.prefix.trim() !== '') {
      namespaces[ns.prefix.trim()] = ns.uri.trim();
    }
  }
  if (Object.keys(namespaces).length > 0) {
    root.namespaces = namespaces;
  } else {
    delete root.namespaces;
  }

  const item = {
    element: edit.itemElement.trim(),
    slots: edit.slots.map((slot) => {
      const name = slot.name.trim();
      const out: Record<string, unknown> = {
        ...slot.rest,
        target: name,
        node: slot.node,
        element: name,
      };
      if (slot.node === 'attribute' && slot.parent.trim() !== '') {
        out.parent = slot.parent.trim();
      }
      if (slot.wrapIn.trim() !== '') {
        out.wrapIn = slot.wrapIn.trim();
      }
      if (slot.required) {
        out.required = true;
      }
      const maxLength = Number.parseInt(slot.maxLength, 10);
      if (slot.maxLength.trim() !== '' && Number.isFinite(maxLength)) {
        out.maxLength = maxLength;
      }
      if (slot.format !== '' && slot.format !== 'text') {
        out.fmt = slot.format;
      } else {
        out.fmt = 'text';
      }
      return out;
    }),
  };

  const originalChannel = asRecord(original.channel);
  if (originalChannel !== null) {
    return { ...original, root, channel: { ...originalChannel, item } };
  }
  return { ...original, root, item };
}

/** Inline validation matching InvalidDescriptorException cases the editor can cause. */
export function structureIssues(edit: EditableStructure): StructureIssue[] {
  const issues: StructureIssue[] = [];

  if (!XML_NAME.test(edit.rootElement.trim())) {
    issues.push({ key: 'invalid_name', field: 'root', value: edit.rootElement });
  }
  if (!XML_NAME.test(edit.itemElement.trim())) {
    issues.push({ key: 'invalid_name', field: 'item', value: edit.itemElement });
  }
  edit.namespaces.forEach((ns, index) => {
    if (!XML_NAME.test(ns.prefix.trim())) {
      issues.push({
        key: 'invalid_name',
        field: 'namespace',
        namespaceIndex: index,
        value: ns.prefix,
      });
    }
  });

  const seen = new Map<string, number>();
  edit.slots.forEach((slot, index) => {
    const name = slot.name.trim();
    if (!XML_NAME.test(name)) {
      issues.push({ key: 'invalid_name', field: 'slot_name', slotIndex: index, value: slot.name });
    } else if (seen.has(name)) {
      issues.push({ key: 'duplicate_slot', field: 'slot_name', slotIndex: index, value: name });
    } else {
      seen.set(name, index);
    }
    if (slot.node === 'attribute') {
      if (slot.parent.trim() === '') {
        issues.push({ key: 'parent_required', field: 'slot_parent', slotIndex: index, value: '' });
      } else if (!XML_NAME.test(slot.parent.trim())) {
        issues.push({
          key: 'invalid_name',
          field: 'slot_parent',
          slotIndex: index,
          value: slot.parent,
        });
      }
    }
    if (slot.wrapIn.trim() !== '' && !XML_NAME.test(slot.wrapIn.trim())) {
      issues.push({
        key: 'invalid_name',
        field: 'slot_wrap',
        slotIndex: index,
        value: slot.wrapIn,
      });
    }
    if (slot.maxLength.trim() !== '') {
      const parsed = Number.parseInt(slot.maxLength, 10);
      if (!Number.isFinite(parsed) || parsed < 1) {
        issues.push({
          key: 'invalid_max_length',
          field: 'max_length',
          slotIndex: index,
          value: slot.maxLength,
        });
      }
    }
  });

  return issues;
}

export function emptySlot(): EditableSlot {
  return {
    name: '',
    node: 'element',
    parent: '',
    wrapIn: '',
    required: false,
    maxLength: '',
    format: 'text',
    rest: {},
  };
}

function asRecord(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null;
}
