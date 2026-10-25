<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Model;

use PHPUnit\Framework\MockObject\Stub;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Twig\Helper\DateHelper;
use Mautic\CoreBundle\Twig\Helper\FormatterHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Entity\Import;
use Mautic\LeadBundle\Entity\LeadRepository;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\InvalidValueException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemImportModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Mautic\CoreBundle\Helper\UserHelper;

/**
 * CustomItemModel::save() writes the item and only then validates each value, so a CSV row with an invalid value used
 * to be refused but leave an item with only its name behind. A row is one transaction: rolled back when the save fails.
 * (CustomItemImportModelTest skips itself on a core without LeadRepository::exists, so these live apart.)
 */
#[AllowMockObjectsWithoutExpectations]
class CustomItemImportModelTransactionTest extends TestCase
{
    private CustomItemModel&Stub $customItemModel;

    private EntityManager&MockObject $entityManager;

    private Import&Stub $import;

    private CustomObject&Stub $customObject;

    private CustomItemImportModel $model;

    protected function setUp(): void
    {
        $this->customItemModel = $this->createStub(CustomItemModel::class);
        $this->entityManager   = $this->createMock(EntityManager::class);
        $this->import          = $this->createStub(Import::class);
        $this->customObject    = $this->createStub(CustomObject::class);

        $this->customObject->method('getCustomFields')->willReturn(new ArrayCollection());

        // only the name is mapped: no field value is needed to reach the save
        $this->import->method('getMatchedFields')->willReturn(['name' => 'customItemName']);

        $translator = $this->createStub(Translator::class);
        $parameters = $this->createStub(CoreParametersHelper::class);
        $formatter  = new FormatterHelper(new DateHelper('F j, Y g:i a T', 'D, M d', 'F j, Y', 'g:i a', $translator, $parameters), $translator);

        $this->model = new CustomItemImportModel(
            $this->entityManager,
            $this->createMock(CorePermissions::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            $translator,
            $this->createMock(UserHelper::class),
            $this->createMock(LoggerInterface::class),
            $parameters,
            $this->customItemModel,
            $formatter
        );
    }

    public function testARowThatFailsIsRolledBackAndTheErrorIsRethrown(): void
    {
        $this->customItemModel->method('save')->willThrowException(new InvalidValueException('bad value'));

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('commit');
        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->expects($this->once())->method('detach');

        $this->expectException(InvalidValueException::class);

        $this->model->import($this->import, ['name' => 'Row'], $this->customObject);
    }

    public function testASuccessfulRowIsCommitted(): void
    {
        $this->customItemModel->method('save')->willReturn($this->createMock(CustomItem::class));
        $this->entityManager->method('getRepository')->willReturn($this->createMock(LeadRepository::class));

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->expects($this->once())->method('commit');
        $connection->expects($this->never())->method('rollBack');
        $this->entityManager->method('getConnection')->willReturn($connection);

        $this->model->import($this->import, ['name' => 'Row'], $this->customObject);
    }
}
