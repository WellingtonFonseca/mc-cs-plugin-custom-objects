<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Event;

use Doctrine\DBAL\Query\QueryBuilder;
use MauticPlugin\CustomObjectsBundle\DTO\TableConfig;
use MauticPlugin\CustomObjectsBundle\Event\CustomItemListDbalQueryEvent;
use PHPUnit\Framework\Attributes\Group;

#[Group('area:co-items')]
#[Group('risk:DADOS')]
class CustomItemListDbalQueryEventTest extends \PHPUnit\Framework\TestCase
{
    public function testGettersSetters(): void
    {
        $queryBuilder  = $this->createMock(QueryBuilder::class);
        $tableConfig   = $this->createMock(TableConfig::class);
        $event         = new CustomItemListDbalQueryEvent($queryBuilder, $tableConfig);

        $this->assertSame($queryBuilder, $event->getQueryBuilder());
        $this->assertSame($tableConfig, $event->getTableConfig());
    }
}
