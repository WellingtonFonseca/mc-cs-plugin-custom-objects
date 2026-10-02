<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Controller\Api;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use MauticPlugin\CustomObjectsBundle\Controller\Api\CustomItemSearchApiController;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CustomItemSearchApiControllerTest extends TestCase
{
    private CustomObjectModel $customObjectModel;

    private EntityManagerInterface $em;

    private QueryBuilder $queryBuilder;

    private CustomItemSearchApiController $controller;

    protected function setUp(): void
    {
        $this->customObjectModel = $this->createMock(CustomObjectModel::class);
        $this->em                = $this->createMock(EntityManagerInterface::class);

        $query = $this->createMock(AbstractQuery::class);
        $query->method('getResult')->willReturn([]);

        $this->queryBuilder = $this->getMockBuilder(QueryBuilder::class)
            ->setConstructorArgs([$this->em])
            ->onlyMethods(['getQuery'])
            ->getMock();
        $this->queryBuilder->method('getQuery')->willReturn($query);
        $this->em->method('createQueryBuilder')->willReturn($this->queryBuilder);

        $this->controller = new CustomItemSearchApiController(
            $this->customObjectModel,
            $this->createMock(CustomItemModel::class),
            $this->em
        );
    }

    /**
     * @param array<string, string> $fieldTypesByAlias
     */
    private function objectWithFields(array $fieldTypesByAlias): void
    {
        $fields = [];
        $id     = 1;
        foreach ($fieldTypesByAlias as $alias => $type) {
            $field = $this->createMock(CustomField::class);
            $field->method('getAlias')->willReturn($alias);
            $field->method('getType')->willReturn($type);
            $field->method('getId')->willReturn($id++);
            $fields[] = $field;
        }

        $object = $this->createMock(CustomObject::class);
        $object->method('getCustomFields')->willReturn(new \Doctrine\Common\Collections\ArrayCollection($fields));
        $this->customObjectModel->method('fetchEntityByAlias')->willReturn($object);
    }

    /**
     * @return array<string, mixed>
     */
    private function params(): array
    {
        $params = [];
        foreach ($this->queryBuilder->getParameters() as $p) {
            $params[$p->getName()] = $p->getValue();
        }

        return $params;
    }

    public function testUnknownObjectIs404(): void
    {
        $this->customObjectModel->method('fetchEntityByAlias')->willThrowException(new NotFoundException('x'));

        $this->assertSame(404, $this->controller->listAction(new Request(), 'nope')->getStatusCode());
    }

    public function testUnknownFieldIs404(): void
    {
        $this->objectWithFields(['nome1' => 'text']);

        $this->assertSame(404, $this->controller->listAction(new Request(['nada' => 'x']), 'o')->getStatusCode());
    }

    public function testTextStaysAnExactMatch(): void
    {
        $this->objectWithFields(['nome1' => 'text']);

        $response = $this->controller->listAction(new Request(['nome1' => 'Disciplina 01']), 'o');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringNotContainsString('LIKE', $this->queryBuilder->getDQL());
        $this->assertSame('Disciplina 01', $this->params()['sval0']);
    }

    public function testDateStaysAnEqualityOnTheDay(): void
    {
        $this->objectWithFields(['inicio' => 'date']);

        $this->controller->listAction(new Request(['inicio' => '2026-09-20']), 'o');

        $this->assertSame('2026-09-20 00:00:00', $this->params()['sval0']);
        $this->assertSame('2026-09-21 00:00:00', $this->params()['svalend0']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function newTypes(): array
    {
        return [
            'int'         => ['int', '1'],
            'decimal'     => ['decimal', '1'],
            'select'      => ['select', 'x'],
            'multiselect' => ['multiselect', 'x'],
            'datetime'    => ['datetime', '2026-09-20'],
            'country'     => ['country', 'BR'],
            'url'         => ['url', 'x'],
        ];
    }

    /**
     * @dataProvider newTypes
     */
    public function testNewTypesAreAccepted(string $type, string $value): void
    {
        $this->objectWithFields(['campo' => $type]);

        $response = $this->controller->listAction(new Request(['campo' => $value]), 'o');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('EXISTS', $this->queryBuilder->getDQL());
    }

    public function testComparisonPrefixWorksInTheApiToo(): void
    {
        $this->objectWithFields(['progresso' => 'decimal']);

        $this->controller->listAction(new Request(['progresso' => '>=0.5']), 'o');

        $this->assertStringContainsString('.value >= :sval0', $this->queryBuilder->getDQL());
    }

    public function testInvalidValueIs400(): void
    {
        $this->objectWithFields(['inicio' => 'date']);

        $this->assertSame(400, $this->controller->listAction(new Request(['inicio' => '31/02/2026']), 'o')->getStatusCode());
    }

    public function testUnsupportedTypeIs400(): void
    {
        $this->objectWithFields(['x' => 'unicorn']);

        $this->assertSame(400, $this->controller->listAction(new Request(['x' => '1']), 'o')->getStatusCode());
    }
}
