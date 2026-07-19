<?php

declare(strict_types=1);

namespace App\Integration\Generic\Domain\Entity;

use App\Integration\Generic\Domain\Enum\RemoteEndpointRole;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One operation descriptor on an external API — the consumer-side analogue of
 * an Airbyte stream (ADR-0022, epic APIC, ticket APIC-P2-01).
 *
 * A {@see Connection} owns many endpoints; each binds a {@see RemoteEndpointRole}
 * (read_list / read_one / write_create / write_update) to an HTTP method, a
 * path template, optional query params and a request-body template. The
 * `pagination` envelope selects the paging strategy (none/offset/page/cursor/
 * link_header — implemented in APIC-P2-03); `recordSelector` is the JSONPath
 * that extracts records from a list response.
 *
 * `TenantScoped` + Postgres RLS isolate every endpoint to its tenant; the
 * tenant always matches the parent connection's. The FK to Connection cascades
 * on delete, so removing a connection removes its descriptor.
 */
class RemoteEndpoint extends AggregateRoot implements TenantScoped
{
    public const array HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public const array REQUEST_FORMATS = ['json', 'form'];

    private Uuid $id;

    private ?Tenant $tenant = null;

    private Connection $connection;

    private string $role;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::HTTP_METHODS)]
    private string $httpMethod;

    #[Assert\NotBlank]
    #[Assert\Length(max: 2048)]
    private string $pathTemplate;

    /** @var array<string, string> */
    private array $queryParams = [];

    /**
     * Static request-body envelope for write roles (constants such as an RPC
     * `method` name; outbound field mappings are merged over it at sync time);
     * null for read roles.
     *
     * @var array<string, mixed>|null
     */
    private ?array $requestBodyTemplate = null;

    /**
     * Wire format of the outbound request body: `json` (raw JSON) or `form`
     * (application/x-www-form-urlencoded with nested objects sent as JSON
     * strings — the shape RPC-style APIs like BaseLinker expect).
     */
    #[Assert\Choice(choices: self::REQUEST_FORMATS)]
    private string $requestFormat = 'json';

    /**
     * Pagination envelope: `{strategy, ...params}`. Defaults to no paging; the
     * concrete strategies (offset/page/cursor/link_header) are read in
     * APIC-P2-03.
     *
     * @var array<string, mixed>
     */
    private array $pagination = ['strategy' => 'none'];

    /** JSONPath that selects records from a list response (read_list); null otherwise. */
    #[Assert\Length(max: 512)]
    private ?string $recordSelector = null;

    #[Assert\Choice(choices: ['json'])]
    private string $responseFormat = 'json';

    private DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    public function __construct(
        Connection $connection,
        RemoteEndpointRole $role,
        string $httpMethod,
        string $pathTemplate,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->connection = $connection;
        $this->role = $role->value;
        $this->httpMethod = $httpMethod;
        $this->pathTemplate = $pathTemplate;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function assignTenant(Tenant $tenant): void
    {
        if (null !== $this->tenant) {
            throw new LogicException('Tenant is already assigned and cannot be reassigned.');
        }

        $this->tenant = $tenant;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getConnectionId(): Uuid
    {
        return $this->connection->getId();
    }

    public function getRole(): RemoteEndpointRole
    {
        return RemoteEndpointRole::from($this->role);
    }

    public function setRole(RemoteEndpointRole $role): void
    {
        $this->role = $role->value;
        $this->touch();
    }

    public function getHttpMethod(): string
    {
        return $this->httpMethod;
    }

    public function setHttpMethod(string $httpMethod): void
    {
        $this->httpMethod = $httpMethod;
        $this->touch();
    }

    public function getPathTemplate(): string
    {
        return $this->pathTemplate;
    }

    public function setPathTemplate(string $pathTemplate): void
    {
        $this->pathTemplate = $pathTemplate;
        $this->touch();
    }

    /**
     * @return array<string, string>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * @param array<string, string> $queryParams
     */
    public function setQueryParams(array $queryParams): void
    {
        $this->queryParams = $queryParams;
        $this->touch();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRequestBodyTemplate(): ?array
    {
        return $this->requestBodyTemplate;
    }

    /**
     * @param array<string, mixed>|null $requestBodyTemplate
     */
    public function setRequestBodyTemplate(?array $requestBodyTemplate): void
    {
        $this->requestBodyTemplate = $requestBodyTemplate;
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPagination(): array
    {
        return $this->pagination;
    }

    /**
     * @param array<string, mixed> $pagination
     */
    public function setPagination(array $pagination): void
    {
        $this->pagination = $pagination;
        $this->touch();
    }

    public function getRecordSelector(): ?string
    {
        return $this->recordSelector;
    }

    public function setRecordSelector(?string $recordSelector): void
    {
        $this->recordSelector = $recordSelector;
        $this->touch();
    }

    public function getRequestFormat(): string
    {
        return $this->requestFormat;
    }

    public function setRequestFormat(string $requestFormat): void
    {
        $this->requestFormat = $requestFormat;
        $this->touch();
    }

    public function getResponseFormat(): string
    {
        return $this->responseFormat;
    }

    public function setResponseFormat(string $responseFormat): void
    {
        $this->responseFormat = $responseFormat;
        $this->touch();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
