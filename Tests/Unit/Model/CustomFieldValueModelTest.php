<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Mautic\LeadBundle\Provider\FilterOperatorProviderInterface;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\IntType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\TextType;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueInterface;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Model\CustomFieldValueModel;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use MauticPlugin\CustomObjectsBundle\Tests\Unit\ConsecutiveCallsTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class CustomFieldValueModelTest extends \PHPUnit\Framework\TestCase
{
    use ConsecutiveCallsTrait;

    private $customObject;
    private $customItem;
    private $customField;
    private $entityManager;
    private $connection;
    private $queryBuilder;
    private $statement;
    private $customFieldValueModel;
    private $validator;
    private $violationList;
    private $filterOperatorProvider;
    private $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customObject           = $this->createMock(CustomObject::class);
        $this->customItem             = $this->createMock(CustomItem::class);
        $this->customField            = $this->createMock(CustomField::class);
        $this->entityManager          = $this->createMock(EntityManager::class);
        $this->connection             = $this->createMock(Connection::class);
        $this->queryBuilder           = $this->createMock(QueryBuilder::class);
        $this->statement              = $this->createMock(Statement::class);
        $this->validator              = $this->createMock(ValidatorInterface::class);
        $this->violationList          = $this->createStub(ConstraintViolationListInterface::class);
        $this->filterOperatorProvider = $this->createStub(FilterOperatorProviderInterface::class);
        $this->translator             = $this->createStub(TranslatorInterface::class);
        $this->customFieldValueModel  = new CustomFieldValueModel(
            $this->entityManager,
            $this->validator
        );

        $this->entityManager->method('getReference')->willReturn($this->customItem);
    }

    public function testGetValuesForItemIfItemDoesNotHaveId(): void
    {
        $customFields = new ArrayCollection([$this->customField]);

        $this->customItem->expects($this->any())
            ->method('getCustomObject')
            ->willReturn($this->customObject);

        $this->customObject->expects($this->once())
            ->method('getPublishedFields')
            ->willReturn($customFields);

        $this->customItem->expects($this->once())
            ->method('isNew')
            ->willReturn(true);

        $this->customField->expects($this->once())
            ->method('getTypeObject')
            ->willReturn(new TextType($this->translator, $this->filterOperatorProvider));

        $this->entityManager->expects($this->never())
            ->method('getConnection');

        $this->customFieldValueModel->createValuesForItem($this->customItem);
    }

    public function testGetValuesForItemIfItemHasId(): void
    {
        $noValueField = $this->createMock(CustomField::class);
        $customFields = new ArrayCollection([44 => $this->customField, 66 => $noValueField]);
        $customItem   = $this->getMockBuilder(CustomItem::class)
            ->onlyMethods(['getCustomObject', 'getId', 'isNew'])
            ->disableOriginalConstructor()
            ->getMock();

        $customItem->expects($this->once())
            ->method('getCustomObject')
            ->willReturn($this->customObject);

        $this->customObject->expects($this->once())
            ->method('getPublishedFields')
            ->willReturn($customFields);

        $customItem->expects($this->any())
            ->method('getId')
            ->willReturn(33);

        $customItem->expects($this->once())
            ->method('isNew')
            ->willReturn(false);

        $this->customField->expects($this->exactly(2))
            ->method('getTypeObject')
            ->willReturn(new TextType($this->translator, $this->filterOperatorProvider));

        $noValueField->expects($this->exactly(2))
            ->method('getTypeObject')
            ->willReturn(new IntType($this->translator, $this->filterOperatorProvider));

        $noValueField->expects($this->any())
            ->method('getId')
            ->willReturn(66);

        $this->customField->expects($this->any())
            ->method('getId')
            ->willReturn(44);

        $this->entityManager->expects($this->exactly(3))
            ->method('getConnection')
            ->willReturn($this->connection);

        $this->connection->expects($this->exactly(2))
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->expects($this->exactly(2))
            ->method('select')
            ->willReturnCallback($this->consecutiveCalls([
                ["cfv_text.custom_field_id, cfv_text.custom_item_id, cfv_text.value, 'text' AS type"],
                ["cfv_int.custom_field_id, cfv_int.custom_item_id, cfv_int.value, 'int' AS type"]
            ], null, false, $this->queryBuilder, 'select'));

        $this->queryBuilder->expects($this->exactly(2))
            ->method('from')
            ->willReturnCallback($this->consecutiveCalls([
                [MAUTIC_TABLE_PREFIX.'custom_field_value_text'],
                [MAUTIC_TABLE_PREFIX.'custom_field_value_int']
            ], null, false, $this->queryBuilder, 'from'));

        $this->queryBuilder->expects($this->exactly(2))
            ->method('where')
            ->willReturnCallback($this->consecutiveCalls([
                ['cfv_text.custom_item_id = 33'],
                ['cfv_int.custom_item_id = 33']
            ], null, false, $this->queryBuilder, 'where'));

        $this->queryBuilder->expects($this->exactly(2))
            ->method('andWhere')
            ->willReturnCallback($this->consecutiveCalls([
                ['cfv_text.custom_field_id = 44'],
                ['cfv_int.custom_field_id = 66']
            ], null, false, $this->queryBuilder, 'andWhere'));

        $this->queryBuilder->expects($this->exactly(2))
            ->method('getSQL')
            ->willReturnOnConsecutiveCalls(
                'THE TEXT FIELD SQL QUERY',
                'THE NUMBER FIELD SQL QUERY'
            );

        $this->connection->expects($this->once())
            ->method('prepare')
            ->with('THE TEXT FIELD SQL QUERY UNION ALL THE NUMBER FIELD SQL QUERY')
            ->willReturn($this->statement);

        $result = $this->createMock(Result::class);

        $this->statement->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result);

        $result->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([[
                'custom_field_id' => 44,
                'custom_item_id'  => 33,
                'value'           => 'Yellow Submarine',
            ]]);

        $this->customFieldValueModel->createValuesForItem($customItem);

        $values = $customItem->getCustomFieldValues();

        $this->assertSame(2, $values->count());

        $storedValue = $values->get(44);
        $newValue    = $values->get(66);

        $this->assertSame('Yellow Submarine', $storedValue->getValue());
        $this->assertSame($this->customField, $storedValue->getCustomField());
        $this->assertSame($customItem, $storedValue->getCustomItem());

        // A number with no stored value is empty (NULL), not 0: IntType keeps an unfilled
        // number as NULL now, and 0 is a value of its own.
        $this->assertNull($newValue->getValue());
        $this->assertSame($noValueField, $newValue->getCustomField());
        $this->assertSame($customItem, $newValue->getCustomItem());
    }

    public function testCreateValuesForItemsReadsAllTheItemsWithOneQuery(): void
    {
        $field = $this->createStub(CustomField::class);
        $field->method('getId')->willReturn(44);
        $field->method('getTypeObject')->willReturn(new TextType($this->translator, $this->filterOperatorProvider));

        $object = $this->createStub(CustomObject::class);
        $object->method('getPublishedFields')->willReturn(new ArrayCollection([44 => $field]));

        $items = [];
        foreach ([31, 32] as $id) {
            $item = $this->getMockBuilder(CustomItem::class)->onlyMethods(['getCustomObject', 'getId', 'isNew'])->disableOriginalConstructor()->getMock();
            $item->method('getCustomObject')->willReturn($object);
            $item->method('getId')->willReturn($id);
            $item->method('isNew')->willReturn(false);
            $items[] = $item;
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturnCallback(fn () => new \Doctrine\DBAL\Query\QueryBuilder($connection));
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('custom_item_id IN (:itemIds)'), $this->callback(fn (array $p): bool => [31, 32] === $p['itemIds']))
            ->willReturn([
                ['custom_item_id' => 32, 'custom_field_id' => 44, 'value' => 'second'],
                ['custom_item_id' => 31, 'custom_field_id' => 44, 'value' => 'first'],
            ]);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $this->customFieldValueModel->createValuesForItems($items);

        $this->assertSame('first', $items[0]->getCustomFieldValues()->get(44)->getValue());
        $this->assertSame('second', $items[1]->getCustomFieldValues()->get(44)->getValue());
    }

    public function testCreateValuesForItemsDoesNotQueryWhenAllItemsAreNew(): void
    {
        $field = $this->createStub(CustomField::class);
        $field->method('getId')->willReturn(44);
        $field->method('getTypeObject')->willReturn(new TextType($this->translator, $this->filterOperatorProvider));
        $object = $this->createStub(CustomObject::class);
        $object->method('getPublishedFields')->willReturn(new ArrayCollection([44 => $field]));

        $item = $this->getMockBuilder(CustomItem::class)->onlyMethods(['getCustomObject', 'isNew'])->disableOriginalConstructor()->getMock();
        $item->method('getCustomObject')->willReturn($object);
        $item->method('isNew')->willReturn(true);

        $this->entityManager->expects($this->never())->method('getConnection');

        $this->customFieldValueModel->createValuesForItems([$item]);

        $this->assertSame(1, $item->getCustomFieldValues()->count());
    }

    private function itemOf(CustomObject $object, int $id, bool $isNew = false): CustomItem
    {
        $item = $this->getMockBuilder(CustomItem::class)->onlyMethods(['getCustomObject', 'getId', 'isNew'])->disableOriginalConstructor()->getMock();
        $item->method('getCustomObject')->willReturn($object);
        $item->method('getId')->willReturn($id);
        $item->method('isNew')->willReturn($isNew);

        return $item;
    }

    private function textField(int $id): CustomField
    {
        $field = $this->createMock(CustomField::class);
        $field->method('getId')->willReturn($id);
        $field->method('getTypeObject')->willReturn(new TextType($this->translator, $this->filterOperatorProvider));

        return $field;
    }

    public function testTheRowsAreGivenToTheirItemsWhateverTheTypeOfTheIdsTheDriverReturnsAndAnUnknownFieldIsIgnored(): void
    {
        $object = $this->createMock(CustomObject::class);
        $object->method('getPublishedFields')->willReturn(new ArrayCollection([44 => $this->textField(44)]));
        $first  = $this->itemOf($object, 31);
        $second = $this->itemOf($object, 32);

        $connection = $this->createStub(Connection::class);
        $connection->method('createQueryBuilder')->willReturnCallback(fn () => new \Doctrine\DBAL\Query\QueryBuilder($connection));
        $connection->method('fetchAllAssociative')->willReturn([
            ['custom_item_id' => '31', 'custom_field_id' => '44', 'value' => 'one'],
            ['custom_item_id' => '31', 'custom_field_id' => '99', 'value' => 'a field that is not published'],
        ]);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $this->customFieldValueModel->createValuesForItems([$first, $second]);

        $this->assertSame('one', $first->getCustomFieldValues()->get(44)->getValue());
        $this->assertNull($first->getCustomFieldValues()->get(99));
        $this->assertSame('', $second->getCustomFieldValues()->get(44)->getValue(), 'an item without rows keeps its empty value');
    }

    public function testOnlyTheSavedItemsAreQueriedAndEachObjectIsReadOnItsOwn(): void
    {
        $objectA = $this->createMock(CustomObject::class);
        $objectA->method('getPublishedFields')->willReturn(new ArrayCollection([44 => $this->textField(44)]));
        $objectB = $this->createMock(CustomObject::class);
        $objectB->method('getPublishedFields')->willReturn(new ArrayCollection([55 => $this->textField(55)]));
        $items = [$this->itemOf($objectA, 1), $this->itemOf($objectA, 2, true), $this->itemOf($objectB, 3)];

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturnCallback(fn () => new \Doctrine\DBAL\Query\QueryBuilder($connection));
        $asked = [];
        $connection->expects($this->exactly(2))->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $params, array $types) use (&$asked): array {
                $asked[] = [$params, $types];

                return [];
            });
        $this->entityManager->method('getConnection')->willReturn($connection);

        $this->customFieldValueModel->createValuesForItems($items);

        $this->assertSame([1], $asked[0][0]['itemIds'], 'the new item is not asked about');
        $this->assertSame([44], $asked[0][0]['custom_field_value_text']);
        $this->assertSame([3], $asked[1][0]['itemIds']);
        $this->assertSame([55], $asked[1][0]['custom_field_value_text']);
        $this->assertSame(Connection::PARAM_INT_ARRAY, $asked[0][1]['itemIds']);
        $this->assertSame(Connection::PARAM_INT_ARRAY, $asked[0][1]['custom_field_value_text']);
    }

    public function testNothingIsQueriedForAnObjectWithoutPublishedFields(): void
    {
        $object = $this->createMock(CustomObject::class);
        $object->method('getPublishedFields')->willReturn(new ArrayCollection());
        $this->entityManager->expects($this->never())->method('getConnection');

        $this->customFieldValueModel->createValuesForItems([$this->itemOf($object, 1)]);
    }

    public function testASavedValueOfAnExistingItemIsMergedAndFlushed(): void
    {
        $value = $this->createStub(CustomFieldValueInterface::class);
        $value->method('getCustomField')->willReturn($this->customField);
        $value->method('getCustomItem')->willReturn($this->customItem);
        $this->customItem->method('getId')->willReturn(99);
        $merged = $this->createStub(CustomFieldValueInterface::class);
        $this->entityManager->method('merge')->with($value)->willReturn($merged);
        $this->entityManager->expects($this->once())->method('flush')->with($merged);
        $this->violationList->method('count')->willReturn(0);
        $this->validator->method('validate')->willReturn($this->violationList);

        $this->customFieldValueModel->save($value);
    }

    public function testEachOptionIsTrimmedAndSavedAsItsOwnValue(): void
    {
        $item  = $this->createStub(CustomItem::class);
        $item->method('getId')->willReturn(99);
        $field = $this->createStub(CustomField::class);
        $field->method('getId')->willReturn(44);
        $field->method('canHaveMultipleValues')->willReturn(true);
        $value = new \MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueOption($field, $item, ' red , green ');
        $query = $this->createStub(AbstractQuery::class);
        $query->method('execute')->willReturn(0);
        $this->entityManager->method('createQuery')->willReturn($query);
        $this->violationList->method('count')->willReturn(0);
        $this->validator->method('validate')->willReturn($this->violationList);
        $saved = [];
        $this->entityManager->method('persist')->willReturnCallback(function ($option) use (&$saved): void {
            $saved[] = $option;
        });

        $this->customFieldValueModel->save($value);

        $this->assertSame(['red', 'green'], array_map(static fn ($o) => $o->getValue(), $saved));
        $this->assertNotSame($saved[0], $saved[1], 'each option is a separate value');
        $this->assertNotSame($value, $saved[0]);
    }

    public function testSaveForNewCustomItem(): void
    {
        $customFieldValue = $this->createMock(CustomFieldValueInterface::class);

        $customFieldValue->expects($this->once())
            ->method('getCustomField')
            ->willReturn($this->customField);

        $customFieldValue->expects($this->exactly(2))
            ->method('getCustomItem')
            ->willReturn($this->customItem);

        $this->customItem->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(99);

        $this->entityManager->expects($this->once())
            ->method('merge')
            ->with($customFieldValue);

        $this->validator->expects($this->once())
            ->method('validate')
            ->with($customFieldValue)
            ->willReturn($this->violationList);

        $this->customFieldValueModel->save($customFieldValue);
    }

    public function testSaveForExistingCustomItem(): void
    {
        $customFieldValue = $this->createMock(CustomFieldValueInterface::class);

        $customFieldValue->expects($this->once())
            ->method('getCustomField')
            ->willReturn($this->customField);

        $customFieldValue->expects($this->exactly(2))
            ->method('getCustomItem')
            ->willReturn($this->customItem);

        $this->customItem->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(0);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($customFieldValue);

        $this->validator->expects($this->once())
            ->method('validate')
            ->with($customFieldValue)
            ->willReturn($this->violationList);

        $this->customFieldValueModel->save($customFieldValue);
    }

    public function testSaveForMultivalueField(): void
    {
        $customFieldValue = $this->createMock(CustomFieldValueInterface::class);
        $query            = $this->createMock(AbstractQuery::class);

        $customFieldValue->expects($this->exactly(2))
            ->method('getCustomField')
            ->willReturn($this->customField);

        $customFieldValue->expects($this->exactly(2))
            ->method('getCustomItem')
            ->willReturn($this->customItem);

        $customFieldValue->expects($this->once())
            ->method('getValue')
            ->willReturn(['red', 'green', 4]);

        $customFieldValue->expects($this->exactly(3))
            ->method('setValue')
            ->willReturnCallback($this->consecutiveCalls([['red'], ['green'], [4]], null, false, $customFieldValue, 'setValue'));

        $this->customItem->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(99);

        $this->customField->expects($this->any())
            ->method('getId')
            ->willReturn(44);

        $this->customField->expects($this->once())
            ->method('canHaveMultipleValues')
            ->willReturn(true);

        $this->entityManager->expects($this->once())
            ->method('createQuery')
            ->with('
            delete from MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueOption cfvo  
            where cfvo.customField = 44
            and cfvo.customItem = 99
        ')
            ->willReturn($query);

        $query->expects($this->once())
            ->method('execute')
            ->willReturn(2);

        $this->entityManager->expects($this->exactly(3))
            ->method('persist')
            ->with($customFieldValue);

        $this->validator->expects($this->exactly(3))
            ->method('validate')
            ->with($customFieldValue)
            ->willReturn($this->violationList);

        $this->customFieldValueModel->save($customFieldValue);
    }

    public function testSaveForMultivalueFieldWithNoOptionsProvided(): void
    {
        $customFieldValue = $this->createMock(CustomFieldValueInterface::class);
        $query            = $this->createMock(AbstractQuery::class);

        $customFieldValue->expects($this->exactly(2))
            ->method('getCustomField')
            ->willReturn($this->customField);

        $customFieldValue->expects($this->exactly(2))
            ->method('getCustomItem')
            ->willReturn($this->customItem);

        $customFieldValue->expects($this->once())
            ->method('getValue')
            ->willReturn('');

        $customFieldValue->expects($this->never())
            ->method('setValue');

        $this->customItem->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(99);

        $this->customField->expects($this->any())
            ->method('getId')
            ->willReturn(44);

        $this->customField->expects($this->once())
            ->method('canHaveMultipleValues')
            ->willReturn(true);

        $this->entityManager->expects($this->once())
            ->method('createQuery')
            ->with('
            delete from MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueOption cfvo  
            where cfvo.customField = 44
            and cfvo.customItem = 99
        ')
            ->willReturn($query);

        $query->expects($this->once())
            ->method('execute')
            ->willReturn(2);

        $this->entityManager->expects($this->never())
            ->method('persist');

        $this->validator->expects($this->never())
            ->method('validate');

        $this->customFieldValueModel->save($customFieldValue);
    }
}
