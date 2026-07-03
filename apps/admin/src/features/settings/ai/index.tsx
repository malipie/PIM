import { useQuery, useQueryClient } from '@tanstack/react-query';
import { KeyRound, Loader2, ShieldCheck, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import { httpErrorDetail, jsonFetch } from '@/lib/http';

interface AgentKeyStatus {
  agent_feature_enabled: boolean;
  configured: boolean;
  enabled: boolean;
  key_prefix: string | null;
  enabled_at: string | null;
  disabled_at: string | null;
  last_used_at: string | null;
}

const JSON_OPTS = { contentType: 'application/json', accept: 'application/json' } as const;

const AGENT_KEY_QUERY_KEY = ['settings', 'agent-key'] as const;

/**
 * AGENT-P6-06 (#1979) — BYOK settings (Piotr, PRD §4.2): set/rotate the
 * tenant's Anthropic key (plaintext never comes back - only the display
 * prefix + timestamps), soft-disable as the per-tenant agent-off
 * toggle, and the §10.3 transparency copy explaining what leaves the
 * system towards the model.
 */
export function AiSettingsPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [draftKey, setDraftKey] = useState('');
  const [busy, setBusy] = useState(false);

  // ADR-0021 — the status read lives in the query cache so the PUT /
  // DELETE mutations refresh it through invalidation, not manual state.
  const statusQuery = useQuery({
    queryKey: AGENT_KEY_QUERY_KEY,
    queryFn: () => jsonFetch<AgentKeyStatus>('/api/settings/agent-key', JSON_OPTS),
  });
  const status = statusQuery.data ?? null;

  const reload = async () => {
    await queryClient.invalidateQueries({ queryKey: AGENT_KEY_QUERY_KEY });
  };

  const saveKey = async () => {
    const key = draftKey.trim();
    if (key === '' || busy) return;
    setBusy(true);
    try {
      await jsonFetch('/api/settings/agent-key', {
        ...JSON_OPTS,
        method: 'PUT',
        body: { api_key: key },
      });
      setDraftKey('');
      toast.success(
        t('agent.settings.saved', { defaultValue: 'Klucz zapisany — agent włączony.' }),
      );
      await reload();
    } catch (error) {
      toast.error(httpErrorDetail(error) ?? String(error));
    } finally {
      setBusy(false);
    }
  };

  const disable = async () => {
    setBusy(true);
    try {
      await jsonFetch('/api/settings/agent-key', { ...JSON_OPTS, method: 'DELETE' });
      toast.success(
        t('agent.settings.disabled', { defaultValue: 'Agent wyłączony dla tego tenanta.' }),
      );
      await reload();
    } catch (error) {
      toast.error(httpErrorDetail(error) ?? String(error));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="max-w-2xl space-y-6" data-testid="agent-settings">
      <div>
        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
          <Sparkles className="size-5 text-purple-600" aria-hidden />
          {t('agent.settings.title', { defaultValue: 'AI / Agent' })}
        </h1>
        <p className="text-sm text-zinc-500">
          {t('agent.settings.subtitle', {
            defaultValue:
              'Agent działa na Twoim kluczu Anthropic (BYOK). Bez aktywnego klucza agent jest wyłączony.',
          })}
        </p>
      </div>

      {status === null ? (
        statusQuery.isError ? (
          <p className="text-sm text-red-600" role="alert">
            {httpErrorDetail(statusQuery.error) ?? String(statusQuery.error)}
          </p>
        ) : (
          <p className="flex items-center gap-2 text-sm text-zinc-500" role="status">
            <Loader2 className="size-4 animate-spin" aria-hidden />
            {t('agent.settings.loading', { defaultValue: 'Wczytywanie…' })}
          </p>
        )
      ) : (
        <>
          {!status.agent_feature_enabled && (
            <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
              {t('agent.settings.feature_off', {
                defaultValue:
                  'Warstwa agenta jest globalnie wyłączona (AGENT_ENABLED) — klucz można skonfigurować, ale runy nie wystartują.',
              })}
            </p>
          )}

          <section
            className="rounded-xl border border-zinc-200 bg-white p-4"
            aria-labelledby="agent-key-heading"
          >
            <h2 id="agent-key-heading" className="flex items-center gap-2 text-sm font-semibold">
              <KeyRound className="size-4 text-zinc-500" aria-hidden />
              {t('agent.settings.key_heading', { defaultValue: 'Klucz Anthropic' })}
            </h2>

            <dl className="mt-3 grid grid-cols-2 gap-2 text-sm">
              <dt className="text-zinc-500">
                {t('agent.settings.state', { defaultValue: 'Stan' })}
              </dt>
              <dd data-testid="agent-key-state">
                {status.enabled
                  ? t('agent.settings.state_on', { defaultValue: 'Aktywny — agent włączony' })
                  : status.configured
                    ? t('agent.settings.state_disabled', { defaultValue: 'Wyłączony (soft-off)' })
                    : t('agent.settings.state_missing', {
                        defaultValue: 'Brak klucza — agent wymaga klucza',
                      })}
              </dd>
              {status.key_prefix !== null && (
                <>
                  <dt className="text-zinc-500">
                    {t('agent.settings.prefix', { defaultValue: 'Prefiks' })}
                  </dt>
                  <dd className="font-mono" data-testid="agent-key-prefix">
                    {status.key_prefix}…
                  </dd>
                </>
              )}
              {status.last_used_at !== null && (
                <>
                  <dt className="text-zinc-500">
                    {t('agent.settings.last_used', { defaultValue: 'Ostatnio użyty' })}
                  </dt>
                  <dd>{new Date(status.last_used_at).toLocaleString()}</dd>
                </>
              )}
            </dl>

            <form
              className="mt-4 flex items-end gap-2"
              onSubmit={(event) => {
                event.preventDefault();
                void saveKey();
              }}
            >
              <label className="flex-1 text-sm">
                <span className="mb-1 block text-zinc-500">
                  {status.configured
                    ? t('agent.settings.rotate_label', {
                        defaultValue: 'Nowy klucz (rotacja zastępuje obecny)',
                      })
                    : t('agent.settings.set_label', { defaultValue: 'Klucz API (sk-ant-…)' })}
                </span>
                <input
                  type="password"
                  value={draftKey}
                  onChange={(event) => setDraftKey(event.target.value)}
                  autoComplete="off"
                  placeholder="sk-ant-api03-…"
                  className="w-full rounded-md border border-zinc-200 px-3 py-2 font-mono text-sm focus:outline-none focus:ring-2 focus:ring-zinc-400"
                  data-testid="agent-key-input"
                />
              </label>
              <Button
                type="submit"
                disabled={busy || draftKey.trim() === ''}
                data-testid="agent-key-save"
              >
                {busy ? (
                  <Loader2 className="size-4 animate-spin" />
                ) : (
                  t('agent.settings.save', { defaultValue: 'Zapisz' })
                )}
              </Button>
              {status.enabled && (
                <Button
                  type="button"
                  variant="outline"
                  disabled={busy}
                  onClick={() => void disable()}
                  data-testid="agent-key-disable"
                >
                  {t('agent.settings.disable', { defaultValue: 'Wyłącz agenta' })}
                </Button>
              )}
            </form>
            <p className="mt-2 text-xs text-zinc-500">
              {t('agent.settings.never_plaintext', {
                defaultValue:
                  'Klucz jest szyfrowany (AES-256-GCM) i nigdy nie wraca w odpowiedziach — widzisz tylko prefiks.',
              })}
            </p>
          </section>

          <section
            className="rounded-xl border border-zinc-200 bg-white p-4"
            aria-labelledby="agent-transparency-heading"
          >
            <h2
              id="agent-transparency-heading"
              className="flex items-center gap-2 text-sm font-semibold"
            >
              <ShieldCheck className="size-4 text-zinc-500" aria-hidden />
              {t('agent.settings.transparency_heading', { defaultValue: 'Co trafia do modelu' })}
            </h2>
            <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-zinc-600">
              <li>
                {t('agent.settings.transparency_intent', {
                  defaultValue: 'Twoja intencja i kolejne wiadomości rozmowy.',
                })}
              </li>
              <li>
                {t('agent.settings.transparency_context', {
                  defaultValue:
                    'Kontekst widoku: kod typu obiektu, aktywny filtr, liczność selekcji (bez pełnych danych produktów).',
                })}
              </li>
              <li>
                {t('agent.settings.transparency_results', {
                  defaultValue:
                    'Wyniki narzędzi: liczniki, kody i minimalne projekcje (id, kod, nazwa, kompletność) — tylko to, co narzędzie zwróci.',
                })}
              </li>
              <li>
                {t('agent.settings.transparency_never', {
                  defaultValue:
                    'Nigdy: Twój klucz, hasła, tokeny API ani dane innych tenantów. Zapis do katalogu wyłącznie po Twojej akceptacji.',
                })}
              </li>
            </ul>
          </section>
        </>
      )}
    </div>
  );
}
