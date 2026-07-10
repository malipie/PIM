# 0030. AI content generation — agent-native tools, grounded recipes, zero auto-write

- **Status:** accepted
- **Date:** 2026-07-08
- **Deciders:** Marcin (operator), architekt rozwiązań (agent)

> Zaakceptowany w tickecie AICG-P0-01 (epik AICG, #2325). Plan architektury: `Project Plan/UI/feature-ai-content-generation.md` (§2 decyzje A–E zatwierdzone przez operatora 2026-07-08, §12 szkic tego ADR). Backlog: `Project Plan/feature-aicg-tickets.md`.
>
> **Nota o numerze:** plan §12 zakładał 0026, backlog pierwotnie rezerwował 0028. Oba nieaktualne: 0026 = dashboard read model, 0027 = catalog PDF, **0028 zarezerwowany równolegle przez epik GRID** (`feature-grid-tickets.md`, attribute sort strategy), **0029 przez epik WFL** (`feature-workflow-tickets.md`, workflow engine). Pierwszy numer wolny od plików ORAZ rezerwacji backlogowych = **0030**. Weryfikacja: `ls docs/adr/` + grep rezerwacji w backlogach epików, nie sam `ls`.

## Context and Problem Statement

Agent layer (epik 0.7, `src/Agent/`, ADR-0024) ma kilkanaście tooli operujących na danych strukturalnych (bulk-edit, kategorie, publish, export/feed) — ale ani jednego, który **pisze prozę**. Operator chce generować treść pól tekstowych (opis produktu, treść SEO, docelowo tłumaczenia) „mądrzej niż samo generowanie tekstu": treść ma powstawać **z posiadanych, ustrukturyzowanych faktów produktu**, nie z powietrza. Rynek potwierdza framing (Akeneo *AI Configurations* — grounding w atrybutach zamiast halucynacji; Plytix — „AI, które zna Twoje produkty").

Zanim powstanie kod epiku AICG (22 tickety, M0–M6), trzeba rozstrzygnąć decyzje, które inaczej byłyby renegocjowane w każdym milestone:

1. **Gdzie żyje generowanie treści** — nowa infrastruktura („Content Studio") czy toole w istniejącym rejestrze agenta?
2. **Wyjątek architektoniczny:** ADR-0024 definiuje tool jako „port `Contracts` w BC-gospodarzu + cienki adapter". Toole treści nie mają BC-gospodarza — ich „silnikiem" jest LLM warstwy Agent. Czy to łamie regułę, czy ją świadomie rozszerza?
3. **Umiejscowienie konfiguracji** — `ContentRecipe` (przepis) i `BrandVoiceProfile` (głos marki) w `src/Agent/` (znikają z modułem — removability) czy w `Shared`/Settings (przeżywają usunięcie agenta jako sieroty bez LLM-a)?
4. **Kontrakt groundingu** — co dokładnie dostaje model i jak wykluczamy dopisywanie nieistniejących parametrów (ryzyko R1)?
5. **Ścieżka zapisu** — czy wygenerowana treść może trafić do pola bezpośrednio?
6. **SEO** — dedykowane typy/pola („meta_description") czy zwykłe atrybuty?
7. **Model i dostawca** — managed default (jak Akeneo GPT-4.1-mini) czy istniejące Claude + BYOK?

## Decision Drivers

- **Reuse ponad rozbudowę:** `src/Agent/` (65 plików) ma pętlę, rejestr, egzekutor z RBAC/limitami/audytem, approval przez `pending_changes`, koszty, BYOK, wybór modelu. Nowa infra = nieuzasadniona duplikacja.
- **Anty-halucynacja jako wymóg produktowy** (ryzyko R1) — model nie może „dopisać" parametrów, których nie ma w danych; audyt „skąd ten fakt" musi być trwały.
- **Removability (open-core, ADR-0024a)** — `rm -rf src/Agent` musi zostawiać zielony PIM; byty bezużyteczne bez LLM-a nie mogą zostać w core.
- **Zero drugiej ścieżki zapisu** — treść idzie tą samą bramką co edycja masowa (walidacje, provenance, undo, audyt).
- **Elastyczność ponad konwencję** — reguły SEO różnią się per tenant/kanał; sztywne pola w schemacie betonują założenia.
- **Zgodność z Deptrac** — Agent sięga Catalog/Channel wyłącznie przez `*\Contracts\*` (ADR-0013/0015).

## Considered Options

1. **Osobny moduł „Content Studio"** — dedykowana infra generowania (własny run-loop, własny approval, własne UI).
2. **Toole treści `kind=Write` w istniejącym `ToolRegistry`** + cienkie byty konfiguracyjne (`ContentRecipe`, `BrandVoiceProfile`) w `src/Agent/`; grounding jako jawny, kuratorowany kontrakt; zapis wyłącznie przez `pending_changes`.
3. **Prosty proxy do LLM** („wyślij pola do czatu") — bez przepisu, głosu marki i groundingu kontraktowanego; użytkownik sam pilnuje faktów.

## Decision Outcome

Chosen option: **Option 2** — generowanie treści to **warstwa groundingu nad istniejącym agentem**, nie nowy build. Siedem rozstrzygnięć:

### (1) Toole treści = `kind=Write` w istniejącym `ToolRegistry` — zero nowej infry agenta

`generate_product_description`, `generate_seo_text`, `translate_value` implementują `AgentToolInterface` (auto-tag `agent.tool`) i wchodzą do istniejącego rejestru. Dostają za darmo: pętlę (`AgentLoopRunner`), egzekutor z re-checkiem RBAC + fine-grained per-attribute/locale/channel (`GuardedToolExecutor`), limity §8.5 (`AgentLimitGuard`), koszty (`AgentCostAggregator`), audyt (`AgentToolCall`), BYOK. Poprawki w rdzeniu agenta propagują się do treści automatycznie. Wzorzec wykonania 1:1 z `SuggestColumnMappingTool` (grounded suggestion — „nothing is applied by the tool itself").

### (2) Toole „agent-native" — świadomy wyjątek od „tool = adapter nad innym BC"

ADR-0024b definiuje tool jako adapter nad portem `Contracts` innego BC. Toole treści są pierwszymi, których **silnikiem jest sam LLM warstwy Agent** — nie orkiestrują cudzego silnika, tylko produkują treść. Wyjątek jest świadomy i wąski: dotyczy wyłącznie tooli, których wartość powstaje w wywołaniu modelu; wszystko, co dotyka danych innych BC (odczyt faktów, zapis propozycji), nadal idzie przez `*\Contracts\*` (grounding czyta `attributes_indexed` przez port Catalog; zapis przez `Catalog/Contracts/PendingChanges`). Reguła ADR-0024 pozostaje domyślna dla tooli orkiestrujących.

### (3) `ContentRecipe` + `BrandVoiceProfile` żyją w `src/Agent/` (removability)

Przepis (target-pole, atrybuty-źródła, ograniczenia formatu/długości/SEO, ton) i głos marki (ton, glosariusz, banned words, przykłady) są **bezużyteczne bez LLM-a** — słusznie znikają razem z modułem przy `rm -rf src/Agent` (test removability w CI, ADR-0024a). Odrzucono `Shared`/Settings: przeżywałyby usunięcie agenta jako martwa konfiguracja. Obie encje TenantScoped + RLS; referencje cross-BC (`object_type_id`, `brand_voice_id`) jako bare UUID (ADR-0015).

### (4) Grounding kontraktowany: fakty × instrukcje + kontrakt anty-halucynacyjny

Model dostaje **fakty** (atrybuty-źródła z `attributes_indexed` per przepis + kontekst kanału/locale wg publication profile ADR-0018 + wartości pokrewne w innych locale jako źródła tłumaczeń) oraz **instrukcje** (przepis + głos marki + reguły SEO) — nic więcej. System-prompt niesie twardy kontrakt: „używaj wyłącznie dostarczonych faktów; nie podawaj parametrów, których nie ma w danych źródłowych". Za mało faktów (bramka kompletności, reuse `CompletenessReportTool`/`objects.completeness`) → tool zwraca `insufficient_grounding` jako tool_result, **nie generuje**. Po generacji zapisujemy audyt „skąd fakt": `provenance_meta` rozszerzone o `source_attributes: string[]` + `recipe_id` (`docs/api/jsonb-schemas.md §5`).

### (5) Zero auto-zapisu — treść zawsze przez `pending_changes` + akceptację człowieka

`kind=Write` → materializacja propozycji (before = obecna wartość, after = wygenerowana) do `pending_changes`; commit do `ObjectValue` wyłącznie po akcepcie w inboxie, przez istniejący bulk-path z `Provenance::Agent`. Nigdy zapis bezpośrednio do pola ani do cache `attributes_indexed`. Dotyczy też bulk (setki produktów → jeden batch w inboxie, Accept/Reject całości lub per wiersz).

### (6) SEO bez sztywnych pól i typów (decyzja C)

Meta title/description/opis SEO to **zwykłe atrybuty** `Text`/`Textarea`/`Wysiwyg`. Reguły SEO (długość title ~60 / meta ~155, słowo kluczowe, zakaz keyword-stuffingu) żyją w `ContentRecipe.constraints.seo` i są walidowane po generacji (`SeoRulesValidator`); naruszenie → regeneracja lub flaga w propozycji. Schema pozostaje nietknięta — elastyczność ponad konwencję.

### (7) Model = `AgentModelSelector::defaultModel` (Sonnet-tier) + BYOK (decyzja D)

Proza nie potrzebuje Opusa — `kind=Write` → `defaultModel` z env (`AGENT_MODEL_DEFAULT`), taniej i szybciej; Opus pozostaje dla `kind=Schema`. Klucz per tenant przez istniejące BYOK AES-256-GCM (ADR-0017). Bez managed default model — świadome odejście od wzorca Akeneo.

### Consequences

- **Positive:** realnie nowego kodu mało (3 toole + 2 encje + grounding + materializer + UI) — reszta to spięcie istniejących szyn; obrona w głąb za darmo (RBAC per tool-call, limity, koszty, audyt, approval, undo); poprawki rdzenia agenta propagują się do treści; audytowalna anty-halucynacja (`source_attributes` + kontrakt w prompcie + bramka kompletności + człowiek).
- **Negative:** wyjątek „agent-native" osłabia jednoznaczność reguły ADR-0024b (mitygacja: zdefiniowany wąsko, tylko silnik=LLM); `provenance_meta` rośnie o dwa pola (backward-compatible, opcjonalne); jakość treści zależy od jakości danych tenanta (mitygacja: `insufficient_grounding` zamiast zmyślania).
- **Follow-ups (hooki, poza MVP):** multimodal (obraz/PDF jako fakt — spięcie z Asset/DAM + Plate-AI); ObjectType ≠ product (razem z custom kinds, ADR-009); managed default model; własny edytor promptów z warunkami; autonomiczne/proaktywne generowanie bez akceptacji (treść nigdy do pola bez człowieka w MVP).

## Alternatives Considered

- **Osobny moduł „Content Studio"** — odrzucony: duplikuje pętlę/approval/limity/koszty/UI agenta; druga ścieżka zapisu; podwójny koszt utrzymania przy identycznej semantyce human-in-the-loop.
- **Prosty proxy do LLM bez groundingu kontraktowanego** — odrzucony: bez przepisu i kontraktu model pisze „opis buta", nie „opis TEGO buta"; brak audytu źródeł; halucynacje nieodróżnialne od faktów — dokładnie to, co rynek (Akeneo/Plytix) wskazuje jako anty-wzorzec.
- **`BrandVoiceProfile` w `Shared`/Settings** — odrzucony: przeżywa `rm -rf src/Agent` jako sierota bez konsumenta; łamie czystość testu removability.
- **Dedykowane typy/pola SEO w schemacie** — odrzucone (decyzja C): betonują konwencję jednego tenanta w modelu danych wszystkich; walidacja treści ≠ model danych.

## Links

- Plan: `Project Plan/UI/feature-ai-content-generation.md` (§2 decyzje A–E, §3 model groundingu, §6 architektura, §12 szkic)
- Backlog: `Project Plan/feature-aicg-tickets.md` (epik AICG, 22 tickety, #2325–#2346, milestone'y M0–M6)
- Related ADRs: ADR-0024 (Agent removable BC + tool registry — wzorzec i źródło wyjątku), ADR-0015 (bare UUID cross-BC), ADR-0017 (BYOK AES-256-GCM), ADR-0018 (channel publication profile — kontekst groundingu), ADR-0020 (OpenAPI custom route), ADR-009 (ObjectType — `Project Plan/01-architektura-pim.md` §13)
- Kontrakty JSONB: `docs/api/jsonb-schemas.md` §1 (`attributes_indexed`), §5 (`provenance_meta` — rozszerzenie o `source_attributes`/`recipe_id` w AICG-P0-02)
- Tickets: AICG-P0-01 #2325 (finalizacja tego ADR); konsumenci: #2326 (provenance), #2331–#2333 (grounding/prompt), #2334–#2338 (toole + materializer), #2344 (translate)
