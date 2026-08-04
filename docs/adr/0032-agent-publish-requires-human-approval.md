# ADR-0032 — Publikacja do kanału przez agenta wymaga akceptacji człowieka

- **Status**: Accepted
- **Data**: 2026-08-04
- **Kontekst ticketu**: #2742 (finding 🟡 z całościowego code review 2026-08-04)
- **Powiązane**: ADR-0024 (removable Agent BC), CLAUDE.md §„Bezpieczeństwo agenta", epiki 0.8 (BaseLinker) / 0.9 (Shopify)

## Kontekst

`PublishToChannelTool` jest zadeklarowany jako `ToolKind::Action`, czyli **wykonuje się natychmiast**, bez materializacji do `pending_changes`. Wszystkie pozostałe narzędzia zapisujące agenta (`BulkEditValues`, `AdjustNumericValues`, `AssignCategories`, `CreateUpdateAttribute*`, `CreateAttributesFromSchema`, narzędzia contentowe) są `ToolKind::Write` i przechodzą przez inbox akceptacji.

Dziś jest to nieszkodliwe: `ChannelPublishPort` ma domyślną implementację `UnavailableChannelPublisher` (`available=false`), więc narzędzie zwraca odmowę zamiast czegokolwiek publikować. Zabezpieczenia, które **działają** już teraz: uprawnienie `publications.publish_unpublish` oraz scope kanałowy użytkownika (`canEditChannel`) sprawdzany przed wywołaniem portu.

Problem pojawia się w momencie dostarczenia pierwszego konektora (epik 0.8 lub 0.9): narzędzie zacznie realnie wypychać produkty do zewnętrznego sklepu **bez człowieka w pętli**.

## Decyzja

**Wariant A: publikacja przez agenta przechodzi przez `pending_changes`** — tak jak każdy inny zapis agenta.

Uzasadnienie:

1. **Skutki są zewnętrzne i nieodwracalne w sensie biznesowym.** Cofnięcie publikacji cofa stan w PIM i w sklepie, ale nie cofa tego, że oferta była widoczna dla klientów, zdążyła zostać zaindeksowana przez wyszukiwarkę albo pobrana przez porównywarkę. „Odwracalne przez unpublish" opisuje stan bazy, nie świat.
2. **Reguła projektu jest jednoznaczna**: CLAUDE.md — „operacje destrukcyjne wymagają człowieka w MVP", a agent tworzy wpisy w `pending_changes` z akceptacją w UI. Publikacja niekompletnego lub błędnie opisanego produktu do sklepu jest kosztowniejsza niż większość operacji, które już dziś wymagają akceptacji.
3. **Asymetria kosztu.** Koszt fałszywego alarmu = jedno kliknięcie akceptacji. Koszt braku bramki = produkt z halucynowanym opisem w sklepie klienta.
4. **RBAC to za mało.** `publications.publish_unpublish` mówi, że *użytkownik* ma prawo publikować — nie że *ten konkretny wybór obiektów zaproponowany przez model* jest poprawny. Approval flow rozstrzyga dokładnie to drugie.

## Konsekwencje

- `PublishToChannelTool` zmienia `kind()` na `ToolKind::Write` i materializuje żądanie do `pending_changes` (payload: `channel_code` + `object_ids`), a faktyczne wywołanie `ChannelPublishPort::publishSelection()` przenosi się do commitu w `AgentApprovalService::approve()` — dokładnie tą ścieżką, którą idą pozostałe write-toole.
- **Zmiana jest wymagana PRZED merge pierwszego konektora** (epik 0.8 / 0.9). Do tego czasu port jest `available=false`, więc narzędzie i tak nic nie publikuje — dlatego decyzja jest zapisana teraz, a implementacja jest pozycją checklisty tamtych epików, nie martwym kodem dziś.
- `GenerateFeedTool` pozostaje `ToolKind::Action`: generuje plik feedu po stronie PIM (artefakt do pobrania), nie wypycha danych do cudzego systemu — skutek jest wewnętrzny i powtarzalny.

## Odrzucone alternatywy

- **Wariant B — udokumentowany wyjątek („publish ≠ destructive")**: odrzucony z powodów 1 i 3 powyżej. Sam fakt, że istnieje operacja odwrotna, nie czyni skutku odwracalnym.
- **Bramka tylko na liczbie obiektów** (np. approval powyżej N sztuk): odrzucona — jeden fatalny produkt w sklepie flagowego klienta jest gorszy niż sto poprawnych; próg zachęcałby do dzielenia publikacji na porcje poniżej progu.
