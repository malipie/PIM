import { Menu } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { GlobalCmdK } from '@/components/agent/global-cmd-k';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { TooltipProvider } from '@/components/ui/tooltip';
import { AgentChatSheet } from '@/features/agent/chat/AgentChatSheet';
import { ExportsLiveBridge } from '@/features/exports/hooks/ExportsLiveBridge';
import { AGENT_ENABLED } from '@/lib/features';

import { AppFooter } from './app-footer';
import { NotificationsInboxProvider } from './notifications-context';
import { PageActionsProvider } from './page-actions-context';
import { SectionPermissionGuard } from './SectionPermissionGuard';
import { SidebarNav } from './sidebar-nav';
import { TopbarV2 } from './topbar-v2';

export function AppLayout() {
  const { t } = useTranslation();
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <TooltipProvider delayDuration={150}>
      <PageActionsProvider>
        <NotificationsInboxProvider>
          <ExportsLiveBridge />
          <GlobalCmdK />
          <div className="flex min-h-screen bg-background">
            <aside className="sticky top-0 hidden h-screen w-[260px] shrink-0 flex-col px-4 py-5 md:flex">
              <SidebarNav />
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
              <header className="glass-strong sticky top-0 z-30 flex items-center gap-1 border-b border-zinc-100 pl-3 md:pl-0">
                <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                  <SheetTrigger asChild>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="md:hidden"
                      aria-label={t('app.toggle_nav', { defaultValue: 'Toggle navigation' })}
                    >
                      <Menu className="size-4" />
                    </Button>
                  </SheetTrigger>
                  <SheetContent
                    side="left"
                    className="w-[260px] bg-background p-4"
                    closeLabel={t('app.close', { defaultValue: 'Close' })}
                  >
                    <SheetTitle className="sr-only">{t('app.title')}</SheetTitle>
                    <SidebarNav onNavigate={() => setMobileOpen(false)} />
                  </SheetContent>
                </Sheet>

                <div className="min-w-0 flex-1">
                  <TopbarV2 />
                </div>
                {AGENT_ENABLED && (
                  <div className="pr-2">
                    <AgentChatSheet />
                  </div>
                )}
              </header>

              <main className="flex-1 overflow-auto p-4 md:p-6">
                {/* #2830 — every routed page passes the section gate, so a
                    URL the caller cannot use shows 403 instead of a form
                    that only fails on submit. */}
                <SectionPermissionGuard />
              </main>

              <AppFooter />
            </div>
          </div>
        </NotificationsInboxProvider>
      </PageActionsProvider>
    </TooltipProvider>
  );
}
