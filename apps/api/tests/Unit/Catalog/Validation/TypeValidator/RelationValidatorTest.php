<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\RelationValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RelationValidatorTest extends TestCase
{
    private RelationValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new RelationValidator();
        $this->attribute = new Attribute('parent', ['pl' => 'Rodzic'], AttributeType::Relation);
    }

    #[Test]
    public function validUuidPasses(): void
    {
        $uuid = Uuid::v7()->toRfc4122();

        self::assertSame([], $this->validator->validate($this->attribute, ['object_id' => $uuid]));
    }

    #[Test]
    public function nonUuidFails(): void
    {
        $errors = $this->validator->validate($this->attribute, ['object_id' => 'abc']);

        self::assertSame('relation.expected_uuid', $errors[0]->code);
    }
}
