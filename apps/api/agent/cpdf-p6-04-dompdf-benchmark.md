# CPDF-P6-04 — Dompdf catalog render benchmark log

> **Ticket:** [#2307](https://github.com/malipie/PIM/issues/2307)
>
> Append-only run log produced by `bin/console pim:catalog:benchmark`.
> Each row renders the archetype at the given product count through the
> real pipeline (values → mapper → Twig → Dompdf) inside one process,
> sizes ascending. `peak_mb` is `memory_get_peak_usage(true)` — the
> worker guardrail number (CLAUDE.md §3.10 alert fires at 256 MB).
> Decision A: the numbers picked the `CATALOG_PDF_MAX_ITEMS` default
> (see `apps/api/.env`); exceeding it on Dompdf raises
> `CatalogTooLargeException` ("enable Gotenberg") instead of an OOM.

| timestamp | tenant | template | products | pages | elapsed_ms | peak_mb | pdf_kb |
|---|---|---|---|---|---|---|---|
| 2026-07-10T02:17:18+00:00 | demo | grid | 50 | 8 | 272.9 | 86.5 | 88.0 |
| 2026-07-10T02:17:18+00:00 | demo | grid | 100 | 13 | 306.8 | 96.5 | 156.2 |
| 2026-07-10T02:17:18+00:00 | demo | grid | 150 | 18 | 484.7 | 104.5 | 236.2 |
| 2026-07-10T02:17:18+00:00 | demo | grid | 300 | 27 | 910.6 | 126.5 | 430.3 |
| 2026-07-10T02:17:18+00:00 | demo | grid | 500 | 48 | 1824.8 | 150.5 | 709.5 |
| 2026-07-10T02:17:31+00:00 | demo | pricelist | 50 | 2 | 211.5 | 84.5 | 22.7 |
| 2026-07-10T02:17:31+00:00 | demo | pricelist | 100 | 4 | 153.2 | 90.5 | 27.1 |
| 2026-07-10T02:17:31+00:00 | demo | pricelist | 150 | 6 | 214.9 | 94.5 | 37.7 |
| 2026-07-10T02:17:31+00:00 | demo | pricelist | 300 | 12 | 475.5 | 112.5 | 49.4 |
| 2026-07-10T02:17:31+00:00 | demo | pricelist | 500 | 20 | 971.6 | 140.5 | 69.0 |
| 2026-07-10T02:17:39+00:00 | demo | sheet | 50 | 50 | 397.2 | 82.5 | 58.3 |
| 2026-07-10T02:17:39+00:00 | demo | sheet | 100 | 100 | 480.0 | 92.5 | 96.8 |
| 2026-07-10T02:17:39+00:00 | demo | sheet | 150 | 150 | 687.1 | 98.5 | 139.6 |
| 2026-07-10T02:17:39+00:00 | demo | sheet | 300 | 300 | 1742.7 | 112.5 | 229.0 |
| 2026-07-10T02:17:39+00:00 | demo | sheet | 500 | 500 | 4490.2 | 126.5 | 377.5 |
