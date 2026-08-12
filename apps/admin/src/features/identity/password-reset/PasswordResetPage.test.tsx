import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PasswordResetPage } from '@/features/identity/password-reset/PasswordResetPage';
import { HttpError } from '@/lib/http';

const jsonFetch = vi.hoisted(() => vi.fn());
const navigate = vi.hoisted(() => vi.fn());
const toastSuccess = vi.hoisted(() => vi.fn());

vi.mock('@/lib/http', async () => {
  const actual = await vi.importActual<typeof import('@/lib/http')>('@/lib/http');
  return { ...actual, jsonFetch };
});

vi.mock('react-router', async () => {
  const actual = await vi.importActual<typeof import('react-router')>('react-router');
  return { ...actual, useNavigate: () => navigate };
});

vi.mock('@/components/ui/toast', () => ({
  toast: { success: toastSuccess, error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

const VALID_TOKEN = 'a'.repeat(64);

function renderPage(token: string) {
  return render(
    <MemoryRouter initialEntries={[`/password-reset?token=${token}`]}>
      <PasswordResetPage />
    </MemoryRouter>,
  );
}

async function fillPasswords(password: string, confirm = password) {
  const user = userEvent.setup();
  await user.type(screen.getByLabelText('Nowe hasło'), password);
  await user.type(screen.getByLabelText('Powtórz hasło'), confirm);
  return user;
}

/**
 * #2827 — the page the reset e-mail links to. There is no pre-flight verify
 * endpoint by design (it would be a public oracle for probing tokens), so
 * every "is this token any good?" answer arrives as a submit response.
 */
describe('PasswordResetPage', () => {
  beforeEach(() => {
    jsonFetch.mockReset();
    navigate.mockReset();
    toastSuccess.mockReset();
  });

  it('refuses a malformed token without offering the form', () => {
    renderPage('not-a-token');

    expect(screen.getByText('Link jest nieaktualny')).toBeInTheDocument();
    expect(screen.queryByLabelText('Nowe hasło')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Poproś o nowy link' })).toHaveAttribute(
      'href',
      '/forgot-password',
    );
  });

  it('blocks submission until the password is long enough and confirmed', async () => {
    renderPage(VALID_TOKEN);

    await fillPasswords('short', 'short');

    expect(screen.getByText('Hasło musi mieć co najmniej 12 znaków.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Zapisz hasło' })).toBeDisabled();
    expect(jsonFetch).not.toHaveBeenCalled();
  });

  it('flags mismatched confirmation', async () => {
    renderPage(VALID_TOKEN);

    await fillPasswords('correct-horse-battery', 'correct-horse-batteryX');

    expect(screen.getByText('Hasła nie są takie same.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Zapisz hasło' })).toBeDisabled();
  });

  it('sends the token with the new password and returns to sign-in', async () => {
    jsonFetch.mockResolvedValue({ status: 'password-updated' });
    renderPage(VALID_TOKEN);

    const user = await fillPasswords('correct-horse-battery');
    await user.click(screen.getByRole('button', { name: 'Zapisz hasło' }));

    await waitFor(() =>
      expect(jsonFetch).toHaveBeenCalledWith(
        '/api/auth/password-reset/confirm',
        expect.objectContaining({
          method: 'POST',
          body: { token: VALID_TOKEN, password: 'correct-horse-battery' },
        }),
      ),
    );
    expect(navigate).toHaveBeenCalledWith('/login', { replace: true });
    expect(toastSuccess).toHaveBeenCalled();
  });

  it('explains a spent or expired token instead of a generic failure', async () => {
    jsonFetch.mockRejectedValue(new HttpError(400, { detail: 'no longer valid' }));
    renderPage(VALID_TOKEN);

    const user = await fillPasswords('correct-horse-battery');
    await user.click(screen.getByRole('button', { name: 'Zapisz hasło' }));

    await waitFor(() => expect(screen.getByText('Link jest nieaktualny')).toBeInTheDocument());
    expect(navigate).not.toHaveBeenCalled();
  });
});
