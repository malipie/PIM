import { type DataProvider, Refine } from '@refinedev/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { axe, toHaveNoViolations } from 'jest-axe';
import type { ReactElement } from 'react';
import { MemoryRouter } from 'react-router';
import { describe, expect, it, vi } from 'vitest';

import { type OutboundSource, OutboundSourceSection } from '../OutboundSourceSection';

expect.extend(toHaveNoViolations);

// #2667 — channels come from the Refine data context, locales from the
// workspace endpoint (raw react-query through Refine's QueryClientProvider).
vi.mock('@/lib/http', () => ({
  jsonFetch: vi.fn(async () => ({
    id: '0192f0aa-0000-7000-8000-0000000000aa',
    code: 'demo',
    name: 'Demo',
    plan: 'mvp',
    enabledLocales: ['pl', 'en'],
    primaryLocale: 'pl',
  })),
}));

const mockDataProvider = {
  getList: async () => ({
    data: [
      { id: '0192f0aa-0000-7000-8000-000000000001', code: 'baselinker', name: 'BaseLinker' },
      { id: '0192f0aa-0000-7000-8000-000000000002', code: 'allegro', name: 'Allegro' },
    ],
    total: 2,
  }),
  getOne: async () => ({ data: {} }),
  getMany: async () => ({ data: [] }),
  create: async () => ({ data: {} }),
  update: async () => ({ data: {} }),
  deleteOne: async () => ({ data: {} }),
  getApiUrl: () => 'http://test',
} as unknown as DataProvider;

function renderSection(ui: ReactElement) {
  return render(
    <MemoryRouter>
      <Refine dataProvider={mockDataProvider} options={{ disableTelemetry: true }}>
        {ui}
      </Refine>
    </MemoryRouter>,
  );
}

const empty: OutboundSource = { channel: '', locale: '' };

describe('OutboundSourceSection', () => {
  it('renders channel options from the API and locale options from the workspace', async () => {
    renderSection(<OutboundSourceSection value={empty} onChange={() => {}} />);

    expect(
      await screen.findByRole('option', { name: 'BaseLinker · baselinker' }),
    ).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Allegro · allegro' })).toBeInTheDocument();
    expect(await screen.findByRole('option', { name: 'EN' })).toBeInTheDocument();
  });

  it('emits the channel code and keeps the locale on channel change', async () => {
    const onChange = vi.fn();
    renderSection(
      <OutboundSourceSection value={{ channel: '', locale: 'en' }} onChange={onChange} />,
    );
    await screen.findByRole('option', { name: 'BaseLinker · baselinker' });

    fireEvent.change(screen.getByRole('combobox', { name: 'Kanał' }), {
      target: { value: 'baselinker' },
    });
    expect(onChange).toHaveBeenCalledWith({ channel: 'baselinker', locale: 'en' });
  });

  it('emits an empty channel for the global option', async () => {
    const onChange = vi.fn();
    renderSection(
      <OutboundSourceSection value={{ channel: 'baselinker', locale: '' }} onChange={onChange} />,
    );
    await screen.findByRole('option', { name: 'BaseLinker · baselinker' });

    fireEvent.change(screen.getByRole('combobox', { name: 'Kanał' }), { target: { value: '' } });
    expect(onChange).toHaveBeenCalledWith({ channel: '', locale: '' });
  });

  it('has no axe violations', async () => {
    const { container } = renderSection(
      <OutboundSourceSection value={empty} onChange={() => {}} />,
    );
    await screen.findByRole('option', { name: 'BaseLinker · baselinker' });
    expect(await axe(container)).toHaveNoViolations();
  });
});
