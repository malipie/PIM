import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CopyPlus, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { MultiSelect } from '@/components/ui/multi-select';
import { toast } from '@/components/ui/toast';
import { jsonFetch } from '@/lib/http';
import { useCanI } from '@/lib/identity/use-identity';

/**
 * AICG-P5-04 (#2342) — ContentRecipe CRUD in Settings → AI: the recipe
 * ("how to write copy for this target") is tenant configuration, not
 * code. Built-in rows carry an informational badge but are fully
 * editable + deletable (operator decision, #2341-followup) — plus a
 * clone action; create/edit covers target, source attributes, format,
 * length budget, SEO rules, tone hint and the pinned brand voice.
 */

export interface ContentRecipeRow {
  id: string;
  code: string;
  name: string;
  targetAttribute: string;
  sourceAttributes: string[];
  constraints: {
    format?: string;
    max_len?: number;
    seo?: { keyword?: string; title_len?: number; meta_len?: number };
  };
  toneHint: string | null;
  brandVoiceId: string | null;
  builtIn: boolean;
}

interface AttributeOption {
  id: string;
  code: string;
  type: string;
}

interface VoiceOption {
  id: string;
  name: string;
}

interface RecipeDraft {
  id: string | null;
  code: string;
  name: string;
  targetAttribute: string;
  sourceAttributes: string[];
  format: string;
  maxLen: string;
  seoKeyword: string;
  seoTitleLen: string;
  seoMetaLen: string;
  toneHint: string;
  brandVoiceId: string;
}

const EMPTY_DRAFT: RecipeDraft = {
  id: null,
  code: '',
  name: '',
  targetAttribute: '',
  sourceAttributes: [],
  format: 'plain',
  maxLen: '',
  seoKeyword: '',
  seoTitleLen: '',
  seoMetaLen: '',
  toneHint: '',
  brandVoiceId: '',
};

function collection<T>(payload: { member?: T[]; 'hydra:member'?: T[] }): T[] {
  return payload.member ?? payload['hydra:member'] ?? [];
}

function toDraft(recipe: ContentRecipeRow): RecipeDraft {
  return {
    id: recipe.id,
    code: recipe.code,
    name: recipe.name,
    targetAttribute: recipe.targetAttribute,
    sourceAttributes: recipe.sourceAttributes,
    format: recipe.constraints.format ?? 'plain',
    maxLen: recipe.constraints.max_len != null ? String(recipe.constraints.max_len) : '',
    seoKeyword: recipe.constraints.seo?.keyword ?? '',
    seoTitleLen:
      recipe.constraints.seo?.title_len != null ? String(recipe.constraints.seo.title_len) : '',
    seoMetaLen:
      recipe.constraints.seo?.meta_len != null ? String(recipe.constraints.seo.meta_len) : '',
    toneHint: recipe.toneHint ?? '',
    brandVoiceId: recipe.brandVoiceId ?? '',
  };
}

function draftBody(draft: RecipeDraft): Record<string, unknown> {
  const constraints: Record<string, unknown> = { format: draft.format };
  if (draft.maxLen.trim() !== '') {
    constraints.max_len = Number(draft.maxLen);
  }
  const seo: Record<string, unknown> = {};
  if (draft.seoKeyword.trim() !== '') seo.keyword = draft.seoKeyword.trim();
  if (draft.seoTitleLen.trim() !== '') seo.title_len = Number(draft.seoTitleLen);
  if (draft.seoMetaLen.trim() !== '') seo.meta_len = Number(draft.seoMetaLen);
  if (Object.keys(seo).length > 0) {
    constraints.seo = seo;
  }
  return {
    name: draft.name,
    targetAttribute: draft.targetAttribute,
    sourceAttributes: draft.sourceAttributes,
    constraints,
    toneHint: draft.toneHint.trim() !== '' ? draft.toneHint : null,
    brandVoiceId: draft.brandVoiceId !== '' ? draft.brandVoiceId : null,
  };
}

export function ContentRecipesSection() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const canAdmin = useCanI('settings.ai_content.admin');
  const canCreate = useCanI('settings.ai_content.create');
  const [draft, setDraft] = useState<RecipeDraft | null>(null);

  const recipesQuery = useQuery({
    queryKey: ['content-recipes'],
    queryFn: () => jsonFetch<{ member?: ContentRecipeRow[] }>('/api/content-recipes'),
  });
  const attributesQuery = useQuery({
    queryKey: ['aicg-attribute-catalog'],
    queryFn: () => jsonFetch<{ member?: AttributeOption[] }>('/api/attributes'),
    staleTime: 5 * 60_000,
  });
  const voicesQuery = useQuery({
    queryKey: ['brand-voice-profiles'],
    queryFn: () => jsonFetch<{ member?: VoiceOption[] }>('/api/brand-voice-profiles'),
  });

  const recipes = recipesQuery.data ? collection(recipesQuery.data) : [];
  const attributes = attributesQuery.data ? collection(attributesQuery.data) : [];
  const voices = voicesQuery.data ? collection(voicesQuery.data) : [];

  const textAttributeOptions = attributes
    .filter((attr) => ['text', 'textarea', 'wysiwyg'].includes(attr.type))
    .map((attr) => ({ value: attr.code, label: attr.code }));
  const allAttributeOptions = attributes.map((attr) => ({ value: attr.code, label: attr.code }));
  const voiceOptions = [
    { value: '', label: t('aicg.settings.no_voice', { defaultValue: '(domyślny głos tenanta)' }) },
    ...voices.map((voice) => ({ value: voice.id, label: voice.name })),
  ];

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['content-recipes'] });

  const saveMutation = useMutation({
    mutationFn: async (current: RecipeDraft) => {
      if (current.id === null) {
        return jsonFetch('/api/content-recipes', {
          method: 'POST',
          contentType: 'application/ld+json',
          body: { code: current.code, ...draftBody(current) },
        });
      }
      return jsonFetch(`/api/content-recipes/${current.id}`, {
        method: 'PATCH',
        contentType: 'application/merge-patch+json',
        body: draftBody(current),
      });
    },
    onSuccess: async () => {
      toast.success(t('aicg.settings.recipe_saved', { defaultValue: 'Przepis zapisany.' }));
      setDraft(null);
      await invalidate();
    },
    onError: (error) => toast.error(error instanceof Error ? error.message : String(error)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => jsonFetch(`/api/content-recipes/${id}`, { method: 'DELETE' }),
    onSuccess: async () => {
      toast.success(t('aicg.settings.recipe_deleted', { defaultValue: 'Przepis usunięty.' }));
      await invalidate();
    },
    onError: (error) => toast.error(error instanceof Error ? error.message : String(error)),
  });

  const cloneMutation = useMutation({
    mutationFn: (id: string) =>
      jsonFetch(`/api/content-recipes/${id}/clone`, {
        method: 'POST',
        contentType: 'application/json',
        body: {},
      }),
    onSuccess: async () => {
      toast.success(
        t('aicg.settings.recipe_cloned', { defaultValue: 'Utworzono edytowalną kopię.' }),
      );
      await invalidate();
    },
    onError: (error) => toast.error(error instanceof Error ? error.message : String(error)),
  });

  return (
    <section
      className="rounded-2xl border border-zinc-200 bg-white p-5"
      data-testid="content-recipes-section"
    >
      <div className="mb-3 flex items-center justify-between">
        <div>
          <h2 className="text-[15px] font-semibold">
            {t('aicg.settings.recipes_title', { defaultValue: 'Przepisy treści' })}
          </h2>
          <p className="text-[12.5px] text-zinc-500">
            {t('aicg.settings.recipes_subtitle', {
              defaultValue:
                'Jak agent pisze treść: pole docelowe, atrybuty-źródła, reguły formatu i SEO.',
            })}
          </p>
        </div>
        {canCreate ? (
          <Button
            size="sm"
            onClick={() => setDraft({ ...EMPTY_DRAFT })}
            data-testid="recipe-create"
          >
            <Plus className="mr-1 size-3.5" aria-hidden />
            {t('aicg.settings.recipe_new', { defaultValue: 'Nowy przepis' })}
          </Button>
        ) : null}
      </div>

      {recipesQuery.isPending ? (
        <p className="text-sm text-zinc-500">{t('app.loading', { defaultValue: 'Ładowanie…' })}</p>
      ) : (
        <ul className="divide-y divide-zinc-100" data-testid="recipe-list">
          {recipes.map((recipe) => (
            <li key={recipe.id} className="flex items-center gap-3 py-2.5">
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 text-[13.5px] font-medium">
                  {recipe.name}
                  {recipe.builtIn ? (
                    <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-zinc-500">
                      {t('aicg.settings.built_in', { defaultValue: 'wbudowany' })}
                    </span>
                  ) : null}
                </div>
                <div className="truncate text-[12px] text-zinc-500">
                  {recipe.targetAttribute} ← {recipe.sourceAttributes.join(', ')}
                </div>
              </div>
              {canCreate ? (
                <button
                  type="button"
                  onClick={() => cloneMutation.mutate(recipe.id)}
                  title={t('aicg.settings.clone', { defaultValue: 'Klonuj' })}
                  aria-label={t('aicg.settings.clone_aria', {
                    defaultValue: 'Klonuj przepis {{name}}',
                    name: recipe.name,
                  })}
                  className="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-100"
                >
                  <CopyPlus className="size-3.5" aria-hidden />
                </button>
              ) : null}
              {canAdmin ? (
                <>
                  <button
                    type="button"
                    onClick={() => setDraft(toDraft(recipe))}
                    title={t('app.edit', { defaultValue: 'Edytuj' })}
                    aria-label={t('aicg.settings.edit_aria', {
                      defaultValue: 'Edytuj przepis {{name}}',
                      name: recipe.name,
                    })}
                    className="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-100"
                  >
                    <Pencil className="size-3.5" aria-hidden />
                  </button>
                  <button
                    type="button"
                    onClick={() => deleteMutation.mutate(recipe.id)}
                    title={t('app.delete', { defaultValue: 'Usuń' })}
                    aria-label={t('aicg.settings.delete_aria', {
                      defaultValue: 'Usuń przepis {{name}}',
                      name: recipe.name,
                    })}
                    className="rounded-md p-1.5 text-red-500 hover:bg-red-50"
                  >
                    <Trash2 className="size-3.5" aria-hidden />
                  </button>
                </>
              ) : null}
            </li>
          ))}
          {recipes.length === 0 ? (
            <li className="py-3 text-sm text-zinc-500">
              {t('aicg.settings.recipes_empty', {
                defaultValue: 'Brak przepisów — utwórz pierwszy.',
              })}
            </li>
          ) : null}
        </ul>
      )}

      {draft !== null ? (
        <div
          className="mt-4 space-y-3 rounded-xl border border-zinc-200 bg-zinc-50/60 p-4"
          data-testid="recipe-form"
        >
          <div className="grid grid-cols-2 gap-3">
            {draft.id === null ? (
              <label className="text-[12px] font-medium text-zinc-600">
                {t('aicg.settings.recipe_code', { defaultValue: 'Kod (snake_case)' })}
                <Input
                  value={draft.code}
                  onChange={(e) => setDraft({ ...draft, code: e.target.value })}
                  placeholder="opis_butow"
                  data-testid="recipe-code"
                />
              </label>
            ) : null}
            <label className="text-[12px] font-medium text-zinc-600">
              {t('aicg.settings.recipe_name', { defaultValue: 'Nazwa' })}
              <Input
                value={draft.name}
                onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                data-testid="recipe-name"
              />
            </label>
            <label className="text-[12px] font-medium text-zinc-600">
              {t('aicg.settings.recipe_target', { defaultValue: 'Pole docelowe' })}
              <Combobox
                options={textAttributeOptions}
                value={draft.targetAttribute}
                onChange={(next) => setDraft({ ...draft, targetAttribute: next ?? '' })}
                placeholder={t('aicg.settings.recipe_target_ph', {
                  defaultValue: 'np. description',
                })}
              />
            </label>
            <label className="text-[12px] font-medium text-zinc-600">
              {t('aicg.settings.recipe_voice', { defaultValue: 'Głos marki' })}
              <Combobox
                options={voiceOptions}
                value={draft.brandVoiceId}
                onChange={(next) => setDraft({ ...draft, brandVoiceId: next ?? '' })}
              />
            </label>
          </div>
          <label className="block text-[12px] font-medium text-zinc-600">
            {t('aicg.settings.recipe_sources', { defaultValue: 'Atrybuty-źródła (fakty)' })}
            <MultiSelect
              options={allAttributeOptions}
              value={draft.sourceAttributes}
              onChange={(next) => setDraft({ ...draft, sourceAttributes: next })}
            />
          </label>
          <div className="grid grid-cols-3 gap-3">
            <label className="text-[12px] font-medium text-zinc-600">
              {t('aicg.settings.recipe_format', { defaultValue: 'Format' })}
              <Combobox
                options={[
                  { value: 'plain', label: 'plain' },
                  { value: 'html', label: 'html' },
                ]}
                value={draft.format}
                onChange={(next) => setDraft({ ...draft, format: next ?? 'plain' })}
              />
            </label>
            <label className="text-[12px] font-medium text-zinc-600">
              {t('aicg.settings.recipe_max_len', { defaultValue: 'Maks. długość' })}
              <Input
                type="number"
                value={draft.maxLen}
                onChange={(e) => setDraft({ ...draft, maxLen: e.target.value })}
              />
            </label>
            <label className="text-[12px] font-medium text-zinc-600">
              {t('aicg.settings.recipe_tone', { defaultValue: 'Wskazówka tonu' })}
              <Input
                value={draft.toneHint}
                onChange={(e) => setDraft({ ...draft, toneHint: e.target.value })}
              />
            </label>
          </div>
          <div className="grid grid-cols-3 gap-3">
            <label className="text-[12px] font-medium text-zinc-600">
              {t('aicg.settings.recipe_seo_keyword', { defaultValue: 'SEO: słowo kluczowe' })}
              <Input
                value={draft.seoKeyword}
                onChange={(e) => setDraft({ ...draft, seoKeyword: e.target.value })}
              />
            </label>
            <label className="text-[12px] font-medium text-zinc-600">
              {t('aicg.settings.recipe_seo_title', { defaultValue: 'SEO: limit title' })}
              <Input
                type="number"
                value={draft.seoTitleLen}
                onChange={(e) => setDraft({ ...draft, seoTitleLen: e.target.value })}
              />
            </label>
            <label className="text-[12px] font-medium text-zinc-600">
              {t('aicg.settings.recipe_seo_meta', { defaultValue: 'SEO: limit meta' })}
              <Input
                type="number"
                value={draft.seoMetaLen}
                onChange={(e) => setDraft({ ...draft, seoMetaLen: e.target.value })}
                data-testid="recipe-seo-meta"
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
                saveMutation.isPending ||
                draft.name.trim() === '' ||
                draft.targetAttribute === '' ||
                (draft.id === null && draft.code.trim() === '')
              }
              data-testid="recipe-save"
            >
              {t('app.save', { defaultValue: 'Zapisz' })}
            </Button>
          </div>
        </div>
      ) : null}
    </section>
  );
}
