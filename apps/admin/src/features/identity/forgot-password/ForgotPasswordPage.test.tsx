import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ForgotPasswordPage } from '@/features/identity/forgot-password/ForgotPasswordPage';
import { HttpError } from '@/lib/http';

const jsonFetch = vi.hoisted(() => vi.fn());

vi.mock('@/lib/http', async () => {
  const actual = await vi.importActual<typeof import('@/lib/http')>('@/lib/http');
  return { ...actual, jsonFetch };
});

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/forgot-password']}>
      <ForgotPasswordPage />
    </MemoryRouter>,
  );
}

async function submitEmail(address: string) {
  const user = userEvent.setup();
  await user.type(screen.getByLabelText('E-mail'), address);
  await user.click(screen.getByRole('button', { name: 'Wyślij link' }));
}

/**
 * #2827 — the reset request screen. Its whole job is to call the endpoint
 * and then say nothing about the outcome: the controller answers 200 for
 * unknown addresses precisely so the caller cannot enumerate accounts, and
 * a UI that rendered "no such account" would hand that back.
 */
describe('ForgotPasswordPage', () => {
  beforeEach(() => {
    jsonFetch.mockReset();
  });

  it('posts the address and confirms without revealing whether it exists', async () => {
    jsonFetch.mockResolvedValue({ status: 'sent' });
    renderPage();

    await submitEmail('known@example.com');

    await waitFor(() => expect(screen.getByText('Sprawdź skrzynkę')).toBeInTheDocument());
    expect(jsonFetch).toHaveBeenCalledWith(
      '/api/auth/password-reset/request',
      expect.objectContaining({ method: 'POST', body: { email: 'known@example.com' } }),
    );
  });

  it('shows the same confirmation when the request fails', async () => {
    // A 404/5xx must not become "that address is unknown" on screen.
    jsonFetch.mockRejectedValue(new HttpError(404, { detail: 'not found' }));
    renderPage();

    await submitEmail('unknown@example.com');

    await waitFor(() => expect(screen.getByText('Sprawdź skrzynkę')).toBeInTheDocument());
  });

  it('reports throttling instead of a false confirmation', async () => {
    jsonFetch.mockRejectedValue(new HttpError(429, { detail: 'too many' }));
    renderPage();

    await submitEmail('spammy@example.com');

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.queryByText('Sprawdź skrzynkę')).not.toBeInTheDocument();
  });
});
