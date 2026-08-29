# Baseline `pg_stat_statements` — import strukturalny, 2026-08-29

Pierwszy wersjonowany baseline dla `AUD-OBS-002` (#3026). To pomiar kontraktowy,
nie SLO produkcyjne.

- PostgreSQL: 16 Alpine, `pg_stat_statements` 1.10;
- środowisko: izolowany projekt Docker Compose na Apple Silicon;
- baza: świeża `pim_test`, statystyki filtrowane po jej `dbid`;
- workload: `StructuralAttributeImportTest`, 7 testów / 33 asercje;
- testy: zielone w 7,970 s;
- SQL text: pominięty zgodnie z polityką; korelacja wyłącznie przez `queryid`.

Czasy służą tylko do porównania na tej samej klasie środowiska. Pierwszy
fingerprint przekracza roboczy próg 20% czasu, ale obejmuje setup/cleanup bazy
testowej wykonywany po każdym teście; nie jest na tej podstawie kandydatem do
optymalizacji produkcyjnej. Produkcyjny priorytet ustala się dopiero po ruchu
klienta według runbooka.

- stats reset: `2026-08-29 12:26:44.302491+00`
- captured at: `2026-08-29 12:30:25.843941+00`

| queryid | calls | total exec ms | mean exec ms | rows | total share |
|---:|---:|---:|---:|---:|---:|
| `-7505038394312252738` | 20 | 55.195 | 2.760 | 6978 | 29.16% |
| `-6826047775718401528` | 20 | 22.297 | 1.115 | 3630 | 11.78% |
| `-2582095462681022079` | 291 | 11.192 | 0.038 | 291 | 5.91% |
| `6508630063052076612` | 291 | 9.577 | 0.033 | 291 | 5.06% |
| `9151299462916926952` | 20 | 8.559 | 0.428 | 720 | 4.52% |
| `-3155184541120955335` | 20 | 6.312 | 0.316 | 612 | 3.33% |
| `2215974374871206120` | 291 | 6.026 | 0.021 | 291 | 3.18% |
| `3922332288970261343` | 291 | 5.715 | 0.020 | 291 | 3.02% |
| `-7549148551377347664` | 291 | 5.646 | 0.019 | 291 | 2.98% |
| `6181088207148797351` | 291 | 5.391 | 0.019 | 291 | 2.85% |
| `-4792063811817027465` | 291 | 5.311 | 0.018 | 291 | 2.81% |
| `4105018371997095437` | 280 | 4.029 | 0.014 | 225 | 2.13% |
| `-9027455942767106853` | 127 | 3.539 | 0.028 | 127 | 1.87% |
| `-8709823323251713504` | 20 | 2.456 | 0.123 | 0 | 1.30% |
| `7280278058376341991` | 72 | 2.337 | 0.032 | 0 | 1.23% |
| `3871736264573922854` | 101 | 1.703 | 0.017 | 101 | 0.90% |
| `7233309313328649423` | 280 | 1.311 | 0.005 | 225 | 0.69% |
| `5029449432829852185` | 11 | 1.300 | 0.118 | 11 | 0.69% |
| `6993886593248769249` | 100 | 1.238 | 0.012 | 100 | 0.65% |
| `8699843753718126106` | 280 | 1.143 | 0.004 | 225 | 0.60% |
