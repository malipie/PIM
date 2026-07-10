import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, Star, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/components/ui/toast';
import { jsonFetch } from '@/lib/http';
import { useCanI } from '@/lib/identity/use-identity';

/**
 * AICG-P5-05 (#2343) — BrandVoiceProfile CRUD in Settings → AI: tone,
 * imposed glossary, banned words, good/bad examples and the tenant
 * default (setting a new default clears the previous one — the swap is
 * transactional server-side, P1-03).
 */

export interface BrandVoiceRow {
  id: string;
  name: string;
  tone: string;
  glossary: Array<{ term: string; use: string }>;
  bannedWords: string[];
  examples: Array<{ good: string; bad: string }>;
  default: boolean;
}

interface VoiceDraft {
  id: string | null;
  name: string;
  tone: string;
  glossaryText: string;
  bannedWordsText: string;
  exampleGood: string;
  exampleBad: string;
  isDefault: boolean;
}

const EMPTY_DRAFT: VoiceDraft = {
  id: null,
  name: '',
  tone: '',
  glossaryText: '',
  bannedWordsText: '',
  exampleGood: '',
  exampleBad: '',
  isDefault: false,
};

function collection<T>(payload: { member?: T[]; 'hydra:member'?: T[] }): T[] {
  return payload.member ?? payload['hydra:member'] ?? [];
}

function toDraft(voice: BrandVoiceRow): VoiceDraft {
  return {
    id: voice.id,
    name: voice.name,
    tone: voice.tone,
    glossaryText: voice.glossary.map((entry) => `${entry.term} => ${entry.use}`).join('\n'),
    bannedWordsText: voice.bannedWords.join(', '),
    exampleGood: voice.examples[0]?.good ?? '',
    exampleBad: voice.examples[0]?.bad ?? '',
    isDefault: voice.default,
  };
}

function draftBody(draft: VoiceDraft): Record<string, unknown> {
  const glossary = draft.glossaryText
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line.includes('=>'))
    .map((line) => {
      const [term, use] = line.split('=>', 2);
      return { term: (term ?? '').trim(), use: (use ?? '').trim() };
    })
    .filter((entry) => entry.term !== '');
  const bannedWords = draft.bannedWordsText
    .split(',')
    .map((word) => word.trim())
    .filter((word) => word !== '');
  const examples =
    draft.exampleGood.trim() !== '' || draft.exampleBad.trim() !== ''
      ? [{ good: draft.exampleGood.trim(), bad: draft.exampleBad.trim() }]
      : [];
  return {
    name: draft.name,
    tone: draft.tone,
    glossary,
    bannedWords,
    examples,
    isDefault: draft.isDefault,
  };
}

export function BrandVoiceSection() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const canAdmin = useCanI('settings.ai_content.admin');
  const canCreate = useCanI('settings.ai_content.create');
  const [draft, setDraft] = useState<VoiceDraft | null>(null);

  const voicesQuery = useQuery({
    queryKey: ['brand-voice-profiles'],
    queryFn: () => jsonFetch<{ member?: BrandVoiceRow[] }>('/api/brand-voice-profiles'),
  });
  const voices = voicesQuery.data ? collection(voicesQuery.data) : [];

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['brand-voice-profiles'] });

  const saveMutation = useMutation({
    mutationFn: async (current: VoiceDraft) => {
      if (current.id === null) {
        return jsonFetch('/api/brand-voice-profiles', {
          method: 'POST',
          contentType: 'application/ld+json',
          body: draftBody(current),
        });
      }
      return jsonFetch(`/api/brand-voice-profiles/${current.id}`, {
        method: 'PATCH',
        contentType: 'application/merge-patch+json',
        body: draftBody(current),
      });
    },
    onSuccess: async () => {
      toast.success(t('aicg.settings.voice_saved', { defaultValue: 'Głos marki zapisany.' }));
      setDraft(null);
      await invalidate();
    },
    onError: (error) => toast.error(error instanceof Error ? error.message : String(error)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => jsonFetch(`/api/brand-voice-profiles/${id}`, { method: 'DELETE' }),
    onSuccess: async () => {
      toast.success(t('aicg.settings.voice_deleted', { defaultValue: 'Głos marki usunięty.' }));
      await invalidate();
    },
    onError: (error) => toast.error(error instanceof Error ? error.message : String(error)),
  });

  const setDefaultMutation = useMutation({
    mutationFn: (id: string) =>
      jsonFetch(`/api/brand-voice-profiles/${id}`, {
        method: 'PATCH',
        contentType: 'application/merge-patch+json',
        body: { isDefault: true },
      }),
    onSuccess: async () => {
      toast.success(
        t('aicg.settings.voice_default_set', { defaultValue: 'Ustawiono głos domyślny.' }),
      );
      await invalidate();
    },
    onError: (error) => toast.error(error instanceof Error ? error.message : String(error)),
  });

  return (
    <section
      className="rounded-2xl border border-zinc-200 bg-white p-5"
      data-testid="brand-voice-section"
    >
      <div className="mb-3 flex items-center justify-between">
        <div>
          <h2 className="text-[15px] font-semibold">
            {t('aicg.settings.voices_title', { defaultValue: 'Głos marki' })}
          </h2>
          <p className="text-[12.5px] text-zinc-500">
            {t('aicg.settings.voices_subtitle', {
              defaultValue: 'Spójny ton na skalę: glosariusz, słowa zakazane, przykłady tak/nie.',
            })}
          </p>
        </div>
        {canCreate ? (
          <Button size="sm" onClick={() => setDraft({ ...EMPTY_DRAFT })} data-testid="voice-create">
            <Plus className="mr-1 size-3.5" aria-hidden />
            {t('aicg.settings.voice_new', { defaultValue: 'Nowy głos' })}
          </Button>
        ) : null}
      </div>

      {voicesQuery.isPending ? (
        <p className="text-sm text-zinc-500">{t('app.loading', { defaultValue: 'Ładowanie…' })}</p>
      ) : (
        <ul className="divide-y divide-zinc-100" data-testid="voice-list">
          {voices.map((voice) => (
            <li key={voice.id} className="flex items-center gap-3 py-2.5">
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 text-[13.5px] font-medium">
                  {voice.name}
                  {voice.default ? (
                    <span className="inline-flex items-center gap-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-700">
                      <Star className="size-3" aria-hidden />
                      {t('aicg.settings.default', { defaultValue: 'domyślny' })}
                    </span>
                  ) : null}
                </div>
                <div className="truncate text-[12px] text-zinc-500">{voice.tone}</div>
              </div>
              {canAdmin && !voice.default ? (
                <button
                  type="button"
                  onClick={() => setDefaultMutation.mutate(voice.id)}
                  title={t('aicg.settings.set_default', { defaultValue: 'Ustaw jako domyślny' })}
                  aria-label={t('aicg.settings.set_default_aria', {
                    defaultValue: 'Ustaw głos {{name}} jako domyślny',
                    name: voice.name,
                  })}
                  className="rounded-md p-1.5 text-zinc-500 hover:bg-amber-50 hover:text-amber-600"
                  data-testid={`voice-set-default-${voice.name}`}
                >
                  <Star className="size-3.5" aria-hidden />
                </button>
              ) : null}
              {canAdmin ? (
                <>
                  <button
                    type="button"
                    onClick={() => setDraft(toDraft(voice))}
                    title={t('app.edit', { defaultValue: 'Edytuj' })}
                    aria-label={t('aicg.settings.voice_edit_aria', {
                      defaultValue: 'Edytuj głos {{name}}',
                      name: voice.name,
                    })}
                    className="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-100"
                  >
                    <Pencil className="size-3.5" aria-hidden />
                  </button>
                  <button
                    type="button"
                    onClick={() => deleteMutation.mutate(voice.id)}
                    title={t('app.delete', { defaultValue: 'Usuń' })}
                    aria-label={t('aicg.settings.voice_delete_aria', {
                      defaultValue: 'Usuń głos {{name}}',
                      name: voice.name,
                    })}
                    className="rounded-md p-1.5 text-red-500 hover:bg-red-50"
                  >
                    <Trash2 className="size-3.5" aria-hidden />
                  </button>
                </>
              ) : null}
            </li>
          ))}
          {voices.length === 0 ? (
            <li className="py-3 text-sm text-zinc-500">
              {t('aicg.settings.voices_empty', {
                defaultValue: 'Brak głosów marki — utwórz pierwszy.',
              })}
            </li>
          ) : null}
        </ul>
      )}

      {draft !== null ? (
        <div
          className="mt-4 space-y-3 rounded-xl border border-zinc-200 bg-zinc-50/60 p-4"
          data-testid="voice-form"
        >
          <div className="grid grid-cols-2 gap-3">
            <label className="text-[12px] font-medium text-zinc-600">
              {t('aicg.settings.voice_name', { defaultValue: 'Nazwa' })}
              <Input
                value={draft.name}
                onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                data-testid="voice-name"
              />
            </label>
            <label className="flex items-end gap-2 pb-1 text-[12px] font-medium text-zinc-600">
              <input
                type="checkbox"
                checked={draft.isDefault}
                onChange={(e) => setDraft({ ...draft, isDefault: e.target.checked })}
                data-testid="voice-default-toggle"
              />
              {t('aicg.settings.voice_default_label', {
                defaultValue: 'Domyślny (zdejmuje poprzedni default)',
              })}
            </label>
          </div>
          <label className="block text-[12px] font-medium text-zinc-600">
            {t('aicg.settings.voice_tone', { defaultValue: 'Ton (np. „ekspercki, zwięzły")' })}
            <Textarea
              value={draft.tone}
              onChange={(e) => setDraft({ ...draft, tone: e.target.value })}
              rows={2}
              data-testid="voice-tone"
            />
          </label>
          <div className="grid grid-cols-2 gap-3">
            <label className="text-[12px] font-medium text-zinc-600">
              {t('aicg.settings.voice_glossary', {
                defaultValue: 'Glosariusz (linia: termin => użycie)',
              })}
              <Textarea
                value={draft.glossaryText}
                onChange={(e) => setDraft({ ...draft, glossaryText: e.target.value })}
                rows={3}
                placeholder={'smart TV => telewizor smart'}
              />
            </label>
            <label className="text-[12px] font-medium text-zinc-600">
              {t('aicg.settings.voice_banned', { defaultValue: 'Słowa zakazane (po przecinku)' })}
              <Textarea
                value={draft.bannedWordsText}
                onChange={(e) => setDraft({ ...draft, bannedWordsText: e.target.value })}
                rows={3}
                placeholder="tani, promocja"
              />
            </label>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <label className="text-[12px] font-medium text-zinc-600">
              {t('aicg.settings.voice_good', { defaultValue: 'Przykład TAK' })}
              <Textarea
                value={draft.exampleGood}
                onChange={(e) => setDraft({ ...draft, exampleGood: e.target.value })}
                rows={2}
              />
            </label>
            <label className="text-[12px] font-medium text-zinc-600">
              {t('aicg.settings.voice_bad', { defaultValue: 'Przykład NIE' })}
              <Textarea
                value={draft.exampleBad}
                onChange={(e) => setDraft({ ...draft, exampleBad: e.target.value })}
                rows={2}
              />
            </label>
          </div>
          <div className="flex items-center justify-end gap-2">
            <Button
              variant="ghost"
              onClick={() => setDraft(null)}
              disabled={saveMutation.isPending}
            >
              {t('app.cancel', { defaultValue: 'Anuluj' })}
            </Button>
            <Button
              onClick={() => saveMutation.mutate(draft)}
              disabled={
                saveMutation.isPending || draft.name.trim() === '' || draft.tone.trim() === ''
              }
              data-testid="voice-save"
            >
              {t('app.save', { defaultValue: 'Zapisz' })}
            </Button>
          </div>
        </div>
      ) : null}
    </section>
  );
}
