<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\CoreBundle\Twig\Helper\DateHelper;
use Mautic\CoreBundle\Twig\Helper\FormatterHelper;
use Mautic\LeadBundle\Entity\Import;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Provider\FilterOperatorProviderInterface;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\TextareaType;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItemXrefContact;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemImportModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * How one CSV row becomes an item: which columns are read, how the special ones (name, id, linked contacts) are matched, the owner,
 * the transaction of the row and the contacts it links.
 */
class CustomItemImportModelFlowTest extends TestCase
{
    private EntityManager&MockObject $em;

    private Connection&MockObject $connection;

    private CustomItemModel&MockObject $itemModel;

    private Import&MockObject $import;

    private CustomObject&MockObject $object;

    private LeadRepository&MockObject $leads;

    private CustomItemImportModel $model;

    protected function setUp(): void
    {
        $this->em         = $this->createMock(EntityManager::class);
        $this->connection = $this->createMock(Connection::class);
        $this->em->method('getConnection')->willReturn($this->connection);
        $this->itemModel = $this->createMock(CustomItemModel::class);
        $this->import    = $this->createMock(Import::class);
        $this->object    = $this->createMock(CustomObject::class);
        $field = $this->createMock(CustomField::class);
        $field->method('getId')->willReturn(33);
        $field->method('getTypeObject')->willReturn(new TextareaType($this->createMock(Translator::class), $this->createMock(FilterOperatorProviderInterface::class)));
        $this->object->method('getCustomFields')->willReturn(new ArrayCollection([$field]));
        $this->leads = $this->createMock(LeadRepository::class);
        $this->em->method('getRepository')->with(Lead::class)->willReturn($this->leads);

        $translator = $this->createMock(Translator::class);
        $params     = $this->createMock(CoreParametersHelper::class);
        $formatter  = new FormatterHelper(new DateHelper('F j, Y g:i a T', 'D, M d', 'F j, Y', 'g:i a', $translator, $params), $translator);
        $this->model = new CustomItemImportModel(
            $this->em,
            $this->createMock(CorePermissions::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            $translator,
            $this->createMock(UserHelper::class),
            $this->createMock(LoggerInterface::class),
            $params,
            $this->itemModel,
            $formatter
        );
    }

    public function testTheNameIsSetAndAColumnMissingFromTheRowIsSkipped(): void
    {
        $this->import->method('getMatchedFields')->willReturn(['n' => 'customItemName', 'absent' => 'customItemName']);
        $this->itemModel->expects($this->once())->method('save')
            ->with($this->callback(function (CustomItem $item): bool {
                $this->assertSame('Disciplina X', $item->getName());

                return true;
            }))
            ->willReturnArgument(0);

        $this->assertFalse($this->model->import($this->import, ['n' => 'Disciplina X'], $this->object));
    }

    public function testAColumnThatIsSkippedOrHandledDoesNotStopTheNextOnes(): void
    {
        $this->import->method('getMatchedFields')->willReturn(['absent' => 'customItemName', 'c' => 'linkedContactIds', 'n' => 'customItemName']);
        $this->itemModel->expects($this->once())->method('save')
            ->with($this->callback(function (CustomItem $item): bool {
                $this->assertSame('Last', $item->getName());

                return true;
            }))
            ->willReturnArgument(0);
        $this->itemModel->method('linkEntity')->willReturn($this->createMock(CustomItemXrefContact::class));

        $this->model->import($this->import, ['c' => '5', 'n' => 'Last'], $this->object);
    }

    public function testTheSpecialColumnsAreMatchedInAnyCaseAndNeverBecomeFieldValues(): void
    {
        $this->import->method('getMatchedFields')->willReturn(['n' => 'CUSTOMITEMNAME', 'id' => 'CustomItemID', 'c' => 'LINKEDCONTACTIDS']);
        $existing = new CustomItem($this->object);
        $existing->setName('Old');
        $this->itemModel->expects($this->once())->method('fetchEntity')->with(77)->willReturn($existing);
        $this->itemModel->expects($this->once())->method('populateCustomFields')->with($existing)->willReturnArgument(0);
        $this->itemModel->expects($this->once())->method('save')
            ->with($this->callback(function (CustomItem $item) use ($existing): bool {
                $this->assertSame($existing, $item);
                $this->assertSame('New', $item->getName());

                return true;
            }))
            ->willReturnArgument(0);
        $linked = [];
        $this->itemModel->method('linkEntity')->willReturnCallback(function (CustomItem $item, string $type, int $id) use (&$linked) {
            $linked[] = [$type, $id];

            return $this->createMock(CustomItemXrefContact::class);
        });

        $this->model->import($this->import, ['n' => 'New', 'id' => '77', 'c' => '5,6'], $this->object);

        $this->assertSame([['contact', 5], ['contact', 6]], $linked);
    }

    public function testAnItemThatWasUpdatedMakesTheRowAnUpdate(): void
    {
        $this->import->method('getMatchedFields')->willReturn([]);
        $saved = $this->createMock(CustomItem::class);
        $saved->method('hasBeenUpdated')->willReturn(true);
        $this->itemModel->method('save')->willReturn($saved);

        $this->assertTrue($this->model->import($this->import, [], $this->object));
    }

    public function testAnExistingItemIsAnUpdateEvenWhenNothingSaysSo(): void
    {
        $this->import->method('getMatchedFields')->willReturn(['id' => 'customItemId']);
        $existing = $this->createMock(CustomItem::class);
        $existing->method('getId')->willReturn(12);
        $this->itemModel->method('fetchEntity')->willReturn($existing);
        $this->itemModel->method('populateCustomFields')->willReturnArgument(0);
        $saved = $this->createMock(CustomItem::class);
        $saved->method('hasBeenUpdated')->willReturn(false);
        $this->itemModel->method('save')->willReturn($saved);

        $this->assertTrue($this->model->import($this->import, ['id' => '12'], $this->object));
    }

    public function testTheOwnerOfTheImportOwnsTheItem(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);
        $user->method('getName')->willReturn('Owner');
        $this->import->method('getMatchedFields')->willReturn([]);
        $this->import->method('getDefault')->with('owner')->willReturn(9);
        $this->em->expects($this->once())->method('find')->with(User::class, 9)->willReturn($user);
        $this->itemModel->expects($this->once())->method('save')
            ->with($this->callback(function (CustomItem $item) use ($user): bool {
                $this->assertSame(5, $item->getCreatedBy());
                $this->assertSame('Owner', $item->getCreatedByUser());

                return true;
            }))
            ->willReturnArgument(0);

        $this->model->import($this->import, [], $this->object);
    }

    public function testWithoutAnOwnerNoUserIsLookedUp(): void
    {
        $this->import->method('getMatchedFields')->willReturn([]);
        $this->import->method('getDefault')->willReturn(null);
        $this->em->expects($this->never())->method('find');
        $this->itemModel->method('save')->willReturnArgument(0);

        $this->model->import($this->import, [], $this->object);
    }

    public function testARowThatFailsIsRolledBackDetachedAndTheErrorGoesOn(): void
    {
        $this->import->method('getMatchedFields')->willReturn([]);
        $this->itemModel->method('save')->willThrowException(new \RuntimeException('invalid'));
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');
        $this->em->expects($this->once())->method('detach')->with($this->isInstanceOf(CustomItem::class));

        $this->expectException(\RuntimeException::class);
        $this->model->import($this->import, [], $this->object);
    }

    public function testAGoodRowIsCommitted(): void
    {
        $this->import->method('getMatchedFields')->willReturn([]);
        $this->itemModel->method('save')->willReturnArgument(0);
        $this->connection->expects($this->once())->method('commit');
        $this->connection->expects($this->never())->method('rollBack');

        $this->model->import($this->import, [], $this->object);
    }
}
