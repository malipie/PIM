# ADR-0025: Walidacja krzyżowa pól + conditional required na poziomie ObjectType

- **Status:** accepted
- **Data:** 2026-07-03
- **Kontekst:** epik DP (drobne poprawki), ticket DP-07 (#2037)
- **Powiązane:** ADR-0019 (ValueWriteCore — wspólny write path), ADR-009 (ObjectType first-class), UI-08.8 #263 (VisibleWhenRule), `docs/api/jsonb-schemas.md`

## Kontekst

Silnik walidacji per-atrybut (`Attribute.validation_rules` + `TypeValidator/*`) nie mieści reguł
odnoszących się do WIELU pól naraz:

- **walidacja krzyżowa**: `weight_net ≤ weight_gross`, `threads ≥ cores`, `hdmi_2_1_ports ≤ hdmi_ports`,
- **conditional required**: `max_sd_card_gb` wymagane, gdy `expandable_storage = true`.

Takie reguły opisują kształt CAŁEGO obiektu, więc ich naturalnym właścicielem jest `ObjectType`
(analogicznie do `completeness_rules`), nie pojedynczy atrybut.

## Decyzja

### Storage

Nowa kolumna `object_types.validation_rules JSONB DEFAULT '[]' NOT NULL` — lista reguł.
Wzorzec identyczny z `completeness_rules` (pole encji + jsonb w ORM + grupa `admin:read/write`
w serializerze). Pole jest schema-shaping → dopisane do `ObjectTypeSchemaVersionBumper::SCHEMA_FIELDS`.

### DSL — dwa rodzaje reguł

```json
{"type": "compare", "left": "weight_net", "op": "lte", "right": "weight_gross"}
{"type": "require_when",
 "if": {"field": "expandable_storage", "operator": "equals", "value": true},
 "then": {"required": "max_sd_card_gb"}}
```

- `compare.op` ∈ `lt | lte | gt | gte | eq | neq`. Obie strony muszą być atrybutami numerycznymi
  (`number` / `metric` / `price`) i **tego samego typu** (guard przy zapisie reguł; konwersja
  jednostek/walut świadomie poza zakresem — patrz DP-09 #2038).
- `require_when.if` **reużywa `VisibleWhenRule`** (`{field, operator: 'equals', value}`) — jeden
  kształt warunku w całym systemie (spójność z conditional visibility na junction
  `attribute_group_attributes.visible_when`). Rozszerzenia operatorów (not_equals/in/composites)
  dziedziczą się automatycznie, gdy Faza 1 rozszerzy `VisibleWhenRule`.

VO domenowe: `CrossFieldRule` (interfejs), `CompareRule`, `RequireWhenRule`, `CrossFieldViolation`,
parser `CrossFieldRules::fromArray` (strict — złe kształty rzucają `InvalidArgumentException` na
krawędzi domeny, JSONB nigdy nie niesie śmieci; wzorzec `VisibleWhenRule`).

### Egzekwowanie

Wspólny `CrossFieldRulesValidator` (Catalog\Application) wołany z OBU ścieżek zapisu wartości:

- **Edycja ręczna** (`ObjectAttributesUpserter`): restrukturyzacja na 3 fazy —
  (1) dotychczasowa walidacja per-atrybut zbiera przygotowane wpisy zamiast zapisywać,
  (2) gate cross-field → `422` PRZED pierwszym save (żadna wartość nie ląduje przy violation),
  (3) persystencja. Wszyscy konsumenci upserta (create/update/bulk/backfill) dostają enforcement.
- **Import/bulk** (`BatchValueWriter`): `primeChunk()` buduje dodatkowo indeks globalnych kopert
  (bez nowych zapytań), `writeMany()` robi pre-pass i emituje issues `kind: 'cross_field'`;
  wpisy uwikłane w złamany `compare` są pomijane (nie lądują), `require_when` jest report-only.
- **Agent (Faza 2)** dziedziczy przez te same seamy.

### Semantyka (kontrakt)

- Ewaluacja WYŁĄCZNIE na global scope (locale=null, channel=null) — spójnie z `attributes_indexed`
  i `visible_when`; wpisy locale/channel-routed nie wchodzą do widoku.
- Źródłem istniejących wartości są kanoniczne wiersze `ObjectValue`
  (`findByObject` w upsercie / prime-index w imporcie) — **nie** `attributes_indexed`
  (listener rebuildu pomija bulk, świeży obiekt ma pusty cache).
- Widok = istniejące global rows nadpisane przychodzącymi kopertami; pusta koperta
  (`ValueWriteCore::isEmptyEnvelope`) USUWA kod z widoku ("wyczyszczone" ≡ "nigdy nie ustawione").
- `compare`: którakolwiek strona nieobecna/nienumeryczna → SKIP (brak violation; wymagalność to
  osobny wymiar). Violation kotwiczona w lewym kodzie.
- `require_when`: strzela gdy warunek prawdziwy ORAZ target nieobecny w widoku; wyczyszczenie pola
  warunku zdejmuje wymóg; brak pola warunku = warunek fałszywy (semantyka ewaluatora visible_when).
- Reguła wskazująca usunięty atrybut: runtime pobłażliwy (SKIP / warunek false), ale ponowny zapis
  reguł przez PATCH odrzuca nieistniejące kody.

### Zapis reguł

`PATCH /api/object_types/{id}` przyjmuje `validationRules`; `ObjectTypeService::update()` parsuje
przez `CrossFieldRules::fromArray`, sprawdza istnienie kodów **tenant-wide**
(`AttributeRepositoryInterface::findByCode` — celowo nie effective-schema: grupy dryfują
niezależnie od reguł) oraz guard numeryczno-typowy dla `compare`. Built-in ObjectTypes:
`fieldLocked` jak przy `completenessRules`.

## Konsekwencje

- (+) reguły cross-field działają identycznie w edycji ręcznej, imporcie i (w Fazie 2) u agenta,
- (+) zero nowych zapytań na ścieżce importu; w edycji ręcznej jedno dodatkowe zapytanie tylko
  gdy ObjectType ma reguły,
- (+) jeden kształt warunku (`VisibleWhenRule`) dla visibility i conditional required,
- (−) global-scope-only: reguły nie widzą wartości per-locale/per-channel (świadome ograniczenie
  MVP, spójne z resztą systemu; rozszerzenie wymaga decyzji o semantyce porównań cross-scope),
- (−) `compare` bez konwersji jednostek (guard same-type minimalizuje ryzyko pomyłek; pełna
  konwersja = DP-09, optional).

## Odrzucone alternatywy

- **Reguły w `Attribute.validation_rules`** — reguła dwustronna nie ma naturalnego właściciela
  wśród atrybutów; duplikacja i dryf przy edycji jednej strony.
- **Egzekwowanie w `ValueWriteCore.formatViolations`** — core waliduje pojedynczą kopertę i nie
  zna obiektu; wciąganie kontekstu obiektu do niego złamałoby jego kontrakt (ADR-0019).
- **Doctrine listener post-flush** — traci natychmiastowe 422 i gwarancję "nic nie zapisane".
- **`attributes_indexed` jako źródło** — nieświeży w bulk (BulkContext) i pusty dla świeżych
  obiektów w tym samym flushu.
