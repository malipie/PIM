import { KeyRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/components/ui/toast';
import { HttpError, httpErrorDetail, jsonFetch } from '@/lib/http';

import type { UserListItem } from './types';

/**
 * DP-02 (#2032) — admin sets a new password for a user directly from the
 * panel (`POST /api/users/{id}/password`). Closes the lifecycle gap for
 * accounts whose email is a login identifier without a real mailbox — the
 * magic-link reset cannot reach them. Backend revokes the target's
 * refresh tokens on success; `force_password_change` (default on) makes
 * the user replace the admin-set password at first login.
 */
export function SetPasswordModal({
  user,
  open,
  onOpenChange,
  onDone,
}: {
  user: UserListItem;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onDone?: () => void;
}) {
  const { t } = useTranslation();
  const [password, setPassword] = useState('');
  const [forceChange, setForceChange] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setPassword('');
      setForceChange(true);
      setError(null);
    }
  }, [open]);

  const submit = async () => {
    if (password.length < 12 || submitting) return;
    setSubmitting(true);
    setError(null);
    try {
      await jsonFetch(`/api/users/${user.id}/password`, {
        method: 'POST',
        contentType: 'application/json',
        accept: 'application/json',
        body: { password, force_password_change: forceChange },
      });
      toast.success(
        t('settings.users.set_password.toast_success', {
          defaultValue: 'Hasło ustawione. Sesje użytkownika zostały unieważnione.',
        }),
      );
      onOpenChange(false);
      onDone?.();
    } catch (err) {
      setError(
        (err instanceof HttpError ? httpErrorDetail(err) : null) ??
          t('settings.users.set_password.error_generic', {
            defaultValue: 'Nie udało się ustawić hasła.',
          }),
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-[440px] gap-0 p-0">
        <div className="border-b border-zinc-100 px-6 pb-4 pt-5">
          <div className="flex items-center gap-2">
            <KeyRound className="size-4 text-zinc-500" />
            <h2 className="text-[15px] font-semibold tracking-tight">
              {t('settings.users.set_password.title', { defaultValue: 'Ustaw nowe hasło' })}
            </h2>
          </div>
          <p className="mt-1 text-[12.5px] text-zinc-500">
            {t('settings.users.set_password.description', {
              defaultValue:
                'Nowe hasło dla {{email}}. Wszystkie aktywne sesje użytkownika zostaną unieważnione.',
              email: user.email,
            })}
          </p>
        </div>

        <div className="space-y-4 px-6 py-4">
          <div>
            <Label
              className="text-[11.5px] font-medium text-muted-foreground"
              htmlFor="set-password-input"
            >
              {t('settings.users.set_password.password_label', {
                defaultValue: 'Nowe hasło (min. 12 znaków)',
              })}
            </Label>
            <Input
              id="set-password-input"
              type="password"
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              minLength={12}
              className="mt-1.5 h-10"
              onKeyDown={(e) => {
                if (e.key === 'Enter') void submit();
              }}
            />
          </div>
          <label className="flex items-center gap-2 text-[12.5px] text-zinc-700">
            <input
              type="checkbox"
              checked={forceChange}
              onChange={(e) => setForceChange(e.target.checked)}
              className="size-3.5 accent-zinc-900"
            />
            {t('settings.users.set_password.force_change_label', {
              defaultValue: 'Wymuś zmianę hasła przy pierwszym logowaniu',
            })}
          </label>
          {error !== null ? (
            <p className="rounded-md border border-destructive/50 bg-destructive/5 px-3 py-2 text-[12.5px] text-destructive">
              {error}
            </p>
          ) : null}
        </div>

        <div className="flex items-center justify-end gap-2 border-t border-zinc-100 px-6 py-4">
          <Button variant="ghost" size="sm" onClick={() => onOpenChange(false)}>
            {t('app.cancel', { defaultValue: 'Anuluj' })}
          </Button>
          <Button
            size="sm"
            disabled={password.length < 12 || submitting}
            onClick={() => void submit()}
            className="rounded-xl bg-zinc-900 hover:bg-zinc-800"
          >
            {t('settings.users.set_password.cta', { defaultValue: 'Ustaw hasło' })}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
