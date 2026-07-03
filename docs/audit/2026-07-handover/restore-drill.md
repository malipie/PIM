# Próba generalna backup/restore (PITR) — raport (#2122, epik GOLIVE)

**Data:** 2026-07-04 · **Blok:** A (plan `Project Plan/15-plan-testow-przedprodukcyjnych.md`, sekcja A2)
**Cel:** udowodnić, że backup pgBackRest jest nie tylko skonfigurowany, ale **odtwarzalny do konkretnego momentu (PITR)**, zmierzyć RTO, przećwiczyć quirk re-grant `pim_app`.

Powtarzalny harness: [`scripts/restore-drill.sh`](../../../scripts/restore-drill.sh).

## Scenariusz (test precyzji PITR)

1. Backup inkrementalny (baza restore).
2. Insert markera **BEFORE** + WAL switch → zapis celu `T = now()`.
3. Insert markera **AFTER** + WAL switch.
4. Restore `--type=time --target=T` (odtwarza WAL tylko do T).
5. Promote + re-grant `pim_app`.
6. Asercja: **BEFORE obecny, AFTER nieobecny** → PITR wylądował dokładnie na T.

Marker przez tabelę `objects` (kanoniczną), nie deprecated `products` (którą wciąż celuje `test-pgbackrest-restore.sh` — po ObjectType ten `DELETE` to no-op, przez co tamten test byłby fałszywie zielony — osobna obserwacja).

## Wynik

```
pre-restore:  BEFORE=1 AFTER=1
post-restore: BEFORE=1 AFTER=0   (expect 1 / 0)
RTO = 19s
==> PITR DRILL PASSED — recovered to T exactly (BEFORE kept, AFTER dropped).
pim_app grants OK (query succeeded; RLS shows 0 rows without tenant GUC)
```

- **PITR precyzyjny:** marker sprzed T przeżył, marker zza T zniknął. ✅
- **RTO ≈ 19 s** (stop api+db → wipe → restore 169 MB → promote → re-grant → api healthy) na dev stacku; na prod-podobnym HW + większym wolumenie proporcjonalnie więcej, ale rząd wielkości potwierdzony.
- **Owner widzi pełne dane** (211 obiektów), `pim_app` odpytuje bez `permission denied` (RLS zwraca 0 bez GUC — poprawne), **login end-to-end 200**.

## Bugi DR wykryte drillem (naprawione w tym samym PR — broken restore = blocker)

Drill odsłonił **2 realne bugi w `scripts/pim-backup-restore.sh`, które wysadziłyby każdy prawdziwy PITR** (→ [#2196](../../../issues/2196)):

1. **`--target` ze spacją łamany.** `${PGBACKREST_ARGS[*]}` (string-join) gubił cytowanie — timestamp `YYYY-MM-DD HH:MM:SS` dzielony na 2 argumenty → `ERROR: [048]: invalid command '<time>'`. **Fizycznie żaden PITR nie działał.** Fix: double-quote każdego argumentu wewnątrz `su -c '...'`.
2. **Brak promote po recovery target.** `--type=time` bez `--target-action` zostawiał klaster w read-only hot-standby (pauza recovery) — api nie mogło pisać. Fix: `--target-action=promote`.
3. **Re-grant `pim_app`** dodany do wrappera (idempotentnie) — W1-1 migracja nie re-runuje po fizycznym restore.

Runbook `docs/runbook/restore.md` uzupełniony o kroki promote + re-grant + PITR quirk.

## Wnioski

1. **Backup jest odtwarzalny do punktu w czasie** — udowodnione empirycznie, nie tylko skonfigurowane. RTO ~19 s (dev).
2. **Krytyczne:** narzędzie restore było **zepsute dla PITR** (bug cytowania) — wykryte i naprawione zanim potrzebne w realnym DR. To główna wartość tej próby generalnej.
3. Drill jest powtarzalny (miesięcznie, świadomie poza CI — DESTRUCTIVE); asertuje precyzję + RTO + grants.

## Mapa ticketów

[#2196](../../../issues/2196) bugi wrappera restore (PITR quoting + promote + re-grant) — naprawione.
