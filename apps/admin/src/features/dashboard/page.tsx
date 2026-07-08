import { ActionCenter } from './components/ActionCenter';
import { AgentCommandHero } from './components/AgentCommandHero';
import { CatalogHealthCard } from './components/CatalogHealthCard';
import { DashboardGreeting } from './components/DashboardGreeting';
import { KpiBand } from './components/KpiBand';
import { TeamActivityCard } from './components/TeamActivityCard';

/**
 * Dashboard "command center" (VIEW-13 #2143) — the approved redesign:
 * greeting → dark agent hero → KPI band → [catalog health | team
 * activity] → action center.
 *
 * Every widget is wired to live data (epic DASH, #2249–#2274): KPI +
 * catalog health via GET /api/dashboard/summary, team activity via
 * /activity + /top-edited, the action center via /alerts. The agent hero
 * runs its own loop (#2246). Each widget degrades per-widget to an honest
 * "—"/empty state on endpoint failure and never fabricates a trend.
 * Per operator decision (2026-07-03) the page carries NO mock badges or
 * banners — it must look exactly like the approved design.
 */
export function DashboardPage() {
  // Padding comes from the AppLayout <main> wrapper — same as every other
  // page; the extra px-* of dashboard v2 made the margins wider than
  // sibling views (operator correction, 2026-07-03).
  return (
    <div className="space-y-6">
      <DashboardGreeting />
      <AgentCommandHero />
      <KpiBand />
      <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <CatalogHealthCard />
        <TeamActivityCard />
      </div>
      <ActionCenter />
    </div>
  );
}
