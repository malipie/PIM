<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\PendingChanges\ContentValueMaterializer;
use App\Catalog\Application\Validation\AttributeValueValidator;
use App\Catalog\Application\Validation\AttributeValueValidatorInterface;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\Command\ContentValueProposal;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Channel\Contracts\ChannelResolverInterface;
use App\Identity\Contracts\Policy\UserScopedPermissionCheckerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P3-01 (#2334) — refusal paths of the content materializer:
 * non-text targets, RBAC (attribute / locale / channel), unknown
 * object/channel, and the scope normalisation that mirrors the write
 * path's routing. Refusals are data (tool_result), never exceptions.
 * The happy path against real Postgres lives in the integration suite.
 */
final class ContentValueMaterializerTest extends TestCase
{
    #[Test]
    public function nonTextAttributeIsRejectedAsInvalid(): void
    {
        $proposal = $this->materializer(attributeType: AttributeType::Number)
            ->materializeGeneratedValue(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'price', 'generated');

        self::assertSame(ContentValueProposal::INVALID, $proposal->status);
        self::assertStringContainsString('text attributes', (string) $proposal->message);
    }

    #[Test]
    public function attributeOutsideEditScopeIsForbidden(): void
    {
        $proposal = $this->materializer(canEditAttribute: false)
            ->materializeGeneratedValue(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'description', 'generated');

        self::assertSame(ContentValueProposal::FORBIDDEN, $proposal->status);
        self::assertStringContainsString('outside your edit scope', (string) $proposal->message);
    }

    #[Test]
    public function localeOutsideEditScopeIsForbiddenOnlyWhenAttributeIsLocalizable(): void
    {
        $denied = $this->materializer(localizable: true, canEditLocale: false)
            ->materializeGeneratedValue(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'description', 'txt', locale: 'en');
        self::assertSame(ContentValueProposal::FORBIDDEN, $denied->status);

        // A non-localizable attribute routes to the global row — the
        // locale permission is irrelevant (mirrors the write path).
        $routed = $this->materializer(localizable: false, canEditLocale: false)
            ->materializeGeneratedValue(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'description', 'txt', locale: 'en');
        self::assertSame(ContentValueProposal::MATERIALIZED, $routed->status);
        self::assertNull($routed->scopeLocale);
    }

    #[Test]
    public function unknownChannelIsInvalid(): void
    {
        $proposal = $this->materializer(scopable: true, channelResolves: false)
            ->materializeGeneratedValue(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'description', 'txt', channel: 'ghost');

        self::assertSame(ContentValueProposal::INVALID, $proposal->status);
        self::assertStringContainsString('Unknown channel', (string) $proposal->message);
    }

    #[Test]
    public function unknownObjectIsInvalid(): void
    {
        $proposal = $this->materializer(objectExists: false)
            ->materializeGeneratedValue(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'description', 'txt');

        self::assertSame(ContentValueProposal::INVALID, $proposal->status);
        self::assertStringContainsString('Unknown object', (string) $proposal->message);
    }

    #[Test]
    public function materializedProposalCarriesBeforeAfterAndScope(): void
    {
        $proposal = $this->materializer(localizable: true)
            ->materializeGeneratedValue(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'description', 'wygenerowany opis', locale: 'en');

        self::assertTrue($proposal->isMaterialized());
        self::assertSame(['value' => 'wygenerowany opis'], $proposal->after);
        self::assertSame('en', $proposal->scopeLocale);
        $payload = $proposal->toToolResult();
        self::assertSame('materialized', $payload['status']);
        self::assertSame('en', $payload['locale']);
    }

    private function materializer(
        AttributeType $attributeType = AttributeType::Textarea,
        bool $canEditAttribute = true,
        bool $canEditLocale = true,
        bool $localizable = false,
        bool $scopable = false,
        bool $channelResolves = true,
        bool $objectExists = true,
    ): ContentValueMaterializer {
        $tenant = new Tenant('demo-test-'.bin2hex(random_bytes(2)), 'Demo');
        $tenantContext = new TenantContext();
        $tenantContext->set($tenant);

        $objectType = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $objectType->assignTenant($tenant);
        $object = new CatalogObject($objectType, 'SKU-CVM-1');

        $attribute = new Attribute('description', ['en' => 'Description'], $attributeType);
        $attribute->assignTenant($tenant);
        $attribute->changeLocalizable($localizable);
        $attribute->changeScopable($scopable);

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($attribute);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($objectExists ? $object : null);
        $em->method('getRepository')->willReturn($repository);

        $permissions = $this->createStub(UserScopedPermissionCheckerInterface::class);
        $permissions->method('canEditAttribute')->willReturn($canEditAttribute);
        $permissions->method('canEditLocale')->willReturn($canEditLocale);
        $permissions->method('canEditChannel')->willReturn(true);

        $channels = $this->createStub(ChannelResolverInterface::class);
        $channels->method('resolveId')->willReturn($channelResolves ? Uuid::v7() : null);

        // AttributeValueValidator is final — build the real one with a
        // per-type stub that accepts everything.
        $accepting = $this->createStub(AttributeValueValidatorInterface::class);
        $accepting->method('validate')->willReturn([]);
        $validator = new AttributeValueValidator([$attributeType->value => $accepting]);

        return new ContentValueMaterializer(
            $em,
            $tenantContext,
            $this->createStub(ObjectValueRepositoryInterface::class),
            $validator,
            $permissions,
            $channels,
            $this->createStub(PendingChangesPort::class),
        );
    }
}
