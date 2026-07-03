# Audyt przejmowalności (handover-readiness) — raport końcowy

**Epik:** GOLIVE, Blok A · **Data:** 2026-07-04
**Cel:** ocena, czy projekt PIM jest gotowy do przejęcia przez zewnętrzny software house i do go-live.

Ten katalog zbiera wyniki Bloku A planu testów przedprodukcyjnych
(`Project Plan/15-plan-testow-przedprodukcyjnych.md`). Każdy podraport ma
własny plik; poniżej synteza + audyt licencji (#2121).

## Podraporty

| Obszar | Plik | Ticket | Werdykt |
|---|---|---|---|
| Cold-start (klon → zielono z docs) | [`cold-start.md`](cold-start.md) | #2119 | ⚠️ 12 luk (3 blokery dnia 1) |
| Struktura vs ADR + dług | [`structure-and-debt.md`](structure-and-debt.md) | #2120 | ✅ PASS (drift dokumentacyjny) |
| Audyt licencji + raport końcowy | ten plik | #2121 | ✅ PASS |
| Restore drill pgBackRest + PITR | [`restore-drill.md`](restore-drill.md) | #2122 | ✅ PASS (2 bugi DR naprawione) |
| Threat model + security checklist | [`../../security/threat-model.md`](../../security/threat-model.md) | #2123 | ✅ dostarczone |
| Przygotowanie load (k6 + seed 50k) | `scripts/load/README.md` | #2124 | ✅ 8 scenariuszy zielonych |
| Fresh-install (od zera) | [`fresh-install.md`](fresh-install.md) | #2125 | ✅ PASS (1 bug detektora → #2186) |
| i18n + macierz przeglądarek | [`i18n.md`](i18n.md) | #2126 | ✅ Firefox/WebKit render OK |

## Audyt licencji (#2121)

Metoda: `composer licenses --no-dev` (PHP prod) + `pnpm licenses list --prod` (JS prod).

### PHP — 150 pakietów produkcyjnych

| Licencja | Pakiety |
|---|---|
| MIT | 144 |
| BSD-3-Clause | 3 |
| Apache-2.0 | 2 |
| LGPL-2.1-or-later | 1 (`ezyang/htmlpurifier`) |

### JavaScript — prod

| Licencja | Pakiety |
|---|---|
| MIT | 150 |
| Apache-2.0 | 3 |
| OFL-1.1 | 2 (fonty `@fontsource/inter`, `@fontsource/jetbrains-mono`) |
| MPL-2.0 OR Apache-2.0 | 1 |
| ISC / BSD-3-Clause / 0BSD | po 1 |

### Werdykt licencyjny: ✅ **BRAK blokad komercyjnych**

- **Zero GPL/AGPL/SSPL/CC-BY-NC** — brak licencji copyleft wymuszającej otwarcie kodu ani zakazującej użycia komercyjnego.
- **LGPL-2.1 (`htmlpurifier`)** — akceptowalne: biblioteka używana jako zależność (dynamiczne linkowanie/wywołanie), LGPL nie „zaraża" kodu aplikacji.
- **OFL-1.1** — Open Font License na fontach; dozwolone użycie komercyjne (fonty embeddowane w UI).
- **MPL-2.0** (file-level copyleft) — zależność biblioteczna, bez modyfikacji plików źródłowych pakietu; bez wpływu na kod PIM.

## Synteza handover-readiness

**Werdykt ogólny: GOTOWY DO PRZEJĘCIA warunkowo** — kod, architektura, testy, backup i licencje są zdrowe; blokują wyłącznie **luki onboardingu dnia pierwszego** (cold-start), wszystkie z osobnymi ticketami.

**Mocne strony:**
- Bounded-context integrity egzekwowana maszynowo (Deptrac); deprecated encje w pełni usunięte; dług minimalny (4 markery TODO).
- Backup **odtwarzalny do punktu w czasie** (PITR RTO ~19s) — a próba generalna wykryła i naprawiła 2 krytyczne bugi narzędzia restore, które wysadziłyby prawdziwy DR.
- Bramki statyczne + testy jednostkowe (1453) zielone na świeżym klonie; licencje bez blokad; dokumenty bezpieczeństwa (STRIDE systemowy + checklist) dostarczone.

**Do naprawy przed handoverem (blokery dnia 1, cold-start #2119):**
- **#2176** — brak generacji JWT keypair w onboardingu → login niemożliwy (CRITICAL).
- **#2177** — `messenger_messages` poza migracjami → worker crashloop.
- **#2181** — S3 credentials niepropagowane z `MINIO_ROOT_*` → uploady 500.
- **#2178** — komendy utrzymaniowe DB padają na roli `pim_app`.

**Dług / dokumentacja (nie-blokery):** #2179, #2180, #2182, #2183 (cold-start docs), #2186 (drift detektor), #2188/#2189 (i18n), #2191/#2192/#2193 (struktura/guardy), #2196 (restore — naprawione).

**Świadome odejścia:** WebKit/Safari console-clean niezweryfikowany autonomicznie (DNS `pim.localhost` bez `/etc/hosts`, sudo niedostępne — render OK); pełny PHPUnit lokalnie niewykonalny wg docs (CI-only, #2183); load p95 lokalnie = proxy (bezwzględny gate <300ms → Blok B na prod-podobnym HW).
