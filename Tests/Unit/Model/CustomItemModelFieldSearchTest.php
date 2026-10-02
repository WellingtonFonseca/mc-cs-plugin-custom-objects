<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use MauticPlugin\CustomObjectsBundle\DTO\TableConfig;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Model\CustomFieldValueModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Repository\CustomItemRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The item list search with "alias:value" terms. The free-text path
 * (no colon) is covered by CustomItemModelTest.
 */
class CustomItemModelFieldSearchTest extends TestCase
{
    private CustomItemModel $model;

    private EntityManager $em;

    protected function setUp(): void
    {
        $inicio = $this->field(11, 'inicio', 'date');
        $nome   = $this->field(12, 'nome1', 'text');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->with(['customObject' => 44])->willReturn([$inicio, $nome]);

        $this->em = $this->createMock(EntityManager::class);
        $this->em->method('createQueryBuilder')->willReturnCallback(fn () => new QueryBuilder($this->em));
        $this->em->method('getExpressionBuilder')->willReturn(new \Doctrine\ORM\Query\Expr());
        $this->em->method('getRepository')->with(CustomField::class)->willReturn($repository);

        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->method('getUser')->willReturn(null); // CLI: no owner filter

        $this->model = new CustomItemModel(
            $this->em,
            $this->createMock(CorePermissions::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(Translator::class),
            $userHelper,
            $this->createMock(LoggerInterface::class),
            $this->createMock(CoreParametersHelper::class),
            $this->createMock(CustomItemRepository::class),
            $this->createMock(CustomItemPermissionProvider::class),
            $this->createMock(CustomFieldValueModel::class),
            $this->createMock(ValidatorInterface::class)
        );
    }

    private function field(int $id, string $alias, string $type): CustomField
    {
        $field = $this->createMock(CustomField::class);
        $field->method('getId')->willReturn($id);
        $field->method('getAlias')->willReturn($alias);
        $field->method('getType')->willReturn($type);

        return $field;
    }

    private function build(string $search): QueryBuilder
    {
        $tableConfig = new TableConfig(10, 1, 'CustomItem.id');
        $tableConfig->addParameter('customObjectId', 44);
        $tableConfig->addParameter('search', $search);

        $method = new \ReflectionMethod($this->model, 'createListOrmQueryBuilder');
        $method->setAccessible(true);

        return $method->invoke($this->model, $tableConfig);
    }

    /**
     * @return array<string, mixed>
     */
    private function params(QueryBuilder $qb): array
    {
        $params = [];
        foreach ($qb->getParameters() as $parameter) {
            $params[$parameter->getName()] = $parameter->getValue();
        }

        return $params;
    }

    public function testFieldTermFiltersByTheFieldAndSkipsFullText(): void
    {
        $qb     = $this->build('inicio:23/08/2026');
        $params = $this->params($qb);

        $this->assertStringContainsString('EXISTS', $qb->getDQL());
        $this->assertStringNotContainsString('MATCH', $qb->getDQL());
        $this->assertSame(11, $params['sfield0']);
        $this->assertSame('2026-08-23 00:00:00', $params['sval0']);
        $this->assertSame('2026-08-24 00:00:00', $params['svalend0']);
        $this->assertArrayNotHasKey('search', $params);
    }

    public function testTermsAreCombinedWithAnd(): void
    {
        $qb = $this->build('inicio:23/08/2026 nome1:"Disciplina 01"');

        $this->assertSame(2, substr_count($qb->getDQL(), 'EXISTS'));
        $this->assertSame('%disciplina 01%', $this->params($qb)['sval1']);
    }

    public function testQuotedValueArrivingHtmlEncodedFromTheController(): void
    {
        $qb = $this->build('nome1:&#34;Disciplina 01&#34;');

        $this->assertSame('%disciplina 01%', $this->params($qb)['sval0']);
    }

    public function testUnknownAliasIsIgnoredAndTheListIsNotBroken(): void
    {
        $qb = $this->build('nao_existe:abc');

        $this->assertStringNotContainsString('EXISTS', $qb->getDQL());
        $this->assertStringNotContainsString('MATCH', $qb->getDQL());
        $this->assertArrayNotHasKey('sfield0', $this->params($qb));
    }

    public function testUnknownAliasDoesNotDropTheOtherTerms(): void
    {
        $qb = $this->build('nao_existe:abc inicio:2026-08-23');

        $this->assertSame(1, substr_count($qb->getDQL(), 'EXISTS'));
        $this->assertSame(11, $this->params($qb)['sfield1']);
    }

    public function testInvalidValueMatchesNothing(): void
    {
        $qb = $this->build('inicio:31/02/2026');

        $this->assertStringContainsString('CustomItem.id IS NULL', $qb->getDQL());
        $this->assertStringNotContainsString('EXISTS', $qb->getDQL());
    }

    public function testWordsAroundTheTermStillUseFullText(): void
    {
        $qb     = $this->build('algebra inicio:2026-08-23');
        $params = $this->params($qb);

        $this->assertStringContainsString('MATCH (CustomItem.name) AGAINST (:search BOOLEAN)', $qb->getDQL());
        $this->assertStringContainsString('EXISTS', $qb->getDQL());
        $this->assertSame('(+algebra*) >"algebra"', $params['search']);
    }

    public function testAliasOfAnotherObjectIsNotFiltered(): void
    {
        // an alias of ANOTHER object (not in findBy of object 44) must not be filtered on
        $qb = $this->build('outro_objeto_campo:1');

        $this->assertStringNotContainsString('EXISTS', $qb->getDQL());
    }

    public function testWarningsListUnknownAliasesAndInvalidValues(): void
    {
        $warnings = $this->model->getSearchWarnings(44, 'nao_existe:1 inicio:xx nome1:ok');

        $this->assertSame(
            [
                ['type' => 'unknown_alias', 'alias' => 'nao_existe'],
                ['type' => 'invalid_value', 'alias' => 'inicio'],
            ],
            $warnings
        );
    }

    public function testNoWarningsForPlainTextOrValidTerms(): void
    {
        $this->assertSame([], $this->model->getSearchWarnings(44, 'algebra'));
        $this->assertSame([], $this->model->getSearchWarnings(44, 'inicio:2026-08-23'));
        $this->assertSame([], $this->model->getSearchWarnings(44, ''));
    }
}
