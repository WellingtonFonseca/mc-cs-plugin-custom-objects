<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\CustomFieldType;

use Mautic\LeadBundle\Provider\FilterOperatorProviderInterface;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\DecimalType;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueDecimal;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use Symfony\Contracts\Translation\TranslatorInterface;

class DecimalTypeTest extends \PHPUnit\Framework\TestCase
{
    private $translator;
    private $customField;
    private $customItem;
    private $filterOperatorProvider;

    /**
     * @var DecimalType
     */
    private $fieldType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translator             = $this->createMock(TranslatorInterface::class);
        $this->customField            = $this->createMock(CustomField::class);
        $this->customItem             = $this->createMock(CustomItem::class);
        $this->filterOperatorProvider = $this->createMock(FilterOperatorProviderInterface::class);
        $this->fieldType              = new DecimalType(
            $this->translator,
            $this->filterOperatorProvider
        );
    }

    public function testGetSymfonyFormFieldType(): void
    {
        $this->assertSame(
            \Symfony\Component\Form\Extension\Core\Type\NumberType::class,
            $this->fieldType->getSymfonyFormFieldType()
        );
    }

    public function testGetEntityClass(): void
    {
        $this->assertSame(
            CustomFieldValueDecimal::class,
            $this->fieldType->getEntityClass()
        );
    }

    public function testGetOperators(): void
    {
        $this->filterOperatorProvider->expects($this->once())
            ->method('getAllOperators')
            ->willReturn([
                'empty'  => [],
                '!empty' => [],
                'in'     => [],
                '='      => [],
                '!='     => [],
            ]);

        $operators = $this->fieldType->getOperators();

        $this->assertArrayHasKey('=', $operators);
        $this->assertArrayNotHasKey('in', $operators);
    }

    public function testCreateValueEntity(): void
    {
        $valueEntity = $this->fieldType->createValueEntity(
            $this->customField,
            $this->customItem,
            3.75
        );

        $this->assertInstanceOf(CustomFieldValueDecimal::class, $valueEntity);
        $this->assertSame($this->customField, $valueEntity->getCustomField());
        $this->assertSame($this->customItem, $valueEntity->getCustomItem());
        $this->assertSame(3.75, $valueEntity->getValue());
    }

    public function testCreateValueEntityKeepsTheFractionalPart(): void
    {
        $valueEntity = $this->fieldType->createValueEntity($this->customField, $this->customItem, '9.99');

        $this->assertSame(9.99, $valueEntity->getValue());
    }

    public function testCreateValueEntityTreatsEmptyAsNull(): void
    {
        $this->assertNull($this->fieldType->createValueEntity($this->customField, $this->customItem, '')->getValue());
        $this->assertNull($this->fieldType->createValueEntity($this->customField, $this->customItem, null)->getValue());
    }

    public function testValueReadFromTheDatabaseAsDecimalStringIsReturnedAsFloat(): void
    {
        $valueEntity = new CustomFieldValueDecimal($this->customField, $this->customItem, '3.750000');

        $this->assertSame(3.75, $valueEntity->getValue());
    }

    public function testTableName(): void
    {
        $this->assertSame('custom_field_value_decimal', $this->fieldType->getTableName());
    }
}
