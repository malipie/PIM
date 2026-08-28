<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\AssetValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AssetValidatorTest extends TestCase
{
    private AssetValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new AssetValidator();
        $this->attribute = new Attribute('hero_image', ['pl' => 'Zdjęcie'], AttributeType::Asset);
    }

    #[Test]
    public function validUuidPasses(): void
    {
        $uuid = Uuid::v7()->toRfc4122();

        self::assertSame([], $this->validator->validate($this->attribute, ['asset_id' => $uuid]));
    }

    #[Test]
    public function nonUuidFails(): void
    {
        $errors = $this->validator->validate($this->attribute, ['asset_id' => 'not-a-uuid']);

        self::assertSame('asset.expected_uuid', $errors[0]->code);
    }

    #[Test]
    public function missingAssetIdFails(): void
    {
        $errors = $this->validator->validate($this->attribute, []);

        self::assertSame('asset.expected_uuid', $errors[0]->code);
    }
}
