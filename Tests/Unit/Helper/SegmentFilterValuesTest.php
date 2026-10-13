<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Helper;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\TextType;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Helper\SegmentFilterValues;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SegmentFilterValuesTest extends TestCase
{
    private Connection&MockObject $connection;
    private EntityManagerInterface&MockObject $em;
    private CustomItemPermissionProvider&MockObject $permissions;
    private SegmentFilterValues $values;

    protected function setUp(): void
    {
        $this->connection  = $this->createMock(Connection::class);
        $this->em          = $this->createMock(EntityManagerInterface::class);
        $this->permissions = $this->createMock(CustomItemPermissionProvider::class);
        $this->em->method('getConnection')->willReturn($this->connection);
        $this->values = new SegmentFilterValues($this->em, $this->permissions);
    }

    private function field(string $type = 'text', int $objectId = 7): CustomField
    {
        $object = $this->createMock(CustomObject::class);
        $object->method('getId')->willReturn($objectId);
        $typeObject = $this->createMock(TextType::class);
        $typeObject->method('getPrefixedTableName')->willReturn('custom_field_value_text');
        $field = $this->createMock(CustomField::class);
        $field->method('getCustomObject')->willReturn($object);
        $field->method('getType')->willReturn($type);
        $field->method('getTypeObject')->willReturn($typeObject);

        return $field;
    }

    public function testAFieldGivesItsDistinctValuesThatMatchTheSearch(): void
    {
        $this->em->method('find')->with(CustomField::class, 14)->willReturn($this->field());
        $this->permissions->expects($this->once())->method('canViewAtAll')->with(7);
        $this->connection->expects($this->once())->method('fetchFirstColumn')
            ->with(
                $this->callback(static fn (string $sql) => str_contains($sql, 'DISTINCT value') && str_contains($sql, 'custom_field_value_text') && str_contains($sql, 'LIKE') && str_contains($sql, 'LIMIT 20')),
                ['field' => 14, 'search' => '%ab%']
            )
            ->willReturn(['abc', 'zab']);

        $this->assertSame(['abc', 'zab'], $this->values->find('cmf_14', 'ab'));
    }

    public function testLikeWildcardsInTheSearchAreEscaped(): void
    {
        $this->em->method('find')->willReturn($this->field());
        $this->connection->expects($this->once())->method('fetchFirstColumn')
            ->with($this->anything(), ['field' => 14, 'search' => '%100\%\_x%'])
            ->willReturn([]);

        $this->values->find('cmf_14', '100%_x');
    }

    public function testTheLimitIsCapped(): void
    {
        $this->em->method('find')->willReturn($this->field());
        $this->connection->expects($this->once())->method('fetchFirstColumn')
            ->with($this->callback(static fn (string $sql) => str_contains($sql, 'LIMIT 50')), $this->anything())
            ->willReturn([]);

        $this->values->find('cmf_14', '', 5000);
    }

    public function testTheItemNameOfAnObjectIsTheSourceOfCmo(): void
    {
        $this->permissions->expects($this->once())->method('canViewAtAll')->with(5);
        $this->connection->expects($this->once())->method('fetchFirstColumn')
            ->with(
                $this->callback(static fn (string $sql) => str_contains($sql, 'DISTINCT name') && str_contains($sql, 'custom_item') && str_contains($sql, 'custom_object_id')),
                ['object' => 5, 'search' => '%x%']
            )
            ->willReturn(['x1']);

        $this->assertSame(['x1'], $this->values->find('cmo_5', 'x'));
    }

    public function testNoPermissionNoValues(): void
    {
        $this->em->method('find')->willReturn($this->field());
        $this->permissions->method('canViewAtAll')->willThrowException(new ForbiddenException('view'));
        $this->connection->expects($this->never())->method('fetchFirstColumn');

        $this->expectException(ForbiddenException::class);
        $this->values->find('cmf_14', '');
    }

    public function testAFieldThatIsNotTextIsRefused(): void
    {
        $this->em->method('find')->willReturn($this->field('int'));
        $this->connection->expects($this->never())->method('fetchFirstColumn');

        $this->expectException(NotFoundException::class);
        $this->values->find('cmf_14', '');
    }

    public function testAnUnknownFieldIsRefused(): void
    {
        $this->em->method('find')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->values->find('cmf_99', '');
    }

    public function testAnAliasThatIsNotACustomObjectFilterIsRefused(): void
    {
        $this->expectException(NotFoundException::class);
        $this->values->find('email', '');
    }
}
