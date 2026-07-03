import { useList } from '@refinedev/core';
import { Eye, EyeOff, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/components/ui/toast';
import { jsonFetch } from '@/lib/http';

import type { RoleListItem } from '../roles/types';
import type { UserListItem } from './types';

interface AddUserManuallyModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: () => void;
}

const MIN_PASSWORD_LENGTH = 12;

/**
 * Manual user creation (#867) — alternative to {@link InviteUserModal}.
 * Admin types email + password directly; user is created `STATUS_ACTIVE`
 * by `POST /api/users` and (when `force_password_change` is on) lands on
 * `/first-login-password` the next time they sign in.
 *
 * Form scope:
 *   - email (required)
 *   - display_name (optional — backend derives a fallback from the email)
 *   - role_code (required, single-select — multi-role assignment lives in
 *     the user detail page, same as the invite flow)
 *   - password (required, min {@link MIN_PASSWORD_LENGTH} chars, eye toggle)
 *   - force_password_change (default true) — flips
 *     `User.passwordChangeRequired`; the user must replace the admin-set
 *     password on first sign-in
 *   - send_welcome_email (default true) — backend renders
 *     `user_welcome.html.twig` without the password inline
 */
export function AddUserManuallyModal({ open, onOpenChange, onSuccess }: AddUserManuallyModalProps) {
  const { t } = useTranslation();
  const [email, setEmail] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [roleCode, setRoleCode] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [forcePasswordChange, setForcePasswordChange] = useState(true);
  const [sendWelcomeEmail, setSendWelcomeEmail] = useState(true);
  const [submitting, setSubmitting] = useState(false);

  const { result: rolesResult } = useList<RoleListItem>({
    resource: 'roles',
    pagination: { mode: 'off' },
  });
  const roles: RoleListItem[] = rolesResult?.data ?? [];

  const reset = () => {
    setEmail('');
    setDisplayName('');
    setRoleCode('');
    setPassword('');
    setShowPassword(false);
    setForcePasswordChange(true);
    setSendWelcomeEmail(true);
    setSubmitting(false);
  };

  const close = (next: boolean) => {
    if (!next) reset();
    onOpenChange(next);
  };

  const passwordTooShort = password.length > 0 && password.length < MIN_PASSWORD_LENGTH;
  const submitDisabled =
    submitting || !email.trim() || !roleCode || password.length < MIN_PASSWORD_LENGTH;

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (submitDisabled) return;
    setSubmitting(true);
    try {
      const created = await jsonFetch<UserListItem>('/api/users', {
        method: 'POST',
        body: {
          email: email.trim(),
          display_name: displayName.trim() || null,
          role_code: roleCode,
          password,
          force_password_change: forcePasswordChange,
          send_welcome_email: sendWelcomeEmail,
        },
        accept: 'application/json',
        contentType: 'application/json',
      });
      toast.success(
        t('settings.users.add_manually.success_toast', {
          email: created.email,
          defaultValue: 'Utworzono konto dla {{email}}',
        }),
      );
      onSuccess();
      close(false);
    } catch (error: unknown) {
      const status = (error as { status?: number; body?: { detail?: string } })?.status;
      const body = (error as { body?: { detail?: string } })?.body;
      if (status === 409) {
        toast.error(t('settings.users.add_manually.error_duplicate'));
      } else if (status === 400) {
        toast.error(body?.detail ?? t('settings.users.add_manually.error_validation'));
      } else if (status === 403) {
        toast.error(t('settings.users.add_manually.error_forbidden'));
      } else {
        toast.error(t('settings.users.add_manually.error_generic'));
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={close}>
      {/* DialogContent default has no height ceiling; the 6-field form +
          intro + 2 checkboxes + footer can outgrow viewports under 800px
          (≈half of laptop screens). Constrain to 90vh + push the form
          into a flex column so the body scrolls while header / footer
          stay pinned. */}
      <DialogContent className="flex max-h-[90vh] max-w-md flex-col overflow-hidden p-0">
        <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col overflow-hidden">
          <DialogHeader className="px-6 pb-2 pt-6">
            <div className="mb-2 inline-grid size-10 place-items-center rounded-full bg-zinc-900 text-white">
              <UserPlus className="size-5" aria-hidden />
            </div>
            <DialogTitle>{t('settings.users.add_manually.title')}</DialogTitle>
          </DialogHeader>
          <p className="px-6 text-sm text-muted-foreground">
            {t('settings.users.add_manually.intro')}
          </p>

          <div className="min-h-0 flex-1 space-y-3 overflow-y-auto px-6 py-3">
            <div className="space-y-1.5">
              <Label htmlFor="add-email">{t('settings.users.add_manually.field_email')}</Label>
              <Input
                id="add-email"
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="ada@example.com"
                autoComplete="email"
              />
              {/* DP-02 (#2032) — email is the login identifier; deliverability
                is never verified, so panel-managed accounts (warehouse staff,
                kiosk logins) work with a placeholder address as long as the
                welcome email stays unchecked. */}
              <p className="text-[11.5px] text-muted-foreground">
                {t('settings.users.add_manually.email_hint', {
                  defaultValue:
                    'Email pełni rolę loginu — nie musi być prawdziwą skrzynką (np. magazyn1@firma.pl). Przy adresie bez skrzynki odznacz wysyłkę welcome maila.',
                })}
              </p>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="add-display-name">
                {t('settings.users.add_manually.field_display_name')}
              </Label>
              <Input
                id="add-display-name"
                type="text"
                value={displayName}
                onChange={(e) => setDisplayName(e.target.value)}
                placeholder="Ada Kowalska"
                autoComplete="off"
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="add-role">{t('settings.users.add_manually.field_role')}</Label>
              <select
                id="add-role"
                required
                value={roleCode}
                onChange={(e) => setRoleCode(e.target.value)}
                className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <option value="">{t('settings.users.invite.field_role_placeholder')}</option>
                {roles.map((role) => (
                  <option key={role.id} value={role.code}>
                    {role.name}
                  </option>
                ))}
              </select>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="add-password">
                {t('settings.users.add_manually.field_password')}
              </Label>
              <div className="relative">
                <Input
                  id="add-password"
                  type={showPassword ? 'text' : 'password'}
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder={t('settings.users.add_manually.field_password_placeholder', {
                    defaultValue: 'Min. 12 znaków',
                  })}
                  autoComplete="new-password"
                  minLength={MIN_PASSWORD_LENGTH}
                  className="pr-10"
                  aria-invalid={passwordTooShort}
                  aria-describedby="add-password-hint"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((prev) => !prev)}
                  className="absolute right-2 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-700"
                  aria-label={
                    showPassword
                      ? t('settings.users.add_manually.password_hide', {
                          defaultValue: 'Ukryj hasło',
                        })
                      : t('settings.users.add_manually.password_show', {
                          defaultValue: 'Pokaż hasło',
                        })
                  }
                >
                  {showPassword ? (
                    <EyeOff className="size-4" aria-hidden />
                  ) : (
                    <Eye className="size-4" aria-hidden />
                  )}
                </button>
              </div>
              <p
                id="add-password-hint"
                className={
                  passwordTooShort
                    ? 'text-[11px] text-rose-600'
                    : 'text-[11px] text-muted-foreground'
                }
              >
                {t('settings.users.add_manually.field_password_hint', {
                  count: MIN_PASSWORD_LENGTH,
                  defaultValue: 'Min. {{count}} znaków. Hasło przekażesz użytkownikowi osobno.',
                })}
              </p>
            </div>

            <label className="flex items-start gap-2.5 rounded-md border bg-background px-3 py-2 text-sm">
              <input
                type="checkbox"
                className="mt-0.5 size-4"
                checked={forcePasswordChange}
                onChange={(e) => setForcePasswordChange(e.target.checked)}
              />
              <div className="flex-1 space-y-0.5">
                <div className="font-medium">
                  {t('settings.users.add_manually.force_password_change_label')}
                </div>
                <p className="text-[11px] text-muted-foreground">
                  {t('settings.users.add_manually.force_password_change_hint')}
                </p>
              </div>
            </label>

            <label className="flex items-start gap-2.5 rounded-md border bg-background px-3 py-2 text-sm">
              <input
                type="checkbox"
                className="mt-0.5 size-4"
                checked={sendWelcomeEmail}
                onChange={(e) => setSendWelcomeEmail(e.target.checked)}
              />
              <div className="flex-1 space-y-0.5">
                <div className="font-medium">
                  {t('settings.users.add_manually.send_welcome_email_label')}
                </div>
                <p className="text-[11px] text-muted-foreground">
                  {t('settings.users.add_manually.send_welcome_email_hint')}
                </p>
              </div>
            </label>
          </div>

          {/* Sticky footer — DialogFooter has no border by default, so add
              a top border + background to visually anchor it once the body
              starts scrolling. */}
          <DialogFooter className="shrink-0 border-t bg-background px-6 py-4">
            <Button
              type="button"
              variant="outline"
              onClick={() => close(false)}
              disabled={submitting}
            >
              {t('settings.users.add_manually.cancel')}
            </Button>
            <Button type="submit" disabled={submitDisabled}>
              {submitting
                ? t('settings.users.add_manually.submitting')
                : t('settings.users.add_manually.submit')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
