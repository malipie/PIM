import { describe, expect, it } from 'vitest';

import { buildExportScopePayload } from './export-scope-payload';
import { INITIAL_WIZARD_STATE } from './types';
import { buildExportPayload, isDownloadableContentType } from './use-run-export';

describe('isDownloadableContentType', () => {
  it('accepts the file each format actually returns', () => {
    expect(
      isDownloadableContentType(
        'xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      ),
    ).toBe(true);
    expect(isDownloadableContentType('csv', 'text/csv; charset=windows-1250')).toBe(true);
    // #2945 — the reported bug: a valid XML export was rejected by a guard
    // that only knew about xlsx and csv, and the operator was shown the first
    // 300 characters of their own file as an error.
    expect(isDownloadableContentType('xml', 'application/xml; charset=utf-8')).toBe(true);
    expect(isDownloadableContentType('xml', 'text/xml')).toBe(true);
  });

  it('still rejects an error body wearing an ok status', () => {
    expect(isDownloadableContentType('xlsx', 'text/html; charset=UTF-8')).toBe(false);
    expect(isDownloadableContentType('csv', 'application/problem+json')).toBe(false);
    expect(isDownloadableContentType('xml', 'text/html')).toBe(false);
  });

  it('does not let one format accept another format s body', () => {
    // A CSV arriving where xlsx was asked for means something upstream
    // ignored the request; downloading it as .xlsx produces a corrupt file.
    expect(isDownloadableContentType('xlsx', 'text/csv')).toBe(false);
    expect(isDownloadableContentType('csv', 'application/xml')).toBe(false);
  });
});

describe('export scope contract', () => {
  it('uses the same selected tree scope for preflight and file execution', () => {
    const state = {
      ...INITIAL_WIZARD_STATE,
      targetScope: 'selected' as const,
      selectedIds: ['019eae00-0000-7000-8000-000000000001'],
      includeVariants: false,
      columns: ['sku'],
    };

    expect(buildExportPayload(state)).toMatchObject(buildExportScopePayload(state));
    expect(buildExportPayload(state)).toMatchObject({
      target_scope: 'selected',
      selected_object_ids: state.selectedIds,
      include_variants: false,
    });
  });
});
