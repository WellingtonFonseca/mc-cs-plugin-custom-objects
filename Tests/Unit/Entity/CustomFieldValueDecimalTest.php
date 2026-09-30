<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueDecimal;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;

class CustomFieldValueDecimalTest extends \PHPUnit\Framework\TestCase
{
    public function testGettersSetters(): void
    {
        $customField = new CustomField();
        $customItem  = new CustomItem(new CustomObject());
        $value       = new CustomFieldValueDecimal($customField, $customItem, 3.75);

        $this->assertSame($customField, $value->getCustomField());
        $this->assertSame($customItem, $value->getCustomItem());
        $this->assertSame(3.75, $value->getValue());

        $value->setValue('9.99');
        $this->assertSame(9.99, $value->getValue());

        $value->setValue(0);
        $this->assertSame(0.0, $value->getValue(), 'zero is a value, not "empty"');

        $value->setValue('');
        $this->assertNull($value->getValue());

        $value->setValue(null);
        $this->assertNull($value->getValue());
    }

    public function testDoctrineDecimalStringIsReturnedAsFloat(): void
    {
        $value = new CustomFieldValueDecimal(new CustomField(), new CustomItem(new CustomObject()), '3.750000');

        $this->assertSame(3.75, $value->getValue());
    }

    public function testMappedToItsOwnTableAsDecimalWithEnoughPrecision(): void
    {
        $metadata = new ClassMetadata(CustomFieldValueDecimal::class);
        CustomFieldValueDecimal::loadMetadata($metadata);

        $this->assertSame('custom_field_value_decimal', $metadata->getTableName());

        $mapping = $metadata->getFieldMapping('value');
        $this->assertSame(Types::DECIMAL, $mapping['type']);
        $this->assertSame(20, $mapping['precision']);
        $this->assertSame(6, $mapping['scale']);
        $this->assertTrue($mapping['nullable']);
    }
}
