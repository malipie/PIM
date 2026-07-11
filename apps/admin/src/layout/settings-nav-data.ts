import {
  Building2,
  CreditCard,
  GitBranch,
  Globe,
  Key,
  KeyRound,
  type LucideIcon,
  Menu as MenuIcon,
  ShieldCheck,
  Sparkles,
  Users,
} from 'lucide-react';

export interface SettingsNavItem {
  to: string;
  icon: LucideIcon;
  labelKey: string;
  /**
   * Workspace primary entries (Users, Roles) get a small accent dot next to
   * the label when inactive — matches `page.jsx` `primary: true` flag.
   */
  primary?: boolean;
  /** TENANT scope items only Tenant Owner can manage — renders amber `owner` badge. */
  ownerOnly?: boolean;
  /** Hide the entry unless the identity holds this permission code. */
  permission?: string;
  /** Hide the entry unless this runtime feature flag is enabled (WFL-P5-03). */
  featureFlag?: string;
}

export interface SettingsNavGroup {
  id: 'account' | 'workspace' | 'tenant';
  labelKey: string;
  items: readonly SettingsNavItem[];
}

/**
 * NUI-01 (#1420) — single source of the settings sub-navigation. Rendered
 * as an expandable subtree under the "Ustawienia" item in the main sidebar
 * (design: `settings/page.jsx` SETTINGS_SUBNAV); previously this lived as a
 * second sidebar inside `SettingsLayout`.
 */
export const SETTINGS_NAV_GROUPS: readonly SettingsNavGroup[] = [
  {
    id: 'account',
    labelKey: 'settings.nav_group_account',
    items: [{ to: '/settings/security', icon: KeyRound, labelKey: 'settings.nav.security' }],
  },
  {
    id: 'workspace',
    labelKey: 'settings.nav_group_workspace',
    items: [
      { to: '/settings/users', icon: Users, labelKey: 'settings.nav.users', primary: true },
      {
        to: '/settings/roles',
        icon: ShieldCheck,
        labelKey: 'settings.nav.roles',
        primary: true,
      },
      { to: '/settings/api-tokens', icon: Key, labelKey: 'settings.nav.api_tokens' },
      { to: '/settings/sso', icon: Globe, labelKey: 'settings.nav.sso' },
      { to: '/settings/menu', icon: MenuIcon, labelKey: 'settings.nav.menu' },
      // DP-03 (#2033): Locales + Channels moved to Modeling (/modeling/locales,
      // /modeling/channels) — they are data-model dimensions, not settings.
      { to: '/settings/ai', icon: Sparkles, labelKey: 'settings.nav.ai' },
      // WFL-P5-03 (#2433) — definition builder; deployment-flagged and
      // gated by workflow.manage_definitions (Owner/Admin).
      {
        to: '/settings/workflow',
        icon: GitBranch,
        labelKey: 'settings.nav.workflow',
        permission: 'workflow.manage_definitions',
        featureFlag: 'workflow_custom_definitions',
      },
    ],
  },
  {
    id: 'tenant',
    labelKey: 'settings.nav_group_tenant',
    items: [
      { to: '/settings/tenant', icon: Building2, labelKey: 'settings.nav.tenant' },
      {
        to: '/settings/billing',
        icon: CreditCard,
        labelKey: 'settings.nav.billing',
        ownerOnly: true,
      },
    ],
  },
] as const;
