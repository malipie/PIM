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

- `GET /api/objects/{id}/workflow` — discovery: aktualny stan + dostępne przejścia z `blockers[]` (kody: brak uprawnienia, `completeness_gate`, `comment_required`). FE jest źródłem prawdy które przyciski renderować.
- `POST /api/objects/{id}/workflow/transitions/{transition}` — zastosuj przejście. Body opcjonalne `{comment}` (≤2000 znaków). 409 + `blockers` gdy przejście niedozwolone; 422 gdy `comment_required` a komentarz pusty.
- `GET /api/objects/{id}/workflow/transitions` — log przejść (kursor UUIDv7, najnowsze pierwsze).
- `POST /api/objects/{id}/workflow/request-unpublish` — prośba o depublikację (dla użytkownika bez `workflow.transition.unpublish`).
- `GET/POST/PATCH /api/workflow/tasks` — zadania (lista `mine`/`status`/`object`/`due`; tworzenie custom; complete/cancel/reassign gated assignee-or-approver).
- `GET/PATCH /api/notifications` — powiadomienia in-app (own-data, gated `workflow.view`).
- `GET/POST/PUT /api/workflow/definitions` + `enable`/`disable` — CRUD definicji (gated `workflow.manage_definitions`, §6).

Bulk: `POST /api/objects/bulk-actions/change_status` — przejście na wielu obiektach; **per-row** sprawdzenie `can()` (marketing bulk-approve = 100% zablokowane, nie eskalacja przez coarse permission).

## 4. Zadania i powiadomienia (WFL-P4)

Przejście generuje zadania automatycznie (`WorkflowTaskAutomation`, idempotentnie — obiekt nigdy nie ma dwóch OPEN tasków jednego typu):

- `submit_for_review` → task `review` dla roli `approver`.
- `reject` → zamknięcie tasku review + task `fix` dla autora submitu z komentarzem recenzenta.
- `approve` → zamknięcie tasku review.
- `request-unpublish` → task `request_unpublish` dla roli `approver`.

Powiadomienia (`WorkflowNotificationFanOut`, post-flush pipeline przez Messenger): submit → grantees `workflow.approve_reject` (minus submitter); approve/reject → autor submitu; unpublish_requested → grantees `workflow.transition.unpublish`. Zadania **nie** wysyłają własnego powiadomienia — fan-out zdarzenia już powiadomił tę samą publiczność (unika duplikatu).

## 5. Edit-lock na opublikowanych obiektach (WFL-P1-02/03)

Treść obiektu w stanie `published`/`review` jest zablokowana dla użytkowników bez `products.edit_any_state`. Próba PATCH treści → 403 `workflow_state_locked` z flagą `request_unpublish_available`. FE pokazuje `workflow-lock-banner` z akcją „poproś o depublikację". Ścieżka auto-unpublish (dla uprawnionych): edycja publikowanego obiektu automatycznie stosuje `unpublish` z kontekstem `{auto_unpublish: true}`.

## 6. Definicje per tenant (WFL-P5, ADR-0029 filar 7)

Za flagą `WORKFLOW_CUSTOM_DEFINITIONS` (default OFF). Gdy OFF — statyczna maszyna YAML `object_editorial` (100% jak dotąd). Gdy ON — `EditorialWorkflowProvider` buduje maszynę z rekordu `workflow_definitions` (ObjectType-specific > tenant-global), zachowując tę samą NAZWĘ `object_editorial` i współdzielony dispatcher, więc wszystkie listenery (guard, gate, log, event recorder) działają bez zmian. Metadane per-przejście (`permission`, `comment_required`, `completeness_gate`) czytają guardy przez `InMemoryMetadataStore`.

Builder UI: **Settings → Workflow** (gated `workflow.manage_definitions` + flaga runtime na `GET /api/auth/me` → `feature_flags.workflow_custom_definitions`). Form-based (świadomie NIE canvas-graph): stany z etykietami pl/en i kolorem, przejścia z from/to, permission dropdown, komentarz wymagany, gate kompletności. Walidator (`WorkflowDefinitionValidator`) egzekwuje: snake_case, initial `draft`, osiągalność BFS, istnienie permission code, zakaz usunięcia stanu z żywymi obiektami. Cache workera keyed `definitionId@updatedAt` — edycja definicji invaliduje bez restartu.

## 7. Bezpieczeństwo i znane ograniczenia (WFL-P6-01)

Pokryte testami adwersaryjnymi (`WorkflowSecurityApiTest` + suity per-obszar):

- **Cross-tenant**: discovery / log / taski / notyfikacje / definicje = 0 wyników dla obcego tenanta; przejście na obiekcie obcego tenanta → 404 (bez existence oracle).
- **Eskalacje**: raw PATCH `status` nie omija maszyny (409 na nieosiągalny stan); bulk per-action; task/notification IDOR → 403.
- **Race**: podwójny `approve` → drugi 409, bez duplikatu w logu ani duplikatu zamknięcia tasku.

**Znane ograniczenie — self-approve (świadoma decyzja MVP):** autor submitu posiadający `workflow.approve_reject` **może** zatwierdzić własny submit — brak zasady four-eyes w MVP. Zabezpieczone testem `WorkflowSecurityApiTest::selfApproveIsAllowedInMvp` (pin). Kandydat do Fazy 2 (opcjonalna polityka four-eyes per definicja). Odwrócenie decyzji musi świadomie złamać ten test.

## 8. Skąd co czytać (mapa kodu)

- Kontrakty + statyczna maszyna: `apps/api/src/Workflow/Contracts/`, `apps/api/config/packages/workflow.yaml`.
- Guardy/log/eventy: `apps/api/src/Workflow/Infrastructure/EventSubscriber/`, `apps/api/src/Catalog/Infrastructure/Workflow/`.
- Provider definicji + walidator: `apps/api/src/Workflow/Application/`.
- Taski + automatyka: `apps/api/src/Workflow/Domain/Entity/WorkflowTask.php`, `.../Infrastructure/Messenger/WorkflowTaskAutomation.php`.
- FE: `apps/admin/src/features/workflow/`, `.../features/catalog/products/components/workflow-*`, `.../features/settings/workflow/`, `.../lib/workflow/`.
