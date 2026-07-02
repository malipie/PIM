import { describe, expect, it } from 'vitest';

import {
  descriptorToEdit,
  editToDescriptor,
  emptySlot,
  structureIssues,
} from '../wizard/descriptor-edit';

/**
 * XMLF-P5-06 — the editable projection of a custom descriptor: round-trip
 * fidelity (untouched rule fields survive), and the inline validation
 * mirroring the backend guard (XML names, duplicates, attribute parent).
 */

const CUSTOM = {
  root: { element: 'products', namespaces: { g: 'http://base.google.com/ns/1.0' } },
  item: {
    element: 'product',
    slots: [
      { target: 'sku', node: 'element', element: 'sku', required: true, fmt: 'text' },
      {
        target: 'condition',
        node: 'element',
        element: 'condition',
        fmt: 'enum',
        enums: ['new', 'used'],
      },
    ],
  },
};

describe('descriptorToEdit / editToDescriptor', () => {
  it('round-trips a descriptor preserving rule fields the editor does not own', () => {
    const edit = descriptorToEdit(CUSTOM);
    expect(edit.rootElement).toBe('products');
    expect(edit.itemElement).toBe('product');
    expect(edit.namespaces).toEqual([{ prefix: 'g', uri: 'http://base.google.com/ns/1.0' }]);
    expect(edit.slots).toHaveLength(2);
    expect(edit.slots[1]?.format).toBe('enum');

    const back = editToDescriptor(edit, CUSTOM);
    const item = back.item as { slots: Array<Record<string, unknown>> };
    expect(item.slots[1]?.enums).toEqual(['new', 'used']);
    expect(item.slots[0]?.required).toBe(true);
    expect((back.root as Record<string, unknown>).namespaces).toEqual({
      g: 'http://base.google.com/ns/1.0',
    });
  });

  it('keeps the channel envelope of an RSS-shaped descriptor, replacing only its item', () => {
    const rss = {
      root: { element: 'rss', attributes: { version: '2.0' } },
      channel: {
        element: 'channel',
        header: [{ element: 'title', source: { kind: 'static', value: 'x' } }],
        item: { element: 'item', slots: [{ target: 'sku', node: 'element', element: 'sku' }] },
      },
    };
    const edit = descriptorToEdit(rss);
    expect(edit.itemElement).toBe('item');
    edit.slots[0] = { ...(edit.slots[0] ?? emptySlot()), name: 'id' };
    const back = editToDescriptor(edit, rss);
    const channel = back.channel as Record<string, unknown>;
    expect(channel.header).toEqual(rss.channel.header);
    expect((back.root as Record<string, unknown>).attributes).toEqual({ version: '2.0' });
    const item = channel.item as { slots: Array<Record<string, unknown>> };
    expect(item.slots[0]?.target).toBe('id');
    expect(back).not.toHaveProperty('item');
  });

  it('renaming a slot updates both target and element', () => {
    const edit = descriptorToEdit(CUSTOM);
    edit.slots[0] = { ...(edit.slots[0] ?? emptySlot()), name: 'ean' };
    const back = editToDescriptor(edit, CUSTOM);
    const slot = (back.item as { slots: Array<Record<string, unknown>> }).slots[0];
    expect(slot?.target).toBe('ean');
    expect(slot?.element).toBe('ean');
  });
});

describe('structureIssues', () => {
  it('accepts the valid starter', () => {
    expect(structureIssues(descriptorToEdit(CUSTOM))).toEqual([]);
  });

  it('flags illegal XML names on root, item, prefixes and slots', () => {
    const edit = descriptorToEdit(CUSTOM);
    edit.rootElement = '1products';
    edit.itemElement = 'pro duct';
    edit.namespaces[0] = { prefix: '1g', uri: 'u' };
    edit.slots[0] = { ...(edit.slots[0] ?? emptySlot()), name: 'description.pl' };
    const issues = structureIssues(edit);
    expect(issues.map((i) => i.field).sort()).toEqual(['item', 'namespace', 'root', 'slot_name']);
    expect(issues.every((i) => i.key === 'invalid_name')).toBe(true);
  });

  it('flags duplicate slot names and attribute nodes without a parent', () => {
    const edit = descriptorToEdit(CUSTOM);
    edit.slots.push({ ...emptySlot(), name: 'sku' });
    edit.slots.push({ ...emptySlot(), name: 'stock', node: 'attribute' });
    const issues = structureIssues(edit);
    expect(issues.some((i) => i.key === 'duplicate_slot' && i.slotIndex === 2)).toBe(true);
    expect(issues.some((i) => i.key === 'parent_required' && i.slotIndex === 3)).toBe(true);
  });

  it('flags a non-positive max length', () => {
    const edit = descriptorToEdit(CUSTOM);
    edit.slots[0] = { ...(edit.slots[0] ?? emptySlot()), maxLength: '0' };
    expect(structureIssues(edit).some((i) => i.key === 'invalid_max_length')).toBe(true);
  });
});
