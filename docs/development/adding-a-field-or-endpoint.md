# Jak dodać pole lub endpoint — pełen vertical slice

> Przewodnik referencyjny dla AUD-059. Pokazuje **wszystkie warstwy**, które
> trzeba dotknąć, żeby dodać pole do istniejącego zasobu albo wystawić nowy
> endpoint przez API Platform 4 — z odnośnikami do realnego, działającego kodu
> w repo (kontekst `Channel`, dla wariantów Provider/JSONB także `Catalog`).
>
> Wzorzec architektoniczny: **encja Domain jest czystym PHP** (konstruktor-only
> agregat, zero adnotacji frameworka). Metadane Doctrine ORM i API Platform są
> dostarczane **z boku przez XML** w warstwie `Infrastructure` (ADR-0011). Zapis
> idzie przez **Input DTO → Processor → komenda CQRS → handler**, bo domyślny
> procesor Doctrine z AP4 nie potrafi zhydratować agregatu, który ma tylko
> konstruktor. To jest ten brakujący, nieoczywisty kawałek, który ten dokument
> dokumentuje.

## Spis treści

- [Mapa warstw](#mapa-warstw)
- [Skąd API Platform i Doctrine czytają XML](#skąd-api-platform-i-doctrine-czytają-xml)
- [Scenariusz A — dodanie POLA do istniejącego zasobu](#scenariusz-a--dodanie-pola-do-istniejącego-zasobu)
- [Scenariusz B — dodanie NOWEGO endpointu / zasobu](#scenariusz-b--dodanie-nowego-endpointu--zasobu)
- [Provider zamiast Processor (odczyt)](#provider-zamiast-processor-odczyt)
- [Gdzie wpina się TenantFilter / provenance / RBAC](#gdzie-wpina-się-tenantfilter--provenance--rbac)
- [Czego NIE robić](#czego-nie-robić)
- [Checklist „Done"](#checklist-done)

---

## Mapa warstw

Pełny przepływ dla zapisu (POST/PATCH), na przykładzie `Channel`
(`/api/channels`). Każda warstwa to realny plik w repo:

```
HTTP POST /api/channels
        │
        ▼
┌─ Resource XML ───────────────────────────────────────────────────────────────┐
│ src/Channel/Infrastructure/ApiPlatform/Resource/Channel.xml                   │
│   <resource class="App\Channel\Domain\Entity\Channel">                        │
│     operacja Post: input=ChannelInput, processor=ChannelProcessor             │
└───────────────────────────────────────────────────────────────────────────────┘
        │ AP4 deserializuje body do Input DTO (grupy denormalizacji)
        ▼
┌─ Input DTO ──────────────────────────────────────────────────────────────────┐
│ src/Channel/Infrastructure/ApiPlatform/Resource/ChannelInput.php              │
│   public string $code; public string $name;  (+ #[Assert\*], #[Groups])       │
└───────────────────────────────────────────────────────────────────────────────┘
        │ AP4 woła process() z DTO jako $data
        ▼
┌─ Processor (State) ──────────────────────────────────────────────────────────┐
│ src/Channel/Infrastructure/ApiPlatform/State/ChannelProcessor.php             │
│   mapuje DTO → CreateChannelCommand → MessageBus->dispatch()                  │
│   rozpakowuje HandlerFailedException → 404/409/422 (zamiast gołego 500)       │
└───────────────────────────────────────────────────────────────────────────────┘
        │ Messenger
        ▼
┌─ Command + Handler (Application, CQRS) ──────────────────────────────────────┐
│ src/Channel/Application/Command/CreateChannel/CreateChannelCommand.php        │
│ src/Channel/Application/Command/CreateChannel/CreateChannelHandler.php        │
│   walidacja biznesowa (duplikat kodu → 409), new Channel(...), repo->save()   │
└───────────────────────────────────────────────────────────────────────────────┘
        │
        ▼
┌─ Encja Domain (czysty PHP) ──────────────────────────────────────────────────┐
│ src/Channel/Domain/Entity/Channel.php                                         │
│   konstruktor-only agregat, extends AggregateRoot implements TenantScoped     │
└───────────────────────────────────────────────────────────────────────────────┘
        │ persist → prePersist listener stampuje tenant
        ▼
┌─ ORM XML (mapowanie tabeli) ─────────────────────────────────────────────────┐
│ src/Channel/Infrastructure/Doctrine/Orm/Mapping/Channel.orm.xml               │
│   table="channels", kolumny, indeksy, FK tenant_id                            │
└───────────────────────────────────────────────────────────────────────────────┘

Odczyt odpowiedzi (serializacja encji z powrotem do JSON-LD):
┌─ Serializer XML (grupy per atrybut) ─────────────────────────────────────────┐
│ src/Channel/Infrastructure/Serializer/Channel.xml                             │
│   <attribute name="code"><group>admin:read</group>...                         │
└───────────────────────────────────────────────────────────────────────────────┘

Kontrakt do frontu:
┌─ shared-types (OpenAPI → TS) ────────────────────────────────────────────────┐
│ packages/shared-types/src/api.d.ts  (generowany, NIE ręczny)                  │
│   pnpm --filter @pim/shared-types generate                                    │
└───────────────────────────────────────────────────────────────────────────────┘

Konsumpcja w adminie:
┌─ Admin UI (Refine + jsonFetch) ──────────────────────────────────────────────┐
│ apps/admin/src/lib/data-provider.ts  → jsonFetch (apps/admin/src/lib/http.ts) │
│ apps/admin/src/features/channel/channels/{list,create,edit,show}.tsx          │
└───────────────────────────────────────────────────────────────────────────────┘

Test:
┌─ ApiTestCase ────────────────────────────────────────────────────────────────┐
│ apps/api/tests/Api/Channel/ChannelsCrudApiTest.php                            │
│   (baza scaffoldingu: tests/Api/Channel/ChannelApiTestCase.php)               │
└───────────────────────────────────────────────────────────────────────────────┘
```

Lista plików do dotknięcia przy **nowym zasobie** (wariant B) — typowo 9–11:

| # | Warstwa | Ścieżka (wzorzec `Channel`) |
|---|---------|------------------------------|
| 1 | Encja Domain | `apps/api/src/Channel/Domain/Entity/Channel.php` |
| 2 | ORM XML | `apps/api/src/Channel/Infrastructure/Doctrine/Orm/Mapping/Channel.orm.xml` |
| 3 | Migracja | `apps/api/migrations/VersionYYYYMMDDHHMMSS.php` (generowana) |
| 4 | Resource XML (API Platform) | `apps/api/src/Channel/Infrastructure/ApiPlatform/Resource/Channel.xml` |
| 5 | Input DTO (POST) | `apps/api/src/Channel/Infrastructure/ApiPlatform/Resource/ChannelInput.php` |
| 6 | Patch Input DTO (PATCH) | `apps/api/src/Channel/Infrastructure/ApiPlatform/Resource/ChannelPatchInput.php` |
| 7 | Processor (State) | `apps/api/src/Channel/Infrastructure/ApiPlatform/State/ChannelProcessor.php` |
| 8 | Command + Handler | `apps/api/src/Channel/Application/Command/CreateChannel/*.php` |
| 9 | Serializer XML (grupy read) | `apps/api/src/Channel/Infrastructure/Serializer/Channel.xml` |
| 10 | shared-types (regen) | `packages/shared-types/src/api.d.ts` (`generate`) |
| 11 | ApiTestCase | `apps/api/tests/Api/Channel/ChannelsCrudApiTest.php` |

Przy **dodaniu pola** (wariant A) dotykasz zwykle 1–2, 4–5 (lub 5–7 jeśli pole
jest zapisywalne), 9–11 — nie tworzysz nowego Processora ani komendy.

---

## Skąd API Platform i Doctrine czytają XML

To jest klucz do zrozumienia, dlaczego encja nie ma adnotacji. Trzy osobne
rejestry skanują katalogi per bounded context — **nowy kontekst trzeba dopisać
do każdego z nich**:

**1. Doctrine ORM** — [`apps/api/config/packages/doctrine.yaml`](../../apps/api/config/packages/doctrine.yaml)
(`doctrine.orm.mappings`). `auto_mapping: false` jest celowe — każdy kontekst
mapuje się jawnie:

```yaml
mappings:
    Channel:
        type: xml
        is_bundle: false
        dir: '%kernel.project_dir%/src/Channel/Infrastructure/Doctrine/Orm/Mapping'
        prefix: 'App\Channel\Domain\Entity'
        alias: Channel
```

**2. API Platform** — [`apps/api/config/packages/api_platform.yaml`](../../apps/api/config/packages/api_platform.yaml)
(`api_platform.mapping.paths`):

```yaml
mapping:
    paths:
        - '%kernel.project_dir%/src/Catalog/Infrastructure/ApiPlatform/Resource'
        - '%kernel.project_dir%/src/Channel/Infrastructure/ApiPlatform/Resource'
        # ... Asset, ApiConfigurator, Import
```

**3. Symfony Serializer** — [`apps/api/config/packages/framework.yaml`](../../apps/api/config/packages/framework.yaml)
(`framework.serializer.mapping.paths`):

```yaml
serializer:
    mapping:
        paths:
            - '%kernel.project_dir%/src/Channel/Infrastructure/Serializer'
            # ... per kontekst
```

> **Pułapka:** jeśli zakładasz **nowy bounded context**, dopisz jego trzy
> ścieżki do tych trzech plików. Encja w nowym kontekście, której katalogu nie
> ma w `doctrine.yaml`, nie zmapuje się (Doctrine jej „nie widzi"); Resource bez
> wpisu w `api_platform.yaml` nie wystawi endpointu; brak wpisu w
> `framework.yaml` → grupy serializacji z XML nie zadziałają. Dla **istniejącego**
> kontekstu (Channel, Catalog, Asset…) katalog już jest zarejestrowany — sam
> dodajesz plik XML w środku, bez zmian w config.

Processor / Handler / Listener **nie wymagają ręcznej rejestracji jako serwis** —
PSR-4 autowiring łapie cały `src/` ([`apps/api/config/services.yaml`](../../apps/api/config/services.yaml),
`App\: resource: '../src/'`). Handler jest oznaczony `#[AsMessageHandler]`,
listener `#[AsDoctrineListener(...)]` — to wystarcza.

---

## Scenariusz A — dodanie POLA do istniejącego zasobu

Przykład w repo: pole `categoryTreeRootId` na `Channel` (mapuje się na kolumnę
`category_tree_root_object_id`, tylko do odczytu w API, zapisywane osobnym
endpointem nawigacji). Prześledź je jako wzorzec „field-only".

### A.1 — Encja Domain: właściwość + akcesory

[`apps/api/src/Channel/Domain/Entity/Channel.php`](../../apps/api/src/Channel/Domain/Entity/Channel.php):

```php
private ?Uuid $categoryTreeRootId = null;

public function getCategoryTreeRootId(): ?Uuid
{
    return $this->categoryTreeRootId;
}

public function attachCategoryTreeRoot(?Uuid $rootId): void { /* ... */ }
```

Reguły:

- Pola dodajesz jako `private`, mutację wystawiasz **metodą domenową**
  (`rename()`, `attachCategoryTreeRoot()`), nie publicznym setterem — encja to
  agregat, nie anemiczny worek na dane.
- Walidację składniową daj adnotacją `#[Assert\*]` na właściwości (np.
  `#[Assert\Length(max: 255)]` przy `name`). Walidacja **biznesowa** (np.
  „kod unikalny w obrębie tenanta") idzie do handlera, nie do encji.

### A.2 — ORM XML: kolumna

[`apps/api/src/Channel/Infrastructure/Doctrine/Orm/Mapping/Channel.orm.xml`](../../apps/api/src/Channel/Infrastructure/Doctrine/Orm/Mapping/Channel.orm.xml):

```xml
<field name="categoryTreeRootId" type="uuid" column="category_tree_root_object_id" nullable="true"/>
```

> **Kolumna `NOT NULL` na istniejącej tabeli** musi mieć w XML
> `<option name="default">...</option>` — baza testowa jest budowana z metadanych
> ORM (Foundry `ResetDatabase`), nie z migracji, więc istniejące surowe `INSERT`-y
> w fixtures/testach wywalą się na not-null bez defaultu. (Zob. lekcja
> „New NOT NULL column needs ORM default".)

### A.3 — Migracja bazy

Wygeneruj diff i przejrzyj go ręcznie przed commitem:

```bash
docker compose exec -T api php bin/console doctrine:migrations:diff
# przejrzyj wygenerowany plik w apps/api/migrations/, potem:
docker compose exec -T api php bin/console doctrine:migrations:migrate --no-interaction
```

### A.4 — Wystawienie pola w API

- **Read (zawsze):** dodaj atrybut z grupą `admin:read` w Serializer XML —
  [`apps/api/src/Channel/Infrastructure/Serializer/Channel.xml`](../../apps/api/src/Channel/Infrastructure/Serializer/Channel.xml):

  ```xml
  <attribute name="categoryTreeRootId">
      <group>admin:read</group>
  </attribute>
  ```

  Grupa musi zgadzać się z `normalizationContext` w Resource XML (`Channel.xml`
  deklaruje `<value>admin:read</value>`).

- **Write (jeśli pole edytowalne):** dodaj właściwość do Input DTO z odpowiednią
  grupą denormalizacji i `#[Assert\*]` — [`ChannelInput.php`](../../apps/api/src/Channel/Infrastructure/ApiPlatform/Resource/ChannelInput.php) /
  [`ChannelPatchInput.php`](../../apps/api/src/Channel/Infrastructure/ApiPlatform/Resource/ChannelPatchInput.php),
  a potem przenieś ją w Processorze do komendy. `code` i `name` w `ChannelInput`
  to gotowy wzorzec:

  ```php
  #[Assert\NotBlank]
  #[Assert\Length(max: 255)]
  #[Groups(['channel:create'])]
  public string $name = '';
  ```

### A.5 — Regeneracja shared-types

Po zmianie kształtu odpowiedzi **zawsze** najpierw odśwież wersjonowany snapshot
OpenAPI, a potem zregeneruj z niego kontrakt TS. Sam codegen działa offline:

```bash
pnpm --filter @pim/shared-types generate
```

### A.6 — Admin UI

Front czyta pole z odpowiedzi przez Refine data provider. Dla `Channel`:
[`apps/admin/src/features/channel/channels/show.tsx`](../../apps/admin/src/features/channel/channels/show.tsx)
(`useOne`), [`list.tsx`](../../apps/admin/src/features/channel/channels/list.tsx)
(`useList`), [`edit.tsx`](../../apps/admin/src/features/channel/channels/edit.tsx)
(`useOne` + `useUpdate`). Wszystkie idą przez
[`apps/admin/src/lib/data-provider.ts`](../../apps/admin/src/lib/data-provider.ts)
→ `jsonFetch` ([`apps/admin/src/lib/http.ts`](../../apps/admin/src/lib/http.ts),
nagłówek `Authorization: Bearer <jwt>` doklejany centralnie).

### A.7 — Test

Dorzuć asercję do istniejącego `ChannelsCrudApiTest`
([`apps/api/tests/Api/Channel/ChannelsCrudApiTest.php`](../../apps/api/tests/Api/Channel/ChannelsCrudApiTest.php)) —
np. że POST zwraca nowe pole, albo że PATCH je zmienia.

---

## Scenariusz B — dodanie NOWEGO endpointu / zasobu

Pełny wzorzec to **cały kontekst `Channel`**. Idź po kolei:

### B.1 — Encja Domain (konstruktor-only agregat)

[`apps/api/src/Channel/Domain/Entity/Channel.php`](../../apps/api/src/Channel/Domain/Entity/Channel.php):

```php
class Channel extends AggregateRoot implements TenantScoped
{
    private Uuid $id;
    private ?Tenant $tenant = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    private string $code;

    public function __construct(string $code, string $name, ?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
        $this->code = $code;
        $this->name = $name;
    }
    // gettery + metody domenowe (rename, attachCategoryTreeRoot, assignTenant)
}
```

- `extends AggregateRoot` — daje `recordThat()` do emisji eventów domenowych.
- `implements TenantScoped` — wystarcza, żeby `TenantAssignmentListener`
  ostemplował `tenant_id` na `prePersist` (patrz niżej, sekcja RBAC/tenant).
- **Brak adnotacji `#[ApiResource]` na encji** — metadane AP4 są w XML. To różni
  PIM od „domyślnego" tutoriala API Platform.
- ID generujesz w konstruktorze (`Uuid::v7()`), strategia w ORM XML to
  `<generator strategy="NONE"/>` — aplikacja, nie baza, nadaje identyfikator.

### B.2 — ORM XML

[`apps/api/src/Channel/Infrastructure/Doctrine/Orm/Mapping/Channel.orm.xml`](../../apps/api/src/Channel/Infrastructure/Doctrine/Orm/Mapping/Channel.orm.xml):

```xml
<entity name="App\Channel\Domain\Entity\Channel" table="channels"
        repository-class="App\Channel\Infrastructure\Doctrine\Repository\DoctrineChannelRepository">
    <unique-constraints>
        <unique-constraint name="channels_tenant_code_uniq" columns="tenant_id,code"/>
    </unique-constraints>
    <id name="id" type="uuid" column="id"><generator strategy="NONE"/></id>
    <many-to-one field="tenant" target-entity="App\Shared\Domain\Tenant">
        <join-column name="tenant_id" referenced-column-name="id" nullable="false" on-delete="RESTRICT"/>
    </many-to-one>
    <field name="code" type="string" length="64"/>
    <field name="name" type="string" length="255"/>
</entity>
```

Każda tabela domenowa ma `tenant_id` od dnia 1 i unikalność scope'owaną po
tenancie (`tenant_id,code`), nie globalną.

### B.3 — Resource XML (API Platform)

[`apps/api/src/Channel/Infrastructure/ApiPlatform/Resource/Channel.xml`](../../apps/api/src/Channel/Infrastructure/ApiPlatform/Resource/Channel.xml).
To tu deklarujesz operacje, ścieżki, `input`, `processor`, `security` i grupy:

```xml
<resource class="App\Channel\Domain\Entity\Channel" shortName="Channel">
    <normalizationContext>
        <values><value name="groups"><values><value>admin:read</value></values></value></values>
    </normalizationContext>
    <operations>
        <operation class="ApiPlatform\Metadata\GetCollection" uriTemplate="/channels{._format}"
                   security="is_granted('READ', 'App\\Channel\\Domain\\Entity\\Channel')"/>
        <operation class="ApiPlatform\Metadata\Get" uriTemplate="/channels/{id}{._format}"
                   security="is_granted('READ', object)"/>
        <operation class="ApiPlatform\Metadata\Post" uriTemplate="/channels{._format}"
                   input="App\Channel\Infrastructure\ApiPlatform\Resource\ChannelInput"
                   processor="App\Channel\Infrastructure\ApiPlatform\State\ChannelProcessor"
                   security="is_granted('CREATE', 'App\\Channel\\Domain\\Entity\\Channel')">
            <denormalizationContext>
                <values><value name="groups"><values><value>channel:create</value></values></value></values>
            </denormalizationContext>
        </operation>
        <!-- Patch + Delete analogicznie, każde z processor= i security= -->
    </operations>
</resource>
```

Uwagi:

- `input="...ChannelInput"` — POST deserializuje się do DTO, nie do encji.
- `processor="...ChannelProcessor"` — wskazuje State Processor (B.5).
- `security="is_granted(...)"` — ekspresja Symfony Security; tu odpalają się
  Votery RBAC (akcje `READ`/`CREATE`/`UPDATE`/`DELETE`).
- Backslash w FQCN w atrybucie XML **podwajasz** (`App\\Channel\\...`).

### B.4 — Input DTO (POST i PATCH osobno)

[`ChannelInput.php`](../../apps/api/src/Channel/Infrastructure/ApiPlatform/Resource/ChannelInput.php)
(POST — pełne pola wymagane) i
[`ChannelPatchInput.php`](../../apps/api/src/Channel/Infrastructure/ApiPlatform/Resource/ChannelPatchInput.php)
(PATCH — wszystkie pola opcjonalne, `code` pominięty bo niezmienny po utworzeniu).
DTO niesie `#[Assert\*]` (walidacja wejścia) + `#[Groups([...])]` (zgodne z
grupami denormalizacji z Resource XML).

> **Dlaczego osobny DTO, a nie hydratacja encji?** Komentarz w `ChannelInput`
> mówi wprost: „AP4 default Doctrine processor cannot hydrate the constructor-only
> Channel aggregate — this DTO is the deserialisation target for the
> ChannelProcessor." Agregat ma tylko konstruktor z wymaganymi argumentami,
> więc domyślny procesor (który robi `new` + settery) nie zadziała.

### B.5 — Processor (State)

[`apps/api/src/Channel/Infrastructure/ApiPlatform/State/ChannelProcessor.php`](../../apps/api/src/Channel/Infrastructure/ApiPlatform/State/ChannelProcessor.php).
Most DTO → komenda CQRS:

```php
final readonly class ChannelProcessor implements ProcessorInterface
{
    public function __construct(
        private MessageBusInterface $bus,
        private ChannelRepositoryInterface $channels,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?Channel
    {
        if ($operation instanceof DeleteOperationInterface) { return $this->handleDelete($uriVariables); }
        if ($operation instanceof Post)  { return $this->handlePost($data); }
        if ($operation instanceof Patch) { return $this->handlePatch($data, $uriVariables); }
        throw new LogicException(/* ... */);
    }

    private function handlePost(mixed $data): Channel
    {
        // $data to ChannelInput; zbuduj komendę, dispatch, przeładuj encję z repo
        $command = new CreateChannelCommand(code: $data->code, name: $data->name, categoryTreeRootId: null);
        $envelope = $this->dispatch($command);
        $newId = $this->extractResult($envelope); // HandledStamp->getResult()
        return $this->channels->findById($newId) ?? throw new LogicException(/* ... */);
    }
    // dispatch() rozpakowuje HandlerFailedException → oryginalny HttpException (404/409/422)
}
```

Krytyczny detal: prywatne `dispatch()` łapie `HandlerFailedException` z Messengera
i rzuca dalej oryginalny `HttpException` (`ConflictHttpException` →409,
`UnprocessableEntityHttpException` →422, `NotFoundHttpException` →404). Bez tego
AP4 pokazałby gołe 500 zamiast właściwego kodu.

### B.6 — Command + Handler (Application, CQRS)

[`CreateChannelCommand.php`](../../apps/api/src/Channel/Application/Command/CreateChannel/CreateChannelCommand.php)
(niezmienny DTO komendy) +
[`CreateChannelHandler.php`](../../apps/api/src/Channel/Application/Command/CreateChannel/CreateChannelHandler.php)
(`#[AsMessageHandler]`). Tu mieszka **walidacja biznesowa** i właściwa mutacja:

```php
#[AsMessageHandler]
final readonly class CreateChannelHandler
{
    public function __invoke(CreateChannelCommand $command): Uuid
    {
        $tenant = $this->tenantContext->get() ?? throw new LogicException(/* ... */);

        if (null !== $this->channels->findByCode($command->code, $tenant)) {
            throw new ConflictHttpException(/* duplikat → 409 */);
        }
        $channel = new Channel(code: $command->code, name: $command->name);
        $this->channels->save($channel);
        return $channel->getId();
    }
}
```

### B.7 — Serializer XML (grupy odczytu)

[`apps/api/src/Channel/Infrastructure/Serializer/Channel.xml`](../../apps/api/src/Channel/Infrastructure/Serializer/Channel.xml) —
per atrybut deklaruje, w których grupach jest widoczny (`admin:read`,
`integration:read`, `public:read`). Pola bez grupy nie wychodzą w odpowiedzi.

### B.8 — Repozytorium (port + adapter)

Interfejs w Domain
([`ChannelRepositoryInterface`](../../apps/api/src/Channel/Domain/Repository/ChannelRepositoryInterface.php)),
implementacja Doctrine w Infrastructure
([`DoctrineChannelRepository`](../../apps/api/src/Channel/Infrastructure/Doctrine/Repository/DoctrineChannelRepository.php),
wskazana w `repository-class` w ORM XML). Handler i Processor zależą od
interfejsu, nie od klasy Doctrine.

### B.9 — Migracja + shared-types + test

- Migracja: `doctrine:migrations:diff` → przegląd → `migrate` (jak A.3).
- shared-types: `pnpm --filter @pim/shared-types generate` (jak A.5).
- Test: cały plik
  [`ChannelsCrudApiTest.php`](../../apps/api/tests/Api/Channel/ChannelsCrudApiTest.php)
  to wzorzec — POST 201, duplikat 409, zły format 422, blank 422, PATCH 200,
  DELETE 204→404, brak auth 401. Baza scaffoldingu (seed tenant + super_admin +
  JWT) jest w
  [`ChannelApiTestCase.php`](../../apps/api/tests/Api/Channel/ChannelApiTestCase.php):

  ```php
  abstract class ChannelApiTestCase extends ApiTestCase
  {
      use Factories; use ResetDatabase;
      protected function authenticatedClient(string $email = self::ADMIN_EMAIL): Client
      {
          $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
          $client = static::createClient();
          $client->setDefaultOptions(['headers' => ['authorization' => 'Bearer '.$jwt]]);
          return $client;
      }
  }
  ```

  > Asercje rób na **kodzie HTTP**, nie na treści błędu RFC 7807 — `detail` jest
  > pełny tylko w debug (lokalnie), a w CI (non-debug) kolapsuje do generycznego
  > tekstu statusu. (Lekcja „Assert status not RFC7807 detail".)

---

## Provider zamiast Processor (odczyt)

Processor obsługuje **zapis** (POST/PATCH/DELETE). Jeśli potrzebujesz nietrywialnego
**odczytu** (np. nakładka per-locale na wartości, agregacja, custom źródło danych),
napisz **State Provider** (`ProviderInterface`) i wskaż go atrybutem
`provider="..."` w operacji `Get`/`GetCollection` w Resource XML.

Realny wzorzec: [`CatalogObjectLocaleOverlayProvider`](../../apps/api/src/Catalog/Infrastructure/ApiPlatform/State/CatalogObjectLocaleOverlayProvider.php)
(deleguje do domyślnego item providera Doctrine, a potem nakłada wartości
rozwiązane per `?locale=`). Pliki State Catalogu:
`apps/api/src/Catalog/Infrastructure/ApiPlatform/State/`.

---

## Gdzie wpina się TenantFilter / provenance / RBAC

### Tenant isolation (multi-tenancy)

- **Zapis:** encja implementuje `TenantScoped`; `tenant_id` stempluje
  [`TenantAssignmentListener`](../../apps/api/src/Shared/Infrastructure/Doctrine/EventListener/TenantAssignmentListener.php)
  na `prePersist` (woła `assignTenant()` z aktualnym `TenantContext`). **Nigdy
  nie ustawiaj `tenant_id` ręcznie w handlerze.** Brak aktywnego tenanta → wyjątek,
  nie ciche `NULL`.
- **Odczyt:** Doctrine filter `tenant`
  ([`TenantFilter`](../../apps/api/src/Shared/Infrastructure/Doctrine/Filter/TenantFilter.php),
  rejestrowany ale `enabled: false` w `doctrine.yaml`) jest włączany per-request,
  gdy znany jest tenant. Postgres RLS to defence-in-depth (osobny mechanizm).
  Szczegóły: [`docs/multi-tenancy.md`](../multi-tenancy.md).

Dla nowej encji wystarczy `implements TenantScoped` + kolumna `tenant_id` w ORM
XML — reszta dzieje się automatycznie.

### Provenance (skąd wzięła się wartość)

Dotyczy zapisu **wartości atrybutów produktu** (`ObjectValue`), nie metadanych
zasobu jak Channel. Każdy `ObjectValue` niesie
[`Provenance`](../../apps/api/src/Catalog/Domain/Provenance.php)
(`manual | import | integration`; `agent` zarezerwowany na Fazę 2) +
`provenance_meta JSONB`. Jeśli piszesz nowy writer wartości — ustaw provenance
zgodnie z kanałem zapisu. Kontrakt kształtu JSONB:
[`docs/api/jsonb-schemas.md`](../api/jsonb-schemas.md).

### RBAC — `#[RequiresPermission]` i `security=`

Dwie warstwy, zależnie od typu endpointu:

- **Operacje API Platform** (jak Channel) — autoryzacja przez `security="is_granted(...)"`
  w Resource XML (B.3). Votery dostają akcję (`READ`/`CREATE`/...) i obiekt/klasę.
- **Custom kontrolery** (poza AP4) — adnotacja
  [`#[RequiresPermission(module, action, subject?)]`](../../apps/api/src/Identity/Contracts/Attribute/RequiresPermission.php)
  na metodzie, egzekwowana runtime przez
  [`EndpointGuardListener`](../../apps/api/src/Identity/Infrastructure/Http/EndpointGuardListener.php)
  (subskrybuje `kernel.controller_arguments`). Kod uprawnienia to
  `{module}.{action}` (np. `products.edit`), zgodnie z macierzą w
  [`docs/rbac.md`](../rbac.md) / PRD-PIM-rbac §3.2.

> Reguła PHPStan `RequiresPermissionAnnotationRule` **blokuje PR**, jeśli custom
> kontroler pod `/api/*` nie ma ani `#[RequiresPermission]`, ani
> `#[NoPermissionRequired]`. To nie jest opcjonalne.

---

## Czego NIE robić

- **Nie dodawaj `#[ApiResource]` na encji Domain.** Metadane AP4 idą do Resource
  XML w `Infrastructure/ApiPlatform/Resource/`. Encja zostaje czystym PHP (ADR-0011).
- **Nie hydratuj agregatu domyślnym procesorem Doctrine.** Zrób Input DTO +
  Processor + komendę. Domyślny `new + setter` nie zadziała na konstruktor-only.
- **Nie ustawiaj `tenant_id` ręcznie.** `implements TenantScoped` +
  `TenantAssignmentListener`. Ręczne ustawienie = naruszenie reguły i ryzyko cross-tenant.
- **Nie pomijaj regeneracji shared-types** po zmianie kształtu odpowiedzi.
  `pnpm --filter @pim/shared-types generate` — inaczej kontrakt TS rozjedzie się
  z API.
- **Nie zapominaj o `default` dla kolumny `NOT NULL`** na istniejącej tabeli w
  ORM XML — baza testowa budowana z metadanych ORM wywali istniejące `INSERT`-y.
- **Nie hardkoduj URL-i, kluczy, sekretów** w kodzie (CLAUDE.md, reguła 7).
  W froncie data provider używa bazy `/api` + `jsonFetch` (single-origin przez
  Caddy, **bez CORS**).
- **Nie asertuj treści błędu RFC 7807** w teście — asertuj kod HTTP (debug vs
  non-debug rozjazd między lokalnie a CI).
- **Nie zapominaj dopisać nowego bounded context** do trzech rejestrów
  (`doctrine.yaml`, `api_platform.yaml`, `framework.yaml`). Dla istniejącego
  kontekstu katalog już jest — sam dodajesz plik.
- **Nie pomijaj testu E2E** dla zmiany widocznej w UI (CLAUDE.md, Definicja Done).

---

## Checklist „Done"

- [ ] Encja Domain: właściwość + metody domenowe, walidacja `#[Assert\*]` na wejściu.
- [ ] ORM XML: kolumna (+ `default` jeśli `NOT NULL` na istniejącej tabeli).
- [ ] Migracja: `doctrine:migrations:diff` → przegląd → `migrate`.
- [ ] Resource XML: operacja(e) + `input`/`processor` + `security` + grupy (jeśli nowy zasób).
- [ ] Input DTO(s): POST i PATCH, `#[Assert\*]` + `#[Groups]`.
- [ ] Processor: DTO → komenda, rozpakowanie `HandlerFailedException`.
- [ ] Command + Handler `#[AsMessageHandler]`: walidacja biznesowa + mutacja.
- [ ] Serializer XML: pole w grupie `admin:read` (i innych wg potrzeby).
- [ ] Nowy kontekst? trzy ścieżki w `doctrine.yaml` + `api_platform.yaml` + `framework.yaml`.
- [ ] shared-types: `pnpm --filter @pim/shared-types generate` ze snapshotu `docs/api-spec/v0.json`.
- [ ] Admin UI: konsumpcja przez data provider + `jsonFetch`.
- [ ] ApiTestCase: 201/409/422/200/204/401 wg wzorca `ChannelsCrudApiTest`.
- [ ] Bramki: `composer phpstan` + `cs-check` + `deptrac` + PHPUnit; `pnpm typecheck` + `build` + Biome.
- [ ] E2E Playwright dla zmiany widocznej w UI.

---

**Powiązane dokumenty:** [ONBOARDING](../../ONBOARDING.md) ·
[CONTRIBUTING](../../CONTRIBUTING.md) · [kontrakt JSONB](../api/jsonb-schemas.md) ·
[multi-tenancy](../multi-tenancy.md) · [RBAC](../rbac.md).
