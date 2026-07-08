# Dashboard — backlog do oprogramowania

> Baza pod kolejne GitHub tickety. Każda pozycja zaznaczona w kodzie komentarzem `MOCK:` lub w dokstrings komponentu jako "Backend: …". Stan na 2026-05-01 (ticket #356 zamyka same makiety; backend dorabiamy później).
>
> **Reguła**: pojedyncza pozycja → osobny issue na GitHubie z labelami `frontend` (+ ewentualnie `backend`), `must-have`/`optional`, `UI`, `epik-UI-03` (lub przeniesione do nowego epika gdy tematyka backendu się rozjedzie).

> **✅ EPIK DASH ZAMKNIĘTY (2026-07-08).** Uinteraktywnienie bloczków Pulpitu — 11 ticketów DASH-01…11 (#2249–#2275, PR #2250…#2275), wszystkie merged. Dashboard w całości live poza bloczkiem agenta (robiony równolegle, #2246). Nowy read-only kontekst `src/Dashboard` (**ADR-0026**) wystawia `GET /api/dashboard/{summary,activity,top-edited,alerts}` + `POST …/alerts/{fp}/ack`. Rozstrzygnięcia architektoniczne (sekcja niżej): **completeness per kanał** = SQL po `objects.completeness->per_channel` (już wyliczane przez `AttributesIndexedRebuilder`, `ChannelCompletenessPort`); **Alert** = agregator on-the-fly z 4 tabel statusowych + detektor spadku ze snapshotów + tabela ack-ów po deterministycznym fingerprincie (NIE encja first-class); **delty** = dzienne `dashboard_snapshots` + job w schedulerze (uczciwy null zamiast fabrykowanego trendu do czasu narośnięcia historii). `mocks.ts` usunięty w całości; wszystkie drill-downy działają przez seed filtrów z URL (`searchStringToDsl`).

## Frontend-only (mock UI, nie wymaga nowego endpointu)

- [x] ~~Dashboard: range picker (7d / 30d / 90d) dla `ActivityChart`~~ — **DASH-08 (#2264)**: toggle w URL (`?range=`), reload serii z API.
- [ ] Konfigurowalne KPI cards — operator wybiera które 4 kafle widoczne (z 6-8 dostępnych). Plik: `apps/admin/src/features/dashboard/components/KpiCards.tsx`. Kandydat: prefs w `localStorage` na MVP, później `workspace_settings`. Estymacja: M. *(Poza zakresem DASH — makieta pinuje dokładnie 4 kafle; konfigurowalność to osobny feature.)*
- [x] ~~Hover-tooltips na chartach + drill-down click na wpis listy `TopEditedProducts`~~ — **DASH-08 (#2264)**: wiersze top-edited = `Link` do `/products/:id`. *(Hover-tooltips na wykresie SVG odroczone — drobny polish.)*
- [ ] Skeleton/loader components dla każdego bloku. Estymacja: S. *(Zamiast skeletonów: degradacja per-widget na „—"/empty state przy błędzie endpointu — decyzja z briefu §8.3. Skeleton loading = opcjonalny polish.)*

## Frontend + nowy endpoint backendowy

- [x] ~~**KPI counts**~~ — **DASH-02/04/06 (#2252/#2256/#2260)**: `GET /api/dashboard/summary` (jeden request zamiast 9 sond) zwraca produkty/publishReady/avgCompleteness/buckets/channels/openAlerts + delty.
- [x] ~~**Activity chart**~~ — **DASH-07/08 (#2262/#2264)**: `GET /api/dashboard/activity?range=` (seria gap-fill z `objects.created_at` + `audit_logs`).
- [x] ~~**Top edited products**~~ — **DASH-07/08 (#2262/#2264)**: `GET /api/dashboard/top-edited?range=&limit=` (ranking z `audit_logs` + hydratacja z `objects`).
- [ ] **Syncs status panel** — `GET /api/integrations/status`. *(Poza zakresem DASH — „Operacje w toku" §5-E briefu; zależy od dedykowanego endpointu zbiorczego. Substrate istnieje: `sync_runs`/`import_sessions`/`feed_runs` czytane już przez agregator alertów.)*
- [ ] **Force sync** button — `POST /api/integrations/{id}/sync`. *(Poza zakresem — sekcja „Operacje w toku".)*
- [x] ~~**Completeness metrics overall + per-channel**~~ — **DASH-03/06 (#2254/#2260)**: `ChannelCompletenessPort` + SQL po `completeness->per_channel`; ring/progi/kanały w `/api/dashboard/summary`.
- [ ] **Recent agent activity** — `GET /api/audit-log?actor=agent&limit=6`. *(Poza zakresem DASH — bloczek agenta robiony równolegle w #2246; `provenance=agent` = Faza 2.)*
- [x] ~~**Alert center**~~ — **DASH-09/10 (#2266/#2274)**: `GET /api/dashboard/alerts` + `POST …/ack`. **Decyzja: agregator on-the-fly, NIE encja Alert** (5 typów: sync/import/feed/webhook/completeness_drop; ack po fingerprincie; okno zamiast TTL).
- [ ] **Channel distribution** — `GET /api/dashboard/channel-distribution` (histogram produktów per liczba kanałów). *(Poza zakresem DASH — §5-G briefu, osobny widget.)*

## Wymaga decyzji architektonicznej (przed wdrożeniem)

- [ ] **Hero "Zapytaj agenta" CTA** — **rozstrzygnięte poza DASH**: bloczek agenta zrobiony w #2246 (realny run `surface:dashboard` → handoff do AgentChatSheet, chipy z `/api/agent/capabilities`). Ten epik go nie dotykał.
- [x] ~~**Schema completeness algorytm**~~ — **rozstrzygnięte (DASH-03)**: per-channel = `objects.completeness->per_channel` wyliczane przez `AttributesIndexedRebuilder` (`required ∪ required_per_channel[C]` per kanał tenanta, kontrakt `docs/api/jsonb-schemas.md` §3). Bez nowej tabeli `channel_attribute_requirements` — reguły kanałowe siedzą w `ObjectType.completeness_rules`.
- [x] ~~**`Alert` jako encja first-class vs. on-the-fly aggregator**~~ — **rozstrzygnięte (ADR-0026, decyzja operatora 2026-07-04): agregator on-the-fly** + tabela `dashboard_alert_acks` po fingerprincie (ack/„oznacz jako przeczytane" bez encji Alert; okno czasowe zamiast TTL; zero seamów zdarzeniowych w 4 BC). Historię „widziałem" pamięta tabela ack-ów, nie sam alert.

## Zależności od innych epików

- Epik 0.8 (BaseLinker) i 0.9 (Shopify) — `SyncsStatusPanel` zależy od pełnej integracji (po Fazie 1).
- Epik 0.7 (Agent layer) — `HeroAgentPanel` CTA + `RecentAgentActivity` z `provenance=agent` (po Fazie 2).
- Epik 0.11 (Hardening + analityka) — `Alert` encja, audit_log endpoint, completeness calculator (kandydat do MVP-Final albo Fazy 1).

## Dopisane przy NUI-02 (#1421, 2026-06-11)

- **Liczniki KPI encji są LIVE** (products/attributes/attribute_groups/categories — `use-dashboard-counts.ts`, wzorzec totalItems). **Delty KPI** („+184 w tym tygodniu") wymagają agregatu historycznego — kafle live renderują się bez delty zamiast fałszywego trendu. *(DASH-04/05: `use-dashboard-counts.ts` zastąpiony przez `/api/dashboard/summary`; delty ze snapshotów `dashboard_snapshots`.)*
- **Backup widget** (RPO, ostatni backup, rozmiar, heatmapa 14 dni) — pgBackRest działa, brak API statusu. Wymaga: endpoint `GET /api/system/backup-status` czytający `pgbackrest info --output=json` (+ cache), pola: last_backup_at, size, rpo_minutes, daily_status[14]. *(Poza zakresem DASH — §5-H briefu, osobny widget systemowy.)*
- Pozostałe widgety (Sync, Aktywność 30d, Top edited, Alerty, Agent activity, Completeness, Channel distribution) — bez zmian statusu: MOCK, istniejące wpisy backlogu aktualne. *(2026-07-08: Aktywność/Top edited/Alerty/Completeness → LIVE w epiku DASH; Sync/Channel distribution/Agent activity/Backup pozostają poza zakresem.)*

## Pozostałe poza zakresem epiku DASH (kandydaci na kolejny epik dashboardowy)

Świadomie nie ruszone w DASH-01…11 — bloczki spoza rdzenia „zdrowie danych + aktywność + alerty":

- **Operacje w toku (§5-E)** — importy/eksporty/feedy/synchronizacje jako wspólny panel. Substrate w pełni istnieje (agregator alertów już czyta `sync_runs`/`import_sessions`/`feed_runs`/`webhook_deliveries`); brakuje zbiorczego endpointu „ostatnie N jobów per podsystem" + widgetu z live-progress (Mercure w importach działa).
- **Dystrybucja kanałowa (§5-G)** — histogram produktów per liczba kanałów.
- **System: backup + tokeny (§5-H)** — `GET /api/system/backup-status` (pgBackRest) + liczba/wygasanie tokenów.
- **Konfigurowalne KPI (§5-C)** — wybór 4 z 8 kafli, prefs per user.
- **Polish**: hover-tooltips na wykresie aktywności, skeleton loading (dziś degradacja per-widget na „—").
- **Per-channel filtr listy produktów** — dziś klik kanału → globalny filtr `completeness<80`; per-channel wymaga pola per-channel w indeksie Meili + nowego filtra API (odroczone jawnie w DASH-06).
