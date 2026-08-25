# Workflow edytorski (epik WFL)

> Dokumentacja user- i developer-facing dla warstwy workflow PIM. Źródło decyzji architektonicznych: [`docs/adr/0029-workflow-engine-and-placement.md`](adr/0029-workflow-engine-and-placement.md). Backlog i benchmark: [`Project Plan/feature-workflow-tickets.md`](../Project%20Plan/feature-workflow-tickets.md). Polityka stanów RBAC: [`Project Plan/PRD/PRD-PIM-rbac.md`](../Project%20Plan/PRD/PRD-PIM-rbac.md) §3.8. Kontrakt pól JSONB (gate kompletności): [`docs/api/jsonb-schemas.md`](api/jsonb-schemas.md) §7.

## 1. Po co to jest

Workflow to **warstwa autoryzacyjna nad cyklem życia obiektu** (produkt, kategoria, asset). Odpowiada na pytanie „kto, kiedy i pod jakimi warunkami może zmienić stan i treść obiektu". Wyróżnik nad Pimcore OSS: dedykowana kolejka przeglądu (inbox), zadania generowane automatycznie z przejść, oraz — od WFL-P5 — **definicje procesu edytowalne per tenant bez deployu** (czego Pimcore nie umie bez płatnego visual designera).

## 2. Maszyna stanów `object_editorial`

Silnik: `symfony/workflow` typu `state_machine`. Marking trzymany na kolumnie `objects.status` przez accessor `getEditorialMarking()`/`setEditorialMarking()` na `CatalogObject` (celowo NIE przez publiczny `status` — uniknięcie leaka schematu do OpenAPI).

```
        submit_for_review           approve
 draft ────────────────► review ────────────► published
   ▲                        │                     │
   │        reject          │      unpublish      │
   └────────────────────────┘◄────────────────────┘
   │                                              
   │ publish (skrót draft→published)              
   └──────────────────────────────────────────────► published
                                                    
 published ──unpublish──► draft      (dowolny) ──archive──► archived ──restore──► draft
```

| Stan | Znaczenie |
|---|---|
| `draft` | edycja treści dozwolona; stan początkowy |
| `review` | zgłoszony do przeglądu; treść zablokowana dla nie-approverów |
| `published` | opublikowany; treść zablokowana (patrz §5 edit-lock) |
| `archived` | zarchiwizowany; przywracalny przez `restore` |

Przejścia i wymagane uprawnienia (statyczna mapa `TransitionPermissionGuard`; definicje DB nadpisują per-przejście metadanymi — §6):

| Przejście | Z → do | Uprawnienie |
|---|---|---|
| `submit_for_review` | draft → review | `products.edit` |
| `publish` | draft → published (skrót) | `workflow.approve_reject` |
| `approve` | review → published | `workflow.approve_reject` |
| `reject` | review → draft | `workflow.approve_reject` |
| `unpublish` | published → draft | `workflow.transition.unpublish` |
| `archive` | * → archived | `workflow.approve_reject` |
| `restore` | archived → draft | `workflow.approve_reject` |

## 3. API

Wszystkie trasy są w OpenAPI (`docs/api-spec/v0.json`). Powierzchnia proceduralna (CQRS, custom `#[Route]`):

- `GET /api/objects/{id}/workflow` — discovery: aktualny stan + dostępne przejścia z `blockers[]` (kody: brak uprawnienia, `completeness_gate`, `comment_required`) + `reviewer` (`{type: role|user, label}` — rozwiązany akceptant do którego trafi task po zgłoszeniu; §4). FE jest źródłem prawdy które przyciski renderować i pokazuje hint „zadanie trafi do…" pod kontrolką.
- `POST /api/objects/{id}/workflow/transitions/{transition}` — zastosuj przejście. Body opcjonalne `{comment}` (≤2000 znaków). 409 + `blockers` gdy przejście niedozwolone; 422 gdy `comment_required` a komentarz pusty.
- `GET /api/objects/{id}/workflow/transitions` — log przejść (kursor UUIDv7, najnowsze pierwsze) + `actor_name` (nazwa autora przejścia z seam `Identity\Contracts\Directory\UserDirectoryInterface`; `null` dla systemu/CLI).
- `POST /api/objects/{id}/workflow/request-unpublish` — prośba o depublikację (dla użytkownika bez `workflow.transition.unpublish`).
- `GET/POST/PATCH /api/workflow/tasks` — zadania (lista `mine`/`status`/`object`/`due`; tworzenie custom; complete/cancel/reassign gated assignee-or-approver). Lista wzbogaca każdy task o `object_title/object_sku/object_kind` (batch seam `Catalog\Contracts\Query\ObjectSummaryPort` po `object_id`; `null` dla usuniętych obiektów), `created_by_name` i `assignee_name` (UserDirectory). `mine` matchuje bezpośrednie przypisanie, członkostwo roli-akceptanta **oraz** posiadaczy `workflow.approve_reject` (superset — task widoczny niezależnie od tego czy akceptant to rola czy konkretny user).
- `GET/PATCH /api/notifications` — powiadomienia in-app (own-data, gated `workflow.view`).
- `GET/POST/PUT /api/workflow/definitions` + `enable`/`disable` — CRUD definicji (gated `workflow.manage_definitions`, §6).

Bulk: `POST /api/objects/bulk-actions/change_status` — przejście na wielu obiektach; **per-row** sprawdzenie `can()` (marketing bulk-approve = 100% zablokowane, nie eskalacja przez coarse permission).

## 4. Zadania i powiadomienia (WFL-P4)

Przejście generuje zadania automatycznie (`WorkflowTaskAutomation`, idempotentnie — obiekt nigdy nie ma dwóch OPEN tasków jednego typu):

- `submit_for_review` → task `review` dla **skonfigurowanego akceptanta** (§4a).
- `reject` → zamknięcie tasku review + task `fix` dla autora submitu z komentarzem recenzenta.
- `approve` → zamknięcie tasku review.
- `request-unpublish` → task `request_unpublish` dla **skonfigurowanego akceptanta** (§4a).

### 4a. Konfigurowalny akceptant (routing zadań, ADR-0029)

Akceptant (odbiorca tasków `review` / `request_unpublish`) jest **konfigurowalny per ObjectType**: rola **LUB** konkretny user (XOR). Rozwiązanie: `EditorialWorkflowProvider::reviewerFor(?objectTypeId): ?TaskAssignee` — definicja ObjectType-specific > tenant-global; gdy flaga OFF / brak definicji / brak `reviewer` → `null`, a automatyka spada na wbudowaną rolę `approver` (`ObjectEditorialWorkflow::REVIEWER_ROLE`). VO `Workflow\Contracts\TaskAssignee` trzyma XOR `roleCode`/`userId`.

Ustawiane w UI **Ustawienia przepływu** (§6) — picker akceptanta (rola albo user). Pole `workflow_definitions.reviewer` (JSONB `{role_code}|{user_id}`, migracja `Version20260712100000`) walidowane przez `WorkflowDefinitionValidator` (istnienie roli/usera w tenancie + XOR). Discovery endpoint (§3) zwraca rozwiązanego akceptanta jako `reviewer`, więc kontrolka na karcie produktu pokazuje „Po zgłoszeniu zadanie trafi do: <Rola: X | Imię usera>". Fix-task zawsze idzie do autora submitu (bez zmian).

Powiadomienia (`WorkflowNotificationFanOut`, post-flush pipeline przez Messenger): submit → grantees `workflow.approve_reject` (minus submitter); approve/reject → autor submitu; unpublish_requested → grantees `workflow.transition.unpublish`. Zadania **nie** wysyłają własnego powiadomienia — fan-out zdarzenia już powiadomił tę samą publiczność (unika duplikatu).

## 5. Edit-lock na opublikowanych obiektach (WFL-P1-02/03)

Treść obiektu w stanie `published`/`review` jest zablokowana dla użytkowników bez `products.edit_any_state`. Próba PATCH treści → 403 `workflow_state_locked` z flagą `request_unpublish_available`. FE pokazuje `workflow-lock-banner` z akcją „poproś o depublikację". Ścieżka auto-unpublish (dla uprawnionych): edycja publikowanego obiektu automatycznie stosuje `unpublish` z kontekstem `{auto_unpublish: true}`.

## 6. Definicje per tenant (WFL-P5, ADR-0029 filar 7)

Za flagą `WORKFLOW_CUSTOM_DEFINITIONS` (default OFF). Gdy OFF — statyczna maszyna YAML `object_editorial` (100% jak dotąd). Gdy ON — `EditorialWorkflowProvider` buduje maszynę z rekordu `workflow_definitions` (ObjectType-specific > tenant-global), zachowując tę samą NAZWĘ `object_editorial` i współdzielony dispatcher, więc wszystkie listenery (guard, gate, log, event recorder) działają bez zmian. Metadane per-przejście (`permission`, `comment_required`, `completeness_gate`) czytają guardy przez `InMemoryMetadataStore`.

Builder UI: **Workflow → „Definicje przepływów"** (trasa `/workflow/definitions`, gated `workflow.manage_definitions` + flaga runtime na `GET /api/auth/me` → `feature_flags.workflow_custom_definitions`; stare ścieżki `/settings/workflow` i `/workflow/settings` przekierowują).

**Jeden ekran od #3000.** Do sierpnia 2026 konfiguracja żyła w dwóch miejscach zapisujących ten sam rekord: „Ustawienia przepływu" (tylko akceptant + próg kompletności) i „Definicje przepływów" (kształt, ale bez akceptanta). Ekran ustawień został usunięty, a wszystko, co umiał, wsiąkło w edytor definicji:

- **Nowy przepływ zaczyna się od szablonu** (#3004): bez akceptacji / jedna akceptacja / akceptacja i publikacja osobno. Szablon „jedna akceptacja" jest budowany z `editorial-shape.ts`, czyli z tego samego kształtu, który mirroruje `workflow.yaml`.
- **Etapy i akcje mówią po ludzku** (#3002): operator wpisuje etykietę, nazwa techniczna (`snake_case`) powstaje ze slugify; przejście czyta się jak zdanie („Zgłoś do przeglądu przenosi z Szkic do W przeglądzie — może Wprowadzający dane"); uprawnienia mają etykiety ról. Nazwy techniczne i surowe kody są pod przełącznikiem **Tryb zaawansowany**.
- **Akceptant** (#3001) ustawiany w sekcji „Kto dostaje zadania" — to samo pole `workflow_definitions.reviewer` co wcześniej (§4a), tylko w innym miejscu.
- **Prawa kolumna** (#3003) rysuje diagram z aktualnego draftu (wcześniej był statyczny obrazek), opowiada narracją co się wydarzy i pokazuje listę gotowości: osiągalność etapów, droga do publikacji, akcje bez uprawnienia, akcje spoza automatyki.
- **Aktywacja jest jawna** (#3004): przełącznik „Aktywny" w nagłówku edytora + dialog potwierdzenia. Zapis **nie** włącza definicji — na usuniętym ekranie włączał, i to po cichu.

**Kanoniczne nazwy przejść to warunek działania automatyki.** `EditorialTransitionEventRecorder` mapuje zdarzenia domenowe po nazwach z `ObjectEditorialWorkflow::TRANSITIONS` (`match` z `default => null`), a `WorkflowTaskAutomation` słucha tych zdarzeń. Przejście o własnej nazwie: zadziała jako zmiana stanu, zaloguje się w historii i będzie egzekwować uprawnienie, ale **nie utworzy zadania ani powiadomienia**. Dlatego edytor mapuje etykiety kanonicznych kroków z powrotem na kanoniczne nazwy (`Zgłoś do przeglądu` → `submit_for_review`, nie `zglos_do_przegladu`), nigdy nie przepisuje nazwy istniejącego przejścia, a własny krok oznacza żółtym ostrzeżeniem w wierszu i na liście gotowości.

Walidator (`WorkflowDefinitionValidator`) egzekwuje: snake_case, initial `draft`, osiągalność BFS, istnienie permission code, istnienie akceptanta (rola/user w tenancie) + XOR, zakaz usunięcia stanu z żywymi obiektami. Cache workera keyed `definitionId@updatedAt` — edycja definicji invaliduje bez restartu.

## 7. Bezpieczeństwo i znane ograniczenia (WFL-P6-01)

Pokryte testami adwersaryjnymi (`WorkflowSecurityApiTest` + suity per-obszar):

- **Cross-tenant**: discovery / log / taski / notyfikacje / definicje = 0 wyników dla obcego tenanta; przejście na obiekcie obcego tenanta → 404 (bez existence oracle).
- **Eskalacje**: raw PATCH `status` nie omija maszyny (409 na nieosiągalny stan); bulk per-action; task/notification IDOR → 403.
- **Race**: podwójny `approve` → drugi 409, bez duplikatu w logu ani duplikatu zamknięcia tasku.

**Znane ograniczenie — self-approve (świadoma decyzja MVP):** autor submitu posiadający `workflow.approve_reject` **może** zatwierdzić własny submit — brak zasady four-eyes w MVP. Zabezpieczone testem `WorkflowSecurityApiTest::selfApproveIsAllowedInMvp` (pin). Kandydat do Fazy 2 (opcjonalna polityka four-eyes per definicja). Odwrócenie decyzji musi świadomie złamać ten test.

## 8. Skąd co czytać (mapa kodu)

- Kontrakty + statyczna maszyna: `apps/api/src/Workflow/Contracts/` (m.in. `TaskAssignee`, `EditorialWorkflowProviderInterface::reviewerFor`), `apps/api/config/packages/workflow.yaml`.
- Guardy/log/eventy: `apps/api/src/Workflow/Infrastructure/EventSubscriber/`, `apps/api/src/Catalog/Infrastructure/Workflow/`.
- Provider definicji + walidator + routing akceptanta: `apps/api/src/Workflow/Application/` (`EditorialWorkflowProvider`, `WorkflowDefinitionValidator`).
- Taski + automatyka: `apps/api/src/Workflow/Domain/Entity/WorkflowTask.php`, `.../Infrastructure/Messenger/WorkflowTaskAutomation.php`.
- Cross-BC seamy do wzbogaceń: `apps/api/src/Identity/Contracts/Directory/UserDirectoryInterface.php` (+ `Application/SqlUserDirectory` — id→imię), `apps/api/src/Catalog/Contracts/Query/ObjectSummaryPort.php` (+ `Application/Query/ObjectSummaryReader` — batch id→{title,sku,kind}).
- FE: `apps/admin/src/features/workflow/` (hub, `WorkflowTaskCard`, `TasksPanel`, `ReviewQueuePage`, `task-presentation`), `apps/admin/src/features/settings/workflow/` (lista + edytor definicji: `PlacesSection`, `TransitionsSection`, `ReviewerSection`, `FlowPreview`, `TemplatePicker`, `flow-vocabulary`, `flow-analysis`, `flow-templates`), `.../features/catalog/products/components/workflow-*` (kontrolka + historia), `.../features/dashboard/components/MyTasksCard.tsx` (widget Pulpitu), `.../lib/workflow/` (`api`, `tasks-api`, `definitions-api`, `directory-api`).
