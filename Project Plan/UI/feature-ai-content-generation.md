# Plan implementacji — Generowanie treści AI do pól tekstowych: opisy + SEO (Cortex PIM)

> **Status:** draft do rozbicia na tickety (epik **AICG**). Utworzony 2026-07-08. Decyzje produktowe A–E zatwierdzone przez operatora.
> **Kontekst:** operator (Marcin) chce, by z poziomu PIM-u dało się **generować treść do pól tekstowych** (opis produktu, treść SEO) — ale „mądrzej niż samo generowanie tekstu": treść ma powstawać **na podstawie czegoś**. Ten dokument odpowiada na pytanie „na podstawie czego?" (§3) i projektuje MVP.
> **To domknięcie znanej luki:** Agent layer (epik 0.7, `src/Agent/`) jest już zbudowany i ma kilkanaście tooli operujących na **danych strukturalnych** (bulk-edit, kategorie, atrybuty, publish, export/feed) — ale **ani jednego, który pisze prozę**. Docblock `AttributeType::Wysiwyg` wprost rezerwuje rozszerzenie „Plate AI (`@udecode/plate-ai`) — Faza 2 follow-up tied to the agent layer". Ten dokument tę lukę wypełnia.
> **Powiązania:** ADR-0024 (Agent = removable BC, „tool = Contracts port + thin adapter", tool registry, single approval gate via `pending_changes`); `Project Plan/PRD/PRD-PIM-agent.md` + `Project Plan/feature-agent-tickets.md` (backlog agenta); ADR-0018 (publication profile — scope/locale/kanał); ADR-0020 (OpenAPI custom route); ADR-0009 (ObjectType); ADR-0017 (BYOK AES-256-GCM); `docs/api/jsonb-schemas.md` (envelope `attributes_indexed`, `provenance_meta`); `01-architektura-pim.md` §8.5 (limity agenta).
> **Ten dokument NIE jest jeszcze backlogiem ticketów.** Architektura + dwa briefy (§9 UI, §10 tickety). Zakres i format spójne z `feature-konfigurator-xml-plan.md`.

---

## 0. TL;DR

Budujemy **generowanie treści ugruntowane w danych** (*grounded content generation*), nie „generator tekstu". Teza, którą potwierdza rynek i nasz własny kod: **PIM ma nieuczciwą przewagę — jest już źródłem prawdy o produkcie.** Plytix reklamuje „AI, które faktycznie zna Twoje produkty" (śr. 387 atrybutów na konto); Akeneo „centralizuje atrybuty techniczne, żeby GenAI dawało treść brand-compliant, nie halucynacje". Więc nie generujemy tekstu z powietrza — **zamieniamy posiadane, ustrukturyzowane fakty w prozę**, pod kontrolą przepisu, głosu marki i akceptacji człowieka.

Trzy decyzje, które sprawiają, że to mały, wysokodźwigniowy feature, a nie duży build:

1. **Nie budujemy infry agenta — ona istnieje.** `src/Agent/` (65 plików) ma pętlę (`AgentLoopRunner`), rejestr tooli (`ToolRegistry`), kontrakt toola (`AgentToolInterface`), egzekutor z limitami/RBAC/audytem (`GuardedToolExecutor`), approval (`pending_changes` + `AgentApprovalService`), koszty (`AgentCostAggregator`), BYOK (Anthropic SDK `^0.35.1`, `AnthropicClientFactory`), wybór modelu z configu (`AgentModelSelector`), `Provenance::Agent` + `provenance_meta`, oraz UI (`features/agent/chat|inbox|history` + `cmd-k`). Dokładamy **nowe toole treści** na tych szynach.
2. **Odpowiedź na „na podstawie czego" to warstwa groundingu** (§3): fakty (własne atrybuty + kontekst kanału/locale) × instrukcje (przepis + głos marki + reguły SEO). To nowa wartość — istniejące toole gruntują się w stanie katalogu implicytnie; treść wymaga **jawnego, kuratorowanego kontraktu groundingu**, żeby nie halucynować.
3. **Reużywalny „przepis" + głos marki jako konfiguracja** (odpowiednik Akeneo *AI Configurations*): jeden zapis „jak pisać opisy w tej kategorii/kanale" → spójny głos na skalę, edytowalny w ustawieniach (nie w kodzie).

Zdanie pozycjonujące: *„Opis produktu albo treść SEO w kilka sekund — z Twoich własnych atrybutów, Twoim głosem marki, bez zmyślania; propozycja ląduje w inboxie do akceptacji, ze śladem który atrybut ją zrodził."*

---

## 1. Cel i zakres

**Cel:** redaktor treści generuje i utrzymuje pola tekstowe (opis, SEO, docelowo tłumaczenia) z danych PIM, bez copy-paste do zewnętrznego czatu i bez ryzyka, że model „dopisze" nieistniejące parametry. PIM pozostaje źródłem prawdy; wygenerowana treść to **propozycja** przechodząca przez tę samą bramkę akceptacji co edycja masowa.

**W zakresie (grube punkty):**
- **Toole treści** w istniejącym `ToolRegistry`: `generate_product_description`, `generate_seo_text` (meta/title/opis SEO), opcjonalnie `translate_value` (Faza bliska). Każdy `kind=Write` → materializacja do `pending_changes` → commit dopiero po akceptacji.
- **Warstwa groundingu (§3)** — fakty (atrybuty źródłowe + kontekst kanału/locale) + instrukcje (przepis, głos marki, reguły formatu/długości/SEO).
- **`ContentRecipe`** — reużywalny „przepis" (target-pole, atrybuty-źródła, ograniczenia, wskazówka tonu), edytowalny w ustawieniach.
- **`BrandVoiceProfile`** — głos marki (ton, glosariusz, do/don't, przykłady) per tenant/marka, wstrzykiwany do promptu (decyzja B).
- **Dwa tryby uruchomienia** — inline „Ask AI" na polu w formularzu produktu (manual, 1 rekord) + **bulk** po zaznaczeniu/filtrze (setki rekordów → batch do inboxu).
- **Ślad pochodzenia** — `Provenance::Agent` + `provenance_meta` rozszerzone o **użyte atrybuty-źródła** i **`recipe_id`** (audyt „skąd ten fakt").

**Poza zakresem (świadomie; hooki):**
- **Sztywne pola/typy SEO** (dedykowany typ „meta_description", „grupa SEO") — **decyzja C: nie wprowadzamy żadnych sztywniaków.** SEO to zwykłe atrybuty `Text`/`Textarea`/`Wysiwyg`; regułę SEO (długość, słowo kluczowe) niesie **przepis**, nie schema.
- **Multimodal (generowanie z obrazu/PDF)** — Akeneo to ma (ekstrakcja z mediów). U nas hook Faza 2 (spięcie z Asset/DAM + rozszerzenie Plate-AI z docblocka `Wysiwyg`).
- **Autonomiczne generowanie bez akceptacji** — `src/Agent/Application/Proactive/` istnieje, ale treść **nigdy** nie trafia do pola bez człowieka (inaczej niż sugestie proaktywne read-only).
- **Własny edytor promptów z warunkami/skryptami** — MVP ma przepis o zamkniętym zestawie parametrów; dowolna logika = hook.
- **Domyślny model „z pudełka"** (jak Akeneo GPT-4.1-mini managed) — **decyzja D: zostajemy przy Claude + istniejące BYOK**, konfigurowane w ustawieniach.
- **Generowanie do ObjectType ≠ product** — MVP tylko produkt (+ warianty).

**Relacja do sąsiednich feature'ów:**

| Feature | Co robi | Granica względem generowania treści |
|---|---|---|
| **Agent layer (0.7)** | Pętla + toole operacyjne + approval + koszty + UI | Generowanie treści **to nowe toole w tym samym rejestrze** — reuse 100% infry. Nowość: warstwa groundingu treści. |
| **`SuggestColumnMappingTool` / `SuggestFeedStructureTool`** | AI-asysta: model proponuje, nic nie aplikuje bez usera | **Wzorzec 1:1** dla tooli treści — grounded suggestion + human-in-the-loop. Nasze toole dokładają zapis do pola (przez approval). |
| **Catalog (`ObjectValue`, `attributes_indexed`)** | Wartości atrybutów + cache | Grounding **czyta** `attributes_indexed`; zapis **wyłącznie** przez `pending_changes` → bulk-path → `ObjectValue` (nigdy bezpośrednio w cache). |
| **Channel + PublicationProfile (ADR-0018)** | Scope atrybutów/locale per kanał | Kontekst generowania (kanał/locale) + zestaw atrybutów-źródeł = reuse profilu publikacji. |

---

## 2. Decyzje produktowe (zatwierdzone przez operatora 2026-07-08)

| # | Decyzja | Wybór | Konsekwencja architektoniczna |
|---|---------|-------|-------------------------------|
| A | Framing | **Toole w istniejącym agencie** + cienki byt `ContentRecipe` + afordancja „Ask AI" na polu (nie osobne „Content Studio") | Reuse całej infry agenta; nowego kodu domenowego mało. Fits ADR-0024. |
| B | Głos marki | **Nowy byt `BrandVoiceProfile`** per tenant/marka (ton, glosariusz, do/don't, przykłady) | Wstrzykiwany do promptu przez `AgentSystemPromptBuilder`. Luka w repo — budujemy od zera. |
| C | SEO | **Zwykłe atrybuty, zero sztywnych pól/typów SEO** | Reguły SEO (długość title/meta, słowo kluczowe) w `ContentRecipe`, nie w schema. Elastyczność ponad konwencję. |
| D | Model / dostawca | **Istniejące Claude + BYOK; konfiguracja w ustawieniach** (bez managed default) | Reuse `AgentModelSelector` (`AGENT_MODEL_DEFAULT`/`AGENT_MODEL_SCHEMA` z env/config) + BYOK per tenant. „Cała baza pod to jest." |
| E | Deliverable | **Ten dokument** + epik **AICG** | Backlog i wireframe w §9/§10 jako briefy. |

**Decyzje domyślne (wynikają z architektury):**
- **`kind=Write`** dla tooli treści → materializacja do `pending_changes`, commit po akceptacji (semantyka `ToolKind`: „`write`/`schema` tools materialize into pending_changes and commit only after human approval").
- **Model = tier domyślny (Sonnet)** dla treści (`kind=Write` → `AgentModelSelector::modelForKind` zwraca `defaultModel`); Opus tylko dla `schema`. Proza nie potrzebuje Opusa — taniej i szybciej.
- **Umiejscowienie w Agent BC** (removable): patrz §6.1 — content-gen to pierwszy tool **„agent-native"** (jego „silnik" to LLM, nie inny BC), więc przepis + głos marki żyją w `src/Agent/` i znikają z modułem.
- **Grounding czyta `attributes_indexed`**, zapis nigdy nie dotyka cache bezpośrednio (jsonb-schemas.md reguła #1).

---

## 3. Sedno — „na podstawie czego?" (model groundingu)

Odpowiedź, którą potwierdza rynek (Akeneo, Plytix) i nasz kod. Grounding = **fakty** (z czego model pisze) × **instrukcje** (jak pisze). Model dostaje jedne i drugie; kontrakt anty-halucynacyjny mówi „używaj wyłącznie dostarczonych faktów".

### 3a. Fakty — z czego model pisze
1. **Własne atrybuty strukturalne produktu (rdzeń).** Wybrane w przepisie atrybuty-źródła (materiał, wymiary, cechy, kolor, kategoria, marka…). Czytane z `attributes_indexed` (envelope `{value, locale, channel, provenance}`). To jest cała różnica między „napisz opis buta" a „napisz opis TEGO buta". *Rynek: Plytix „knows your products"; Akeneo „centralizes technical attributes".*
2. **Kontekst kanału + locale.** Który kanał (B2B/B2C, marketplace) i który język — dobiera zestaw atrybutów-źródeł (publication profile ADR-0018) i wariant tonu. Tłumaczenie = generowanie z wartości w locale-źródłowym jako faktach.
3. **Wartości pokrewne w innych językach / kanałach.** Istniejący opis PL jako fakt-źródło dla EN (spójność, nie re-wymyślanie).
4. **Media produktu (multimodal)** — zdjęcie/PDF jako grounding wizualny. *Akeneo ekstrahuje z obrazów/PDF.* **Poza MVP** (hook — Asset/DAM + Plate-AI).
5. **Bramka kompletności.** Generuj tylko, gdy jest z czego: `CompletenessReportTool` + `objects.completeness`. Za mało faktów → tool zwraca „insufficient grounding", nie zmyśla.

### 3b. Instrukcje — jak model pisze
6. **`ContentRecipe` (przepis).** Reużywalna konfiguracja (odpowiednik Akeneo *AI Configurations*): target-pole, lista atrybutów-źródeł, ograniczenia (długość, format `plain|html`, reguły SEO: słowo kluczowe, limit ~60/~155 zn.), wskazówka tonu, ObjectType/kategoria, do której się stosuje. Jeden przepis → spójny głos na tysiącach produktów.
7. **`BrandVoiceProfile` (głos marki).** Ton (np. „ekspercki, zwięzły"), glosariusz (terminy narzucone), słowa zakazane, 1–2 przykłady „tak/nie". Wstrzykiwany do system-promptu. *Rynek: Akeneo „every product follows the same tone, format, structure".*
8. **Reguły SEO jako parametry przepisu (nie pola).** Długości, słowo kluczowe, zakaz keyword-stuffingu — walidowane po generacji; naruszenie → regeneracja/flag. **Decyzja C: żyją w przepisie, nie w schemacie.**

**Kontrakt anty-halucynacyjny (przekrojowy):** system-prompt + tool-result zawierają wyłącznie dostarczone fakty; instrukcja „nie podawaj parametrów, których nie ma w danych źródłowych"; po generacji zapis **których atrybutów** użyto do `provenance_meta.source_attributes`; obowiązkowa akceptacja w inboxie (diff = plan) przed zapisem do pola.

---

## 4. Stan obecny (as-is) — co reużywamy

Zbadane i **zweryfikowane w kodzie 2026-07-08** (nie z pamięci — pliki istnieją).

| Klocek | Lokalizacja | Rola w generowaniu treści |
|--------|-------------|----------------------------|
| **`AgentToolInterface`** (P0-07 #1950) | `Agent/Application/Tool/AgentToolInterface.php` | Kontrakt nowego toola: `name/description/parametersSchema/requiredPermission/kind/execute`. Dodanie toola = implementacja interfejsu (auto-tag `agent.tool`) + port host-BC. **Zero zmian w pętli.** |
| **`ToolRegistry` + `GuardedToolExecutor`** (P1-02 #1954) | `Agent/Application/Tool/` | Rejestr + egzekutor: RBAC re-check, fine-grained per-attribute/locale/channel, „forbidden" jako tool_result (nie wyjątek), zapis `AgentToolCall` per call. Toole treści dostają to za darmo. |
| **`ToolKind`** (Read/Write/Schema/Action) | `Agent/Application/Tool/ToolKind.php` | Toole treści = `Write` → pending_changes + approval. Steruje też wyborem modelu. |
| **`SuggestColumnMappingTool` / `SuggestFeedStructureTool`** (P8-02 #1984) | `Agent/Application/Tool/` | **Wzorzec grounded-suggestion:** tool zwraca fakty + baseline, model proponuje, „nothing is applied by the tool itself". Kalka dla tooli treści. |
| **`AgentLoopRunner` + `AgentTurnService` + `AgentRunHandler`** | `Agent/Application/Run/` | Pętla async (`AgentRunMessage` → queue `import`, tenant+RLS GUC rebind w workerze). Reuse 1:1. |
| **`AgentSystemPromptBuilder`** | `Agent/Application/Run/AgentSystemPromptBuilder.php` | **Punkt wstrzyknięcia głosu marki + przepisu** do system-promptu. |
| **`AgentModelSelector`** (P0-06 #1949) | `Agent/Infrastructure/Anthropic/AgentModelSelector.php` | Model z configu (`AGENT_MODEL_DEFAULT`/`_SCHEMA`), nie hardcode. Treść → `defaultModel`. Decyzja D. |
| **`AnthropicClientFactory` + `SdkAgentLlmClient` + `AgentLlmClientInterface`** | `Agent/{Infrastructure/Anthropic,Application/Llm}/` | Wywołanie LLM + BYOK (klucz tenanta AES-256-GCM, ADR-0017). Reuse. |
| **`pending_changes` + `AgentApprovalService` + materializery** | `Catalog/Contracts/PendingChanges/`, `Catalog/Application/PendingChanges/`, `Agent/Application/Approval/` | Bramka akceptacji: diff = plan. Nowy materializer treści (wartość tekstowa) obok `BulkEditValuesMaterializer`. |
| **`AgentLimitGuard` + `AgentCostAggregator` + `UsageCostCalculator`** | `Agent/Application/{Limits,Cost}/` | Twarde limity (§8.5: 50 tool-calls/h, 100k tok/run, $20/dzień/tenant…) + koszty. Treść jest limitowana automatycznie. |
| **`Provenance::Agent` + `provenance_meta`** | `Catalog/Domain/Provenance.php`, `docs/api/jsonb-schemas.md §5` | Ślad `{agent_run_id, model, intent}` + badge „agent" w UI (P6-05). **Rozszerzamy** o `source_attributes` + `recipe_id`. |
| **`attributes_indexed` (GIN) + `AttributesIndexedRebuilder`** | `Catalog/Application/`, `docs/api/jsonb-schemas.md §1` | Szybkie źródło faktów do groundingu. Zapis nigdy tu bezpośrednio. |
| **`AttributeType`: `Text`/`Textarea`/`Wysiwyg`** | `Catalog/Domain/AttributeType.php` | Realne pola docelowe (localizable + scopable, string w `object_values.value->>'value'`). `Wysiwyg` = HTML (`@udecode/plate`). |
| **UI agenta: `features/agent/{chat,inbox,history}` + `components/agent/{cmd-k,global-cmd-k}`** | `apps/admin/src/` | Inbox (akceptacja), cmd-k (uruchomienie), chat (dialog). Dokładamy afordancję „Ask AI" na polu + ustawienia przepisów/głosu. |

**Wniosek:** realnie nowego kodu niewiele — 2–3 toole (`AgentToolInterface`), byty `ContentRecipe` + `BrandVoiceProfile` + ich CRUD/ustawienia, materializer wartości tekstowej, rozszerzenie `provenance_meta`, afordancja „Ask AI" na polu. Reszta to spięcie istniejących szyn agenta. **To trzyma feature w rozmiarze „warstwa nad agentem", nie „nowy agent".**

---

## 5. Research → zasady projektowe

Źródła w §13. Z każdego jedna zasada.

1. **Akeneo AI Configurations** — scentralizowane, reużywalne prompty enrichment w jednym miejscu; „every product follows the same tone, format, structure"; dwa tryby: manual „Ask AI" na rekordzie + automatyczny (Rules Engine, tysiące). → **Zasada:** przepis (`ContentRecipe`) jako first-class byt; manual + bulk od początku.
2. **Akeneo — grounding zamiast halucynacji** — „centralizes technical attributes, ensuring GenAI produces high-quality, brand-compliant content rather than hallucinations". → **Zasada:** fakty przychodzą z atrybutów, nie z modelu; kontrakt „tylko dostarczone fakty".
3. **Plytix — dane PIM jako paliwo** — „the value lies in the data already available… avg 387 attributes gives the model a vast amount to pull from"; generuje pod kanał (Amazon/Walmart/eBay), tagi SEO, tłumaczenia, tytuły, bullety. → **Zasada:** grounding per kanał/locale; opis, SEO, tłumaczenie to warianty tego samego mechanizmu.
4. **Plytix — lekkość dla SMB** — „lightweight enrichment". → **Zasada:** afordancja „Ask AI" na polu (1 klik), nie ciężki kreator, dla zwykłej ścieżki.
5. **Human-in-the-loop (nasz `SuggestColumnMappingTool`)** — „nothing is applied by the tool itself; suggestions for the operator to apply". → **Zasada:** treść zawsze przez `pending_changes` + akceptację; zero auto-zapisu do pola.
6. **Głos marki na skalę** (Akeneo „cohesive brand voice") — luka w naszym repo. → **Zasada:** `BrandVoiceProfile` wstrzykiwany do promptu; spójność ważniejsza niż kreatywność.
7. **Reguły SEO bez sztywnych pól** (decyzja C) — długość meta/title i słowo kluczowe to walidacja treści, nie model danych. → **Zasada:** SEO jako parametry przepisu + walidator po generacji.

---

## 6. Architektura docelowa

### 6.1 Umiejscowienie
- **Toole treści + `ContentRecipe` + `BrandVoiceProfile` → `src/Agent/`** (removable BC). Uzasadnienie: content-gen to pierwszy tool **„agent-native"** — jego „silnik" to LLM (warstwa Agent), a nie port innego BC (jak `BulkEditValuesTool` nad Catalog czy `TriggerExportTool` nad Export). Przepis i głos marki są bezużyteczne bez LLM-a, więc słusznie znikają razem z modułem przy `rm -rf src/Agent` (test removability, ADR-0024 a).
- **Zapis wartości → przez `Catalog/Contracts/PendingChanges` port** (bare UUID cross-BC, ADR-0015) — Agent nie sięga encji Catalog bezpośrednio.
- **Ustawienia (przepisy, głos marki) → obszar Settings** w adminie, obok konfiguracji agenta.

> Decyzja do potwierdzenia w mini-ADR (§12): `BrandVoiceProfile` w Agent BC (znika z modułem) vs. w `Shared`/Settings (przeżywa usunięcie agenta, ale jest sierotą bez LLM-a). Rekomendacja: **Agent BC** — spójne z removability.

### 6.2 Toole treści (kontrakt jak `AgentToolInterface`)
`generate_product_description` — `kind=Write`, `requiredPermission` np. `catalog.edit` (+ fine-grained per-attribute w GuardedToolExecutor). `parametersSchema`: `{product_id, target_attribute, locale?, channel?, recipe_id?, tone_hint?}`. `execute`: (1) rozwiąż przepis + głos marki, (2) zbierz fakty (atrybuty-źródła z `attributes_indexed`, kontekst kanału/locale), (3) sprawdź bramkę kompletności, (4) zbuduj prompt (fakty + instrukcje + kontrakt anty-halucynacyjny), (5) wywołaj LLM, (6) waliduj (długość/SEO/format), (7) **materializuj propozycję do `pending_changes`** (before = obecna wartość, after = wygenerowana) — **nie** zapisuj do pola. `generate_seo_text` analogicznie (target = atrybut „meta_*", reguły SEO z przepisu). `translate_value` (bliska Faza) — fakt-źródło = wartość w locale-źródłowym.

### 6.3 `ContentRecipe` (byt konfiguracyjny — „AI Configuration") ⏳ *(pełne DDL do rozwinięcia)*
`TenantScoped` + RLS. Pola: `code`, `name`, `object_type_id` (bare ref, MVP=product), `applies_to` (kategoria/kanał — opcjonalny scope), `target_attribute` (kod pola docelowego), `source_attributes JSONB` (lista kodów atrybutów-faktów), `constraints JSONB` (`{format: plain|html, max_len, seo:{keyword, title_len, meta_len}}`), `tone_hint`, `brand_voice_id?`. Predef seedy (opis produktu, meta SEO) `is_built_in`, klonowalne.

### 6.4 `BrandVoiceProfile` (decyzja B) ⏳
`TenantScoped`. Pola: `name`, `tone` (opis), `glossary JSONB` (`[{term, use}]`), `banned_words JSONB`, `examples JSONB` (`[{good, bad}]`), `default` (bool per tenant). Wstrzykiwany do system-promptu przez `AgentSystemPromptBuilder` (rozszerzenie istniejącego buildera o sekcję „brand voice", gdy run niesie `recipe_id`/`brand_voice_id`).

### 6.5 Pipeline groundingu ⏳
`atrybuty-źródła (attributes_indexed) + kontekst kanału/locale + kompletność` → **fakty** → `prompt = system(brand voice + kontrakt) + user(przepis + fakty)` → LLM → walidacja → `pending_changes`. Memory: bulk-path iteruje batchami z `EntityManager::clear()` (§3.10), tak jak `BulkEditValuesTool`.

### 6.6 Provenance + approval ⏳
Zapis po akceptacji: `Provenance::Agent` + `provenance_meta` = `{agent_run_id, model, intent}` **rozszerzone** o `source_attributes: [...]` (audyt „skąd fakt") i `recipe_id`. Badge „agent" na polu (P6-05) + tooltip „wygenerowano z: [atrybuty], przepis: [nazwa]".

### 6.7 Tryby uruchomienia ⏳
- **Inline „Ask AI" na polu** — przycisk przy polu `Text`/`Textarea`/`Wysiwyg` w formularzu produktu → start runu z kontekstem (`product_id`, `target_attribute`, domyślny przepis) → propozycja w inline-diff / inboxie.
- **Bulk** — zaznaczenie/filtr produktów → „Generuj opisy" → batch tooli → jeden `pending_changes` batch w inboxie (Accept/Reject całości lub per wiersz). Reuse `ResolvesSelectionScope`.

### 6.8 Model, koszty, BYOK ⏳ (decyzja D)
`kind=Write` → `AgentModelSelector::defaultModel` (Sonnet-tier, z env). BYOK per tenant (klucz AES-256-GCM). Limity `AgentLimitGuard` (§8.5) obejmują treść automatycznie. Konfiguracja (model, klucz, domyślny głos/przepis) — w ustawieniach.

### 6.9 API + OpenAPI ⏳
`ContentRecipe`/`BrandVoiceProfile` CRUD (API Platform, byty zasobowe). Uruchomienie przez istniejące `/api/agent/*` (start run z intencją + kontekstem) — bez nowego równoległego endpointu, jeśli pętla go pokrywa. Custom trasy w `v0.json` (ADR-0020).

### 6.10 RBAC ⏳
`requiredPermission` per tool (`catalog.edit`/`catalog.seo`?) + fine-grained per-attribute/locale/channel w `GuardedToolExecutor` (już działa). CRUD przepisów/głosu = uprawnienie ustawień (np. `settings.ai_content`).

---

## 7. Zakres MVP: IN / OUT

**IN:** toole `generate_product_description` + `generate_seo_text` (`kind=Write`) · grounding z atrybutów + kontekst kanału/locale · `ContentRecipe` + `BrandVoiceProfile` (edytowalne w ustawieniach) · kontrakt anty-halucynacyjny + `provenance_meta.source_attributes` · manual „Ask AI" + bulk · approval przez inbox · Claude + BYOK + limity/koszty (istniejące) · pola `Text`/`Textarea`/`Wysiwyg` · PL + multi-locale.

**OUT (hooki):** sztywne pola/typy SEO (decyzja C) · multimodal z obrazu/PDF · auto-zapis bez akceptacji · własny edytor promptów z warunkami · managed default model · ObjectType ≠ product · proaktywne generowanie treści bez zaznaczenia.

---

## 8. Ryzyka i otwarte decyzje
- **R1 — halucynacja parametrów.** Mitygacja: kontrakt „tylko dostarczone fakty" + walidacja + akceptacja człowieka + `source_attributes` w audycie.
- **R2 — słaby grounding = słaba treść.** Mitygacja: bramka kompletności; „insufficient grounding" zamiast zmyślania.
- **R3 — koszt przy bulk (setki produktów).** Mitygacja: istniejące limity `$/dzień/tenant`; batch z podglądem kosztu przed uruchomieniem (jak inne runy).
- **R4 — removability głosu marki** (otwarta decyzja §6.1). Rekomendacja: Agent BC.
- **R5 — „agent-native tool" łamie regułę „tool = adapter nad innym BC".** Mitygacja: udokumentować w mini-ADR jako świadomy wyjątek (silnik = LLM warstwy Agent).

---

## 9. Brief dla agenta UI ⏳
*(Do rozwinięcia.)* (a) Afordancja „Ask AI" przy polach tekstowych w formularzu produktu (ikona + inline-diff propozycji). (b) Bulk „Generuj opisy/SEO" z listy (reuse selection + inbox). (c) Ustawienia: CRUD `ContentRecipe` (target, atrybuty-źródła, ograniczenia SEO, ton) + `BrandVoiceProfile` (ton, glosariusz, przykłady). (d) Badge „agent" + tooltip pochodzenia na wygenerowanych polach. Reuse `features/agent/inbox`, `components/agent/cmd-k`, `@udecode/plate` dla Wysiwyg.

## 10. Brief dla agenta ticketów — proponowany epik AICG ⏳
*(Do rozwinięcia.)* Wstępne milestone'y:
- **M0** — `ContentRecipe` + `BrandVoiceProfile` (encje + schema + CRUD API + seedy predef).
- **M1** — grounding service (czyta atrybuty-źródła + kontekst + bramka kompletności) + rozszerzenie `AgentSystemPromptBuilder` o głos marki/przepis.
- **M2** — `generate_product_description` tool (`kind=Write`) + materializer wartości tekstowej + `provenance_meta.source_attributes`.
- **M3** — `generate_seo_text` tool + walidator reguł SEO (długości, słowo kluczowe).
- **M4** — UI: afordancja „Ask AI" na polu + inline-diff + badge pochodzenia.
- **M5** — UI: bulk generate z listy (selection → inbox) + ustawienia przepisów/głosu.
- **M6** — (opcja) `translate_value` + hook multimodal; hardening + red-team promptów (reuse `PromptInjectionRedTeamTest`).

## 11. Integracja z istniejącym kodem (zweryfikowane punkty zaczepienia)
- Nowe toole: `apps/api/src/Agent/Application/Tool/GenerateProductDescriptionTool.php` itd. (impl `AgentToolInterface`, auto-tag `agent.tool`, rejestr `ToolRegistry`).
- Prompt: rozszerzenie `apps/api/src/Agent/Application/Run/AgentSystemPromptBuilder.php`.
- Zapis: `apps/api/src/Catalog/Contracts/PendingChanges/PendingChangesPort.php` + nowy materializer w `Catalog/Application/PendingChanges/`.
- Provenance: `apps/api/src/Catalog/Domain/Provenance.php` (`Agent` już jest) + `provenance_meta` (`docs/api/jsonb-schemas.md §5` — dopisać `source_attributes`/`recipe_id`).
- UI: `apps/admin/src/features/agent/inbox` (akceptacja), pola w formularzu produktu (afordancja), Settings (przepisy/głos).

## 12. Mini-ADR szkic — ADR-0026 (do dopisania po akceptacji) ⏳
> Numer: **0026** (0025 rezerwują Katalogi PDF; koordynować przy merge).

„Generowanie treści AI jako toole `kind=Write` w Agent BC; pierwsze toole **agent-native** (silnik = LLM, nie port innego BC) — świadomy wyjątek od 'tool = adapter'; grounding kontraktowany (`ContentRecipe` fakty+instrukcje), `BrandVoiceProfile` w Agent BC (removability), zero auto-zapisu (pending_changes + approval), `provenance_meta` rozszerzone o `source_attributes`. SEO bez sztywnych pól (reguły w przepisie)." Kontekst/alternatywy/konsekwencje wg MADR 4.0.

## 13. Źródła (research 2026-07-08)
- Akeneo — AI Configurations Overview: https://help.akeneo.com/using-ai-in-the-pim/ai-configurations-overview
- Akeneo — Using Generative AI (manual „Ask AI" + Rules Engine): https://help.akeneo.com/using-ai-in-the-pim/executing-ai-configurations
- Akeneo — GenAI a tworzenie treści (grounding vs halucynacje): https://www.akeneo.com/blog/the-product-data-paradox-how-genai-revolutionizes-content-creation/
- Akeneo PXM Studio: https://www.akeneo.com/akeneo-pxm-studio/
- Plytix — AI Product Descriptions Generator: https://www.plytix.com/ai-product-descriptions-generator
- Plytix — AI Content Studio („knows your products"): https://www.plytix.com/ai-content-studio/
