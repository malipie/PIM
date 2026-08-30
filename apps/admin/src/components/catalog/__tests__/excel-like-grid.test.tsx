import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { type ExcelColumn, ExcelLikeGrid } from '../excel-like-grid';

/**
 * AUD-038 (#1611) — column virtualization for ExcelLikeGrid.
 *
 * jsdom has no layout engine (every element reports size 0), so
 * `@tanstack/react-virtual` is fed a deterministic viewport: a 500px-wide
 * scroll container over many 160px columns. With overscan that mounts a
 * single-digit subset of the 60 columns — proving windowing — while the
 * editing / keyboard behaviours the grid had regressions on (UI-02) are
 * re-asserted to stay intact.
 */

interface Row extends Record<string, unknown> {
  [key: `c${number}`]: string;
}

const COLUMN_COUNT = 60;
const COLUMN_WIDTH = 160;
const VIEWPORT_WIDTH = 500;

const columns: ExcelColumn<Row>[] = Array.from({ length: COLUMN_COUNT }, (_, i) => ({
  key: `c${i}` as keyof Row & string,
  label: `Col ${i}`,
  type: 'text' as const,
  width: COLUMN_WIDTH,
}));

const rows: Row[] = Array.from({ length: 5 }, (_, r) => {
  const row = {} as Row;
  for (let c = 0; c < COLUMN_COUNT; c += 1) {
    row[`c${c}` as keyof Row & string] = `r${r}-c${c}`;
  }
  return row;
});

/**
 * Give the virtualizer a finite viewport. `@tanstack/virtual-core` reads the
 * scroll element's `offsetWidth` (horizontal mode) and re-measures via
 * ResizeObserver; jsdom reports 0 for both and never fires the observer, so
 * the column window would otherwise be empty. We stub `offsetWidth` on the
 * scroll container and fire a one-shot ResizeObserver callback with its
 * borderBoxSize so the virtualizer materialises a real window on mount.
 */
function mockLayout(): void {
  // Parameter properties are disallowed under `erasableSyntaxOnly`, so the
  // callback is held in a plain field.
  class StubResizeObserver {
    private readonly cb: ResizeObserverCallback;
    constructor(cb: ResizeObserverCallback) {
      this.cb = cb;
    }
    observe(target: Element): void {
      // Report the viewport size for the scroll container immediately.
      if (target instanceof HTMLElement && target.classList.contains('overflow-x-auto')) {
        this.cb(
          [
            {
              target,
              borderBoxSize: [{ inlineSize: VIEWPORT_WIDTH, blockSize: 0 }],
              contentBoxSize: [{ inlineSize: VIEWPORT_WIDTH, blockSize: 0 }],
              contentRect: { width: VIEWPORT_WIDTH, height: 0 } as DOMRectReadOnly,
              devicePixelContentBoxSize: [{ inlineSize: VIEWPORT_WIDTH, blockSize: 0 }],
            } as unknown as ResizeObserverEntry,
          ],
          this as unknown as ResizeObserver,
        );
      }
    }
    unobserve(): void {}
    disconnect(): void {}
  }
  vi.stubGlobal('ResizeObserver', StubResizeObserver);

  // offsetWidth/offsetHeight are non-configurable accessors in jsdom; redefine
  // them so the scroll container reports a finite width.
  Object.defineProperty(HTMLElement.prototype, 'offsetWidth', {
    configurable: true,
    get(this: HTMLElement) {
      return this.classList.contains('overflow-x-auto') ? VIEWPORT_WIDTH : 0;
    },
  });
  Object.defineProperty(HTMLElement.prototype, 'offsetHeight', {
    configurable: true,
    get() {
      return 0;
    },
  });
}

describe('ExcelLikeGrid column virtualization', () => {
  beforeEach(() => {
    mockLayout();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    // The offsetWidth/offsetHeight accessors are defined straight on the
    // prototype, so restoreAllMocks does not clear them.
    Reflect.deleteProperty(HTMLElement.prototype, 'offsetWidth');
    Reflect.deleteProperty(HTMLElement.prototype, 'offsetHeight');
  });

  it('windows columns — renders only a subset of a wide ObjectType', () => {
    render(<ExcelLikeGrid<Row> rows={rows} columns={columns} onCommit={() => {}} />);

    const headers = screen
      .getAllByRole('columnheader')
      .filter((h) => /^Col \d+$/.test(h.textContent ?? ''));
    // 500px / 160px ≈ 4 visible + overscan → far fewer than the 60 total.
    expect(headers.length).toBeGreaterThan(0);
    expect(headers.length).toBeLessThan(COLUMN_COUNT);

    // The very last column is well outside the initial viewport: not mounted.
    expect(screen.queryByText(`Col ${COLUMN_COUNT - 1}`)).not.toBeInTheDocument();
    // The first columns are in view.
    expect(screen.getByText('Col 0')).toBeInTheDocument();
  });

  it('renders body cells only for the windowed columns', () => {
    render(<ExcelLikeGrid<Row> rows={rows} columns={columns} onCommit={() => {}} />);

    // First row's first cell is visible; a far-right cell is windowed out.
    expect(screen.getByText('r0-c0')).toBeInTheDocument();
    expect(screen.queryByText(`r0-c${COLUMN_COUNT - 1}`)).not.toBeInTheDocument();
  });

  it('still edits a visible cell on single click and commits on Enter', async () => {
    const user = userEvent.setup();
    const onCommit = vi.fn();
    render(<ExcelLikeGrid<Row> rows={rows} columns={columns} onCommit={onCommit} />);

    const cell = screen.getByText('r0-c0');
    await user.click(cell);

    const editor = screen.getByDisplayValue('r0-c0');
    expect(editor).toBeInTheDocument();

    await user.clear(editor);
    await user.type(editor, 'edited{Enter}');
    expect(onCommit).toHaveBeenCalledWith(0, 'c0', 'edited');
  });

  it('keeps keyboard navigation: Enter re-opens the editor on the active cell', async () => {
    const user = userEvent.setup();
    render(<ExcelLikeGrid<Row> rows={rows} columns={columns} onCommit={() => {}} />);

    // Click establishes the active cell AND opens its editor (single-click UX).
    await user.click(screen.getByText('r1-c1'));
    expect(screen.getByDisplayValue('r1-c1')).toBeInTheDocument();

    // Escape closes the editor but the cell stays active.
    await user.keyboard('{Escape}');
    expect(screen.queryByDisplayValue('r1-c1')).not.toBeInTheDocument();

    // Enter on the table (handleKeyDown) must re-open the editor on the
    // active cell — the keyboard-nav path the grid had regressed on.
    const table = screen.getByRole('table');
    fireEvent.keyDown(table, { key: 'Enter' });
    expect(within(table).getByDisplayValue('r1-c1')).toBeInTheDocument();
  });

  it('uses the latest paste report callback after a rerender', async () => {
    const user = userEvent.setup();
    const firstOnPasteReport = vi.fn();
    const latestOnPasteReport = vi.fn();
    const onCommit = vi.fn();
    const { rerender } = render(
      <ExcelLikeGrid<Row>
        rows={rows}
        columns={columns}
        onCommit={onCommit}
        onPasteReport={firstOnPasteReport}
      />,
    );

    await user.click(screen.getByText('r0-c0'));
    await user.keyboard('{Escape}');
    rerender(
      <ExcelLikeGrid<Row>
        rows={rows}
        columns={columns}
        onCommit={onCommit}
        onPasteReport={latestOnPasteReport}
      />,
    );

    vi.spyOn(navigator.clipboard, 'readText').mockResolvedValue('pasted');
    fireEvent.keyDown(screen.getByRole('table'), { key: 'v', ctrlKey: true });

    await waitFor(() => expect(latestOnPasteReport).toHaveBeenCalledWith(1, 0));
    expect(firstOnPasteReport).not.toHaveBeenCalled();
    expect(onCommit).toHaveBeenCalledWith(0, 'c0', 'pasted');
  });
});
