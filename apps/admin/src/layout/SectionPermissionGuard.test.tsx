import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router';
import { describe, expect, it, vi } from 'vitest';

import { SectionPermissionGuard } from './SectionPermissionGuard';

const useIdentity = vi.hoisted(() => vi.fn());

vi.mock('@/lib/identity', async () => {
  const actual = await vi.importActual<typeof import('@/lib/identity')>('@/lib/identity');
  return { ...actual, useIdentity };
});

function renderAt(pathname: string) {
  return render(
    <MemoryRouter initialEntries={[pathname]}>
      <Routes>
        <Route element={<SectionPermissionGuard />}>
          <Route path="/modeling/object-types/new" element={<div>KREATOR TYPU</div>} />
          <Route path="/products" element={<div>LISTA PRODUKTÓW</div>} />
        </Route>
      </Routes>
    </MemoryRouter>,
  );
}

function asIdentity(codes: string[]) {
  return { identity: { permissions: new Set(codes) }, isLoading: false };
}

/**
 * #2830 — the guard that stops a URL from rendering a page the caller
 * cannot use. Before it, `/modeling/object-types/new` served the whole
 * object-type wizard to a Catalog Manager, who then hit HTTP 403 on save.
 */
describe('SectionPermissionGuard', () => {
  it('renders 403 instead of the page when the section is out of reach', () => {
    useIdentity.mockReturnValue(asIdentity(['products.view', 'categories.view']));

    renderAt('/modeling/object-types/new');

    expect(screen.queryByText('KREATOR TYPU')).not.toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument();
  });

  it('renders the page when the role holds the section code', () => {
    useIdentity.mockReturnValue(asIdentity(['modeling.view']));

    renderAt('/modeling/object-types/new');

    expect(screen.getByText('KREATOR TYPU')).toBeInTheDocument();
  });

  it('leaves ungated behaviour untouched for permitted sections', () => {
    useIdentity.mockReturnValue(asIdentity(['products.view']));

    renderAt('/products');

    expect(screen.getByText('LISTA PRODUKTÓW')).toBeInTheDocument();
  });

  it('does not flash 403 while identity is loading', () => {
    useIdentity.mockReturnValue({ identity: null, isLoading: true });

    renderAt('/modeling/object-types/new');

    expect(screen.getByText('KREATOR TYPU')).toBeInTheDocument();
  });
});
