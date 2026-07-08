import { ArrowLeft, MoreHorizontal, Save, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { CompletenessRing } from './completeness-ring';
import { DuplicateButton } from './duplicate-button';
import { LocaleChannelToolbar } from './locale-channel-toolbar';
import { type TabKey, tabBadge, tabLabel } from './product-detail-helpers';
import type {
  CatalogObjectDto,
  ChannelOption,
  GroupMeta,
  LocaleOption,
  ProductChannel,
  ProductDetailMode,
  ProductLocale,
} from './types';

/**
 * AUD-057 (#1608) — the product-detail sticky header (breadcrumb + action
 * bar + title/identity block + completeness ring + tab strip + locale/
 * channel toolbar), lifted out of product-detail-page.tsx to bring that
 * monolith under the 500-line guard. Purely presentational: every value +
 * handler is passed in, so the page keeps ownership of state and the
 * save/delete logic.
 */
export interface ProductDetailHeaderProps {
  mode: ProductDetailMode;
  kind: string | null;
  id: string;
  backHref: string;
  objectTypeLabel: string | undefined;
  breadcrumbCategory: string;
  skuValue: string;
  nameValue: string;
  brandValue: string;
  objectTypeName: string | null;
  product: CatalogObjectDto | null | undefined;
  isEditing: boolean;
  isSaving: boolean;
  objectTypeId: string | null;
  scopedCompletenessPct: number;
  completenessScope: string | null;
  lang: 'pl' | 'en';
  visibleTabs: readonly TabKey[];
  activeTab: TabKey;
  groups: GroupMeta[];
  stackedGroups: GroupMeta[];
  locale: ProductLocale;
  channel: ProductChannel | null;
  locales: LocaleOption[];
  channels: ChannelOption[];
  onSave: (returnToList?: boolean) => void;
  onCancel: () => void;
  onRequestDelete: () => void;
  onFieldChange: (code: string, value: unknown) => void;
  onSelectTab: (tab: TabKey) => void;
  onLocaleChange: (locale: ProductLocale) => void;
  onChannelChange: (channel: ProductChannel | null) => void;
}

export function ProductDetailHeader({
  mode,
  kind,
  id,
  backHref,
  objectTypeLabel,
  breadcrumbCategory,
  skuValue,
  nameValue,
  brandValue,
  objectTypeName,
  product,
  isEditing,
  isSaving,
  objectTypeId,
  scopedCompletenessPct,
  completenessScope,
  lang,
  visibleTabs,
  activeTab,
  groups,
  stackedGroups,
  locale,
  channel,
  locales,
  channels,
  onSave,
  onCancel,
  onRequestDelete,
  onFieldChange,
  onSelectTab,
  onLocaleChange,
  onChannelChange,
}: ProductDetailHeaderProps) {
  const { t } = useTranslation();

  return (
    <header className="sticky top-0 z-20 glass-strong border-b border-zinc-100">
      <div className="px-7 pb-3 pt-5">
        <div className="flex items-center gap-3">
          <Button
            asChild
            variant="ghost"
            size="icon"
            className="size-9 rounded-xl bg-white soft-shadow"
          >
            <Link
              to={backHref}
              aria-label={t('products.back', { defaultValue: 'Powrót do listy' })}
            >
              <ArrowLeft className="size-4" />
            </Link>
          </Button>
          <div className="text-[12px] text-zinc-500">
            <span>{objectTypeLabel ?? t('products.title', { defaultValue: 'Produkty' })}</span>
            <span className="mx-1.5 text-zinc-300">/</span>
            <span>{breadcrumbCategory}</span>
            {skuValue !== '' ? (
              <>
                <span className="mx-1.5 text-zinc-300">/</span>
                <span className="font-medium text-zinc-900">{skuValue}</span>
              </>
            ) : null}
          </div>
          <div className="ml-auto flex items-center gap-2">
            {/* Duplicate hits a product-only endpoint. */}
            {kind === 'product' && mode === 'edit' && id !== '' ? (
              <DuplicateButton productId={id} />
            ) : null}
            {mode === 'edit' && id !== '' ? (
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-9 rounded-xl bg-white soft-shadow"
                    aria-label={t('products.detail.actions.more', { defaultValue: 'Więcej' })}
                  >
                    <MoreHorizontal className="size-4" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                  <DropdownMenuItem
                    onSelect={onRequestDelete}
                    className="text-rose-600 focus:bg-rose-50 focus:text-rose-700"
                  >
                    <Trash2 className="mr-2 size-4" />
                    {t('products.detail.actions.delete', { defaultValue: 'Usuń produkt' })}
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            ) : (
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-9 rounded-xl bg-white soft-shadow"
                aria-label={t('products.detail.actions.more', { defaultValue: 'Więcej' })}
                disabled
              >
                <MoreHorizontal className="size-4" />
              </Button>
            )}
            <span className="mx-1 h-6 w-px bg-zinc-200" />
            {mode === 'edit' ? (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={onCancel}
                disabled={isSaving}
                className="h-9 rounded-xl px-3 text-[12.5px] text-zinc-600"
              >
                {t('products.detail.actions.cancel', { defaultValue: 'Anuluj' })}
              </Button>
            ) : null}
            <Button
              type="button"
              onClick={() => onSave()}
              disabled={isSaving || (mode === 'create' && objectTypeId === null)}
              className="h-9 rounded-xl bg-zinc-900 px-4 text-[12.5px] font-medium text-white hover:bg-zinc-800"
            >
              <Save className="size-4" />
              {mode === 'create'
                ? kind === 'product'
                  ? t('products.detail.actions.create', { defaultValue: 'Utwórz produkt' })
                  : t('object_create.submit', { defaultValue: 'Utwórz' })
                : t('products.detail.actions.save', { defaultValue: 'Zapisz zmiany' })}
            </Button>
            {/* #1351 — save and return to the list (edit mode only). */}
            {mode === 'edit' ? (
              <Button
                type="button"
                variant="outline"
                onClick={() => onSave(true)}
                disabled={isSaving}
                className="h-9 rounded-xl px-4 text-[12.5px] font-medium"
              >
                <Save className="size-4" />
                {t('products.detail.actions.save_and_return', {
                  defaultValue: 'Zapisz i wróć do listy',
                })}
              </Button>
            ) : null}
          </div>
        </div>

        <div className="mt-4 flex items-start gap-5">
          <div
            className="grid size-[72px] shrink-0 place-items-center rounded-2xl bg-white text-[34px] soft-shadow"
            aria-hidden
          >
            ▣
          </div>
          <div className="min-w-0 flex-1">
            {mode === 'create' ? (
              <div className="space-y-2">
                <div className="flex items-center gap-2.5 text-[12px] text-zinc-500">
                  <Input
                    autoFocus
                    placeholder={t('object_create.id_placeholder', { defaultValue: 'ID' })}
                    value={skuValue}
                    onChange={(event) => onFieldChange('sku', event.target.value)}
                    className="h-7 w-32 rounded-lg border-zinc-200 bg-white px-2 font-mono text-[12px]"
                  />
                </div>
                <Input
                  placeholder={
                    kind === 'product'
                      ? t('products.detail.create.placeholder.name', {
                          defaultValue: 'Nazwa produktu',
                        })
                      : t('object_create.name_placeholder', { defaultValue: 'Nazwa' })
                  }
                  value={nameValue}
                  onChange={(event) => onFieldChange('name', event.target.value)}
                  className="font-display h-10 rounded-lg border-zinc-200 bg-white text-[20px] font-semibold tracking-tight"
                />
                {/* #1357 — "Marka" removed from the new-entry form. */}
              </div>
            ) : (
              <>
                <div className="flex items-center gap-2.5 text-[12px] text-zinc-500">
                  <span className="font-mono">{product?.code}</span>
                  {brandValue !== '' ? (
                    <>
                      <span className="text-zinc-300">·</span>
                      <span>{brandValue}</span>
                    </>
                  ) : null}
                  <span className="text-zinc-300">·</span>
                  <span className="inline-flex items-center gap-1.5">
                    <span
                      className={cn(
                        'size-1.5 rounded-full',
                        product?.enabled ? 'bg-emerald-500' : 'bg-zinc-300',
                      )}
                      aria-hidden
                    />
                    {product?.enabled
                      ? t('products.detail.status.active', { defaultValue: 'Aktywny' })
                      : t('products.detail.status.inactive', { defaultValue: 'Nieaktywny' })}
                  </span>
                </div>
                {isEditing ? (
                  <Input
                    aria-label={t('products.detail.create.placeholder.name', {
                      defaultValue: 'Nazwa produktu',
                    })}
                    placeholder={t('products.detail.create.placeholder.name', {
                      defaultValue: 'Nazwa produktu',
                    })}
                    value={nameValue}
                    onChange={(event) => onFieldChange('name', event.target.value)}
                    className="font-display mt-1 h-11 rounded-lg border-zinc-200 bg-white text-[26px] font-semibold tracking-tight"
                  />
                ) : (
                  <h1 className="font-display mt-1 text-[26px] font-semibold leading-tight tracking-tight">
                    {nameValue}
                  </h1>
                )}
                {objectTypeName !== null ? (
                  <div className="mt-2.5 flex flex-wrap items-center gap-2">
                    <span className="rounded-full bg-white px-2 py-1 text-[11px] font-medium text-zinc-700 soft-shadow">
                      {objectTypeName}
                    </span>
                  </div>
                ) : null}
              </>
            )}
          </div>
          {mode === 'edit' ? (
            <div className="flex flex-col items-center gap-1">
              <CompletenessRing pct={scopedCompletenessPct} size={72} stroke={6} />
              {completenessScope !== null ? (
                <span
                  className="num rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-zinc-500"
                  title={t('products.completeness.scope_tooltip', {
                    scope: completenessScope.toUpperCase(),
                    defaultValue: 'Kompletność dla zakresu {{scope}}',
                  })}
                >
                  {completenessScope}
                </span>
              ) : null}
            </div>
          ) : null}
        </div>
      </div>

      {/* Tabs + locale/channel toolbar */}
      <div className="flex items-center gap-1 border-t border-zinc-100 px-7">
        <div
          className="flex flex-1 items-center gap-1"
          role="tablist"
          aria-label={t('products.detail.tabs.aria', { defaultValue: 'Zakładki produktu' })}
        >
          {visibleTabs.map((tab) => {
            const isActive = activeTab === tab;
            const badge = tabBadge(tab, groups, stackedGroups, product);
            return (
              <button
                key={tab}
                type="button"
                role="tab"
                aria-selected={isActive}
                onClick={() => onSelectTab(tab)}
                className={cn(
                  'relative inline-flex h-[44px] items-center gap-2 px-3.5 text-[13px] font-medium tracking-tight',
                  isActive ? 'text-zinc-900' : 'text-zinc-500 hover:text-zinc-800',
                )}
              >
                {tabLabel(tab, groups, lang, t)}
                {badge !== null ? (
                  <span
                    className={cn(
                      'num rounded px-1.5 py-0.5 text-[10.5px]',
                      isActive ? 'bg-zinc-900 text-white' : 'bg-zinc-100 text-zinc-500',
                    )}
                  >
                    {badge}
                  </span>
                ) : null}
                {isActive ? (
                  <span
                    className="absolute -bottom-px left-0 right-0 h-[2px] rounded-t bg-zinc-900"
                    aria-hidden
                  />
                ) : null}
              </button>
            );
          })}
        </div>
        {mode === 'edit' ? (
          <LocaleChannelToolbar
            locale={locale}
            channel={channel}
            onLocaleChange={onLocaleChange}
            onChannelChange={onChannelChange}
            locales={locales}
            channels={channels}
          />
        ) : null}
      </div>
    </header>
  );
}
