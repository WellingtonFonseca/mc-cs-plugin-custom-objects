<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\CustomFieldType;

use Mautic\LeadBundle\Provider\FilterOperatorProviderInterface;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\IntType;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueInt;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use Symfony\Contracts\Translation\TranslatorInterface;

class IntTypeTest extends \PHPUnit\Framework\TestCase
{
    private $translator;
    private $customField;
    private $customItem;
    private $filterOperatorProvider;

    /**
     * @var IntType
     */
    private $fieldType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translator             = $this->createMock(TranslatorInterface::class);
        $this->customField            = $this->createMock(CustomField::class);
        $this->customItem             = $this->createMock(CustomItem::class);
        $this->filterOperatorProvider = $this->createMock(FilterOperatorProviderInterface::class);
        $this->fieldType              = new IntType(
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
            CustomFieldValueInt::class,
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
            234
        );

        $this->assertInstanceOf(CustomFieldValueInt::class, $valueEntity);
        $this->assertSame($this->customField, $valueEntity->getCustomField());
        $this->assertSame($this->customItem, $valueEntity->getCustomItem());
        $this->assertSame(234, $valueEntity->getValue());
    }

    /**
     * @return iterable<string, array{0: mixed, 1: int|null}>
     */
    public static function storedValues(): iterable
    {
        yield 'null is empty' => [null, null];
        yield 'empty string is empty' => ['', null];
        yield '0 is a value' => [0, 0];
        yield "'0' is a value" => ['0', 0];
        yield 'a number as a string' => ['7', 7];
        yield 'a number' => [42, 42];
    }

    /**
     * An unfilled number is NULL, not 0 (the column is nullable): 0 is a real value,
     * and with 0 stored for "no value" the "empty" segment filter never matched.
     *
     * @param mixed $input
     *
     * @dataProvider storedValues
     */
    public function testAnUnfilledNumberIsStoredAsNullNotZero($input, ?int $expected): void
    {
        $valueEntity = $this->fieldType->createValueEntity($this->customField, $this->customItem, $input);

        $this->assertSame($expected, $valueEntity->getValue());
    }

    public function testSetValueKeepsEmptyAsNull(): void
    {
        $valueEntity = new CustomFieldValueInt($this->customField, $this->customItem, 5);

        $valueEntity->setValue('');
        $this->assertNull($valueEntity->getValue());

        $valueEntity->setValue(0);
        $this->assertSame(0, $valueEntity->getValue());
    }
}
