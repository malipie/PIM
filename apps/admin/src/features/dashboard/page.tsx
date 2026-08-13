import { useCanI } from '@/lib/identity';

import { ActionCenter } from './components/ActionCenter';
import { AgentCommandHero } from './components/AgentCommandHero';
import { CatalogHealthCard } from './components/CatalogHealthCard';
import { DashboardGreeting } from './components/DashboardGreeting';
import { KpiBand } from './components/KpiBand';
import { MyTasksCard } from './components/MyTasksCard';
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
  // #2831 — "Tempo pracy zespołu" attributes edits to named people, which
  // is cross-user audit data. A role whose audit reach is `audit.view_own`
  // (Catalog Manager, for one) must not see it; the endpoint behind it
  // refuses the same caller, so leaving the card in would render an error
  // tile. Without it the health card takes the full width instead of
  // leaving a hole in the grid.
  const canSeeTeamActivity = useCanI('audit.view_cross_user');

  // Padding comes from the AppLayout <main> wrapper — same as every other
  // page; the extra px-* of dashboard v2 made the margins wider than
  // sibling views (operator correction, 2026-07-03).
  return (
    <div className="space-y-6">
      <DashboardGreeting />
      <AgentCommandHero />
      <KpiBand />
      <div
        className={
          canSeeTeamActivity ? 'grid grid-cols-1 gap-6 xl:grid-cols-2' : 'grid grid-cols-1 gap-6'
        }
      >
        <CatalogHealthCard />
        {canSeeTeamActivity ? <TeamActivityCard /> : null}
      </div>
      <MyTasksCard />
      <ActionCenter />
    </div>
  );
}
