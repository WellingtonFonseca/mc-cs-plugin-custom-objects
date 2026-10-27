<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Helper;

use Mautic\CategoryBundle\Entity\Category;
use Mautic\CategoryBundle\Entity\CategoryRepository;
use Mautic\CategoryBundle\Model\CategoryModel;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\CustomFieldTypeInterface;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldFactory;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Exception\DefinitionImportException;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Helper\CustomObjectDefinitionImporter;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomFieldTypeProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Repository\CustomFieldRepository;
use MauticPlugin\CustomObjectsBundle\Repository\CustomItemRepository;
use MauticPlugin\CustomObjectsBundle\Repository\CustomObjectRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CustomObjectDefinitionImporterTest extends TestCase
{
    /** @var CustomObjectModel|MockObject */
    private $model;

    /** @var CustomObjectRepository|MockObject */
    private $objectRepository;

    /** @var CustomFieldRepository|MockObject */
    private $fieldRepository;

    /** @var CustomObjectPermissionProvider|MockObject */
    private $permissionProvider;

    /** @var CustomItemRepository|MockObject */
    private $itemRepository;

    /** @var CategoryModel|MockObject */
    private $categoryModel;

    /** @var CategoryRepository|MockObject */
    private $categoryRepository;

    /** @var CorePermissions|MockObject */
    private $security;

    /** @var ValidatorInterface|MockObject */
    private $validator;

    /** @var Connection|MockObject */
    private $connection;

    private $typeProvider;
    private $itemModel;
    private $entityManager;
    private $translator;

    private CustomObjectDefinitionImporter $importer;

    /** @var string[] the collaborators the running test sets expectations on: real mocks, the others are stubs (no PHPUnit notice) */
    private array $mocked = [];

    protected function setUp(): void
    {
        $this->build();
    }

    /**
     * Makes (or remakes) every collaborator and the importer. A test that calls ->expects() on a collaborator asks for it first with
     * useMock('name'): PHPUnit gives a notice for a mock nobody set an expectation on, and a stub cannot have one.
     */
    private function build(): void
    {
        $this->model = $this->collaborator('model', CustomObjectModel::class);
        // an alias is clean when it is lower case with letters, numbers and hyphens
        $this->model->method('cleanAlias')->willReturnCallback(static fn (string $alias): string => strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', $alias)));
        $this->model->method('save')->willReturnArgument(0);
        $this->objectRepository   = $this->collaborator('objectRepository', CustomObjectRepository::class);
        $this->fieldRepository    = $this->collaborator('fieldRepository', CustomFieldRepository::class);
        $this->permissionProvider = $this->collaborator('permissionProvider', CustomObjectPermissionProvider::class);
        $this->itemRepository     = $this->collaborator('itemRepository', CustomItemRepository::class);
        $this->itemModel          = $this->createStub(CustomItemModel::class);
        $this->itemModel->method('getRepository')->willReturn($this->itemRepository);
        $this->categoryRepository = $this->collaborator('categoryRepository', CategoryRepository::class);
        $this->categoryModel      = $this->collaborator('categoryModel', CategoryModel::class);
        $this->categoryModel->method('getRepository')->willReturn($this->categoryRepository);
        $this->security = $this->collaborator('security', CorePermissions::class);
        $this->security->method('isGranted')->willReturn(true);
        $this->validator = $this->collaborator('validator', ValidatorInterface::class);
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->connection    = $this->collaborator('connection', Connection::class);
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($this->connection);

        $text = $this->createStub(CustomFieldTypeInterface::class);
        $text->method('getSymfonyFormFieldType')->willReturn(TextType::class);
        $select = $this->createStub(CustomFieldTypeInterface::class);
        $select->method('getSymfonyFormFieldType')->willReturn(ChoiceType::class);

        $this->typeProvider = $this->createStub(CustomFieldTypeProvider::class);
        $this->typeProvider->method('getType')->willReturnCallback(static function (string $key) use ($text, $select) {
            return match ($key) {
                'text'   => $text,
                'select' => $select,
                default  => throw new NotFoundException("no {$key}"),
            };
        });

        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id.($params ? ' '.json_encode($params) : ''));

        $this->importer = new CustomObjectDefinitionImporter(
            $this->model,
            $this->objectRepository,
            $this->fieldRepository,
            new CustomFieldFactory($this->typeProvider),
            $this->itemModel,
            $this->categoryModel,
            $this->permissionProvider,
            $this->security,
            $this->validator,
            $this->entityManager,
            $this->translator
        );
    }

    /**
     * @return MockObject|Stub
     */
    private function collaborator(string $name, string $class)
    {
        return in_array($name, $this->mocked, true) ? $this->createMock($class) : $this->createStub($class);
    }

    /**
     * Before the test sets ->expects() on $this->{$name}: from here on it is a real mock. Call it first thing in the test.
     */
    private function useMock(string ...$names): void
    {
        $this->mocked = array_merge($this->mocked, $names);
        $this->build();
    }

    /**
     * @return mixed[]
     */
    private function definition(): array
    {
        return [
            'format'       => 'custom-object-definition',
            'version'      => 1,
            'alias'        => 'course',
            'nameSingular' => 'Curso',
            'namePlural'   => 'Cursos',
            'description'  => 'Os cursos',
            'language'     => 'pt_BR',
            'type'         => 0,
            'customFields' => [
                ['alias' => 'b', 'label' => 'B', 'type' => 'select', 'order' => 9, 'required' => true, 'defaultValue' => 'x',
                    'isUniqueIdentifier' => true, 'showInContactDetailList' => true, 'params' => ['placeholder' => 'pick'],
                    'options' => [['label' => 'Two', 'value' => '2', 'order' => 2], ['label' => 'One', 'value' => '1', 'order' => 1]]],
                ['alias' => 'a', 'label' => 'A', 'type' => 'text', 'order' => 3],
            ],
        ];
    }

    /**
     * @param mixed[] $definition
     *
     * @return string[]
     */
    private function errorsOf(array $definition): array
    {
        try {
            $this->importer->import((string) json_encode($definition));
        } catch (DefinitionImportException $e) {
            return $e->getErrors();
        }

        $this->fail('The import should have been refused');
    }

    public function testTheObjectAndItsFieldsAreCreatedInTheSequenceOfTheFile(): void
    {
        $this->useMock('model');
        $this->model->expects($this->once())->method('save');

        $object = $this->importer->import((string) json_encode($this->definition()))->getCustomObject();

        $this->assertSame('course', $object->getAlias());
        $this->assertSame('Curso', $object->getNameSingular());
        $this->assertSame('Cursos', $object->getNamePlural());
        $this->assertSame('Os cursos', $object->getDescription());
        $this->assertSame('pt_BR', $object->getLanguage());
        $this->assertSame(CustomObject::TYPE_MASTER, $object->getType());
        $fields = $object->getCustomFields()->toArray();
        $this->assertSame(['a', 'b'], array_map(static fn ($f) => $f->getAlias(), $fields));
        $this->assertSame([0, 1], array_map(static fn ($f) => $f->getOrder(), $fields));
        $this->assertSame($object, $fields[0]->getCustomObject());
    }

    public function testTheSettingsOfAFieldAreKept(): void
    {
        $object = $this->importer->import((string) json_encode($this->definition()))->getCustomObject();
        $b      = $object->getCustomFields()->toArray()[1];

        $this->assertSame('B', $b->getLabel());
        $this->assertSame('select', $b->getType());
        $this->assertTrue($b->isRequired());
        $this->assertTrue($b->getIsUniqueIdentifier());
        $this->assertTrue($b->isShowInContactDetailList());
        $this->assertFalse($b->isShowInCustomObjectDetailList());
        $this->assertSame('x', $b->getStoredDefaultValue());
        $this->assertSame('pick', $b->getParams()->getPlaceholder());
        $this->assertSame(['One' => '1', 'Two' => '2'], $b->getChoices());
    }

    public function testRelatedObjectsAreFoundByAlias(): void
    {
        $master = new CustomObject();
        $master->setAlias('school');
        $this->objectRepository->method('findOneBy')->willReturnCallback(static fn (array $criteria) => 'school' === $criteria['alias'] ? $master : null);
        $definition                      = $this->definition();
        $definition['type']              = CustomObject::TYPE_RELATIONSHIP;
        $definition['masterObjectAlias'] = 'school';

        $object = $this->importer->import((string) json_encode($definition))->getCustomObject();

        $this->assertSame($master, $object->getMasterObject());
        $this->assertNull($object->getRelationshipObject());
    }

    public function testNothingIsSavedWhenThereIsAnError(): void
    {
        $this->useMock('model');
        $this->model->expects($this->never())->method('save');

        $this->errorsOf(['format' => 'other']);
    }

    public function testAnythingThatIsNotAJsonObjectIsRefused(): void
    {
        $this->assertSame(['custom.object.import.error.json'], $this->errorsOfRaw('not json'));
        $this->assertSame(['custom.object.import.error.json'], $this->errorsOfRaw('"text"'));
    }

    /**
     * @return string[]
     */
    private function errorsOfRaw(string $json): array
    {
        try {
            $this->importer->import($json);
        } catch (DefinitionImportException $e) {
            return $e->getErrors();
        }

        $this->fail('The import should have been refused');
    }

    public function testTheFormatAndTheVersionAreChecked(): void
    {
        $definition           = $this->definition();
        $definition['format'] = 'other';
        $this->assertContains('custom.object.import.error.format', $this->errorsOf($definition));

        $definition            = $this->definition();
        $definition['version'] = 2;
        $this->assertStringStartsWith('custom.object.import.error.version', $this->errorsOf($definition)[0]);
    }

    public function testTheRequiredKeysTheTypeAndTheFieldListAreChecked(): void
    {
        $definition = $this->definition();
        unset($definition['alias'], $definition['customFields']);
        $definition['nameSingular'] = ' ';
        $definition['type']         = 5;

        $errors = implode("\n", $this->errorsOf($definition));

        $this->assertStringContainsString('"%key%":"alias"', $errors);
        $this->assertStringContainsString('"%key%":"nameSingular"', $errors);
        $this->assertStringNotContainsString('"%key%":"namePlural"', $errors);
        $this->assertStringContainsString('custom.object.import.error.type', $errors);
        $this->assertStringContainsString('custom.object.import.error.fields', $errors);
    }

    public function testEveryProblemOfTheFieldsIsListedTogether(): void
    {
        $this->fieldRepository->method('isAliasUnique')->willReturnCallback(static fn (string $alias): bool => 'taken' === $alias);
        $definition                  = $this->definition();
        $definition['customFields']  = [
            ['alias' => 'taken', 'label' => 'T', 'type' => 'text', 'order' => 1],
            ['alias' => 'dup', 'label' => 'D', 'type' => 'text', 'order' => 2],
            ['alias' => 'dup', 'label' => 'D2', 'type' => 'text', 'order' => 3],
            ['alias' => 'odd', 'label' => 'O', 'type' => 'nonexistent', 'order' => 4],
            ['alias' => 'nolabel', 'type' => 'text', 'order' => 5],
            'not an object',
        ];
        $definition['masterObjectAlias'] = 'missing';

        $errors = implode("\n", $this->errorsOf($definition));

        $this->assertStringContainsString('custom.object.import.error.field_exists {"%alias%":"taken"}', $errors);
        $this->assertStringContainsString('custom.object.import.error.field_repeated {"%alias%":"dup"}', $errors);
        $this->assertStringContainsString('custom.object.import.error.field_type {"%alias%":"odd","%type%":"nonexistent"}', $errors);
        $this->assertStringContainsString('"%position%":"5","%key%":"label"', $errors);
        $this->assertStringContainsString('custom.object.import.error.field_shape {"%position%":"6"}', $errors);
        $this->assertStringContainsString('custom.object.import.error.related {"%alias%":"missing"}', $errors);
    }

    public function testTheOptionalKeysTakeTheirDefaults(): void
    {
        $definition                 = $this->definition();
        $definition['customFields'] = [['alias' => 'a', 'label' => 'A', 'type' => 'text']];
        unset($definition['description'], $definition['language'], $definition['type']);

        $object = $this->importer->import((string) json_encode($definition))->getCustomObject();
        $field  = $object->getCustomFields()->first();

        $this->assertSame(CustomObject::TYPE_MASTER, $object->getType());
        $this->assertNull($object->getDescription());
        $this->assertNull($object->getLanguage());
        $this->assertFalse($field->isRequired());
        $this->assertTrue((bool) $field->getIsPublished());
        $this->assertFalse($field->getIsUniqueIdentifier());
        $this->assertFalse($field->isShowInCustomObjectDetailList());
        $this->assertFalse($field->isShowInContactDetailList());
        $this->assertNull($field->getStoredDefaultValue());
        $this->assertSame(0, $field->getOrder());
    }

    public function testARelationshipObjectKeepsItsTypeAndItsMaster(): void
    {
        $master = new CustomObject();
        $master->setAlias('school');
        $this->objectRepository->method('findOneBy')->willReturnCallback(static fn (array $criteria) => 'school' === $criteria['alias'] ? $master : null);
        $definition                      = $this->definition();
        $definition['type']              = CustomObject::TYPE_RELATIONSHIP;
        $definition['masterObjectAlias'] = 'school';
        // the child of a master is never taken from the file: the plugin links it when the child is saved (a second link would clash with the
        // unique index of the column)
        $definition['relationshipObjectAlias'] = 'student';

        $object = $this->importer->import((string) json_encode($definition))->getCustomObject();

        $this->assertSame(CustomObject::TYPE_RELATIONSHIP, $object->getType());
        $this->assertSame($master, $object->getMasterObject());
        $this->assertNull($object->getRelationshipObject());
    }

    public function testFieldsAsAJsonObjectAndMissingOrdersKeepTheSequenceOfTheFile(): void
    {
        $definition                 = $this->definition();
        $definition['customFields'] = [
            'first'  => ['alias' => 'first', 'label' => 'F', 'type' => 'text'],
            'second' => ['alias' => 'second', 'label' => 'S', 'type' => 'text'],
            'early'  => ['alias' => 'early', 'label' => 'E', 'type' => 'text', 'order' => -1],
        ];

        $fields = $this->importer->import((string) json_encode($definition))->getCustomObject()->getCustomFields()->toArray();

        $this->assertSame(['early', 'first', 'second'], array_map(static fn ($f) => $f->getAlias(), $fields));
        $this->assertSame([0, 1, 2], array_map(static fn ($f) => $f->getOrder(), $fields));
    }

    public function testTheOptionsFollowTheirOrderAndTheEmptyOnesAreLeftOut(): void
    {
        $definition                 = $this->definition();
        $definition['customFields'] = [['alias' => 'a', 'label' => 'A', 'type' => 'select', 'options' => [
            ['label' => 'Last', 'value' => 'z', 'order' => 5],
            ['label' => 'No order', 'value' => 'n'],
            ['label' => 'Early', 'value' => 'e', 'order' => -2],
            'not an option',
            ['label' => 'No value'],
        ]]];

        $field = $this->importer->import((string) json_encode($definition))->getCustomObject()->getCustomFields()->first();

        $this->assertSame(['Early' => 'e', 'No order' => 'n', 'Last' => 'z'], $field->getChoices());
    }

    public function testTheMessagesCarryTheirValues(): void
    {
        $definition            = $this->definition();
        $definition['version'] = 7;
        $this->assertSame(['custom.object.import.error.version {"%version%":"7"}'], $this->errorsOf($definition));

    }

    public function testWhatIsWrongWithAFieldDoesNotStopTheNextOnesFromBeingChecked(): void
    {
        $definition                 = $this->definition();
        $definition['customFields'] = [
            ['label' => 'No alias', 'type' => 'text'],
            ['alias' => 'blank', 'label' => '   ', 'type' => 'text'],
            ['alias' => 'odd', 'label' => 'O', 'type' => 'nonexistent'],
        ];

        $errors = implode("\n", $this->errorsOf($definition));

        $this->assertStringContainsString('"%position%":"1","%key%":"alias"', $errors);
        $this->assertStringContainsString('"%position%":"2","%key%":"label"', $errors);
        $this->assertStringContainsString('field_type {"%alias%":"odd"', $errors);
    }

    public function testARelatedAliasThatIsNotATextIsRefused(): void
    {
        $definition                      = $this->definition();
        $definition['masterObjectAlias'] = ['x' => 1];

        $this->assertSame(['custom.object.import.error.related {"%alias%":"{\"x\":1}"}'], $this->errorsOf($definition));
    }

    private function existingObject(): CustomObject
    {
        $existing = new CustomObject();
        $existing->setAlias('course');
        $existing->setNameSingular('Original');
        foreach ([['a', 'text', 4], ['b', 'select', 9]] as [$alias, $type, $order]) {
            $field = new CustomField();
            $field->setAlias($alias);
            $field->setType($type);
            $field->setOrder($order);
            $existing->addCustomField($field);
        }
        $this->objectRepository->method('findOneBy')->willReturnCallback(static fn (array $c) => 'course' === $c['alias'] ? $existing : null);

        return $existing;
    }

    public function testAnExistingObjectReceivesOnlyTheFieldsItDoesNotHave(): void
    {
        $this->useMock('model');
        $existing = $this->existingObject();
        $this->fieldRepository->method('isAliasUnique')->willReturnCallback(static fn (string $alias): bool => in_array($alias, ['a', 'b'], true));
        $definition                 = $this->definition();
        $definition['nameSingular'] = 'Changed in the file';
        $definition['customFields'] = [
            ['alias' => 'c', 'label' => 'C', 'type' => 'text', 'order' => 5],
            ['alias' => 'a', 'label' => 'A changed', 'type' => 'text', 'order' => 1],
            ['alias' => 'd', 'label' => 'D', 'type' => 'text', 'order' => 2],
        ];
        $this->model->expects($this->once())->method('save')->with($existing);

        $result = $this->importer->import((string) json_encode($definition));

        $this->assertFalse($result->isCreated());
        $this->assertSame($existing, $result->getCustomObject());
        $this->assertSame(['d', 'c'], $result->getAddedAliases());
        $fields = [];
        foreach ($existing->getCustomFields() as $field) {
            $fields[$field->getAlias()] = [$field->getLabel(), $field->getOrder()];
        }
        // the new ones follow the sequence of the file and the last order the object uses (9); what it had is untouched
        $this->assertSame([null, 4], $fields['a']);
        $this->assertSame(['D', 10], $fields['d']);
        $this->assertSame(['C', 11], $fields['c']);
        $this->assertSame('Original', $existing->getNameSingular());
    }

    public function testNothingIsSavedWhenTheObjectAlreadyHasEveryField(): void
    {
        $this->useMock('model');
        $this->existingObject();
        $this->fieldRepository->method('isAliasUnique')->willReturn(true);
        $definition                 = $this->definition();
        $definition['customFields'] = [['alias' => 'a', 'label' => 'A', 'type' => 'text']];
        $this->model->expects($this->never())->method('save');

        $result = $this->importer->import((string) json_encode($definition));

        $this->assertSame([], $result->getAddedAliases());
        $this->assertSame([], $result->getTypeMismatches());
    }

    public function testADifferentTypeIsReportedApartFromTheSkippedOnes(): void
    {
        $this->existingObject();
        $this->fieldRepository->method('isAliasUnique')->willReturn(true);
        $definition                 = $this->definition();
        $definition['customFields'] = [['alias' => 'b', 'label' => 'B', 'type' => 'text']];

        $result = $this->importer->import((string) json_encode($definition));

        $this->assertSame(['custom.object.import.skipped_type {"%alias%":"b","%existing%":"select","%file%":"text"}'], $result->getTypeMismatches());
    }

    public function testAFieldAliasUsedByAnotherObjectIsStillAnError(): void
    {
        $this->existingObject();
        $this->fieldRepository->method('isAliasUnique')->willReturnCallback(static fn (string $alias): bool => in_array($alias, ['a', 'b', 'elsewhere'], true));
        $definition                 = $this->definition();
        $definition['customFields'] = [['alias' => 'elsewhere', 'label' => 'E', 'type' => 'text']];

        $this->assertSame(['custom.object.import.error.field_exists {"%alias%":"elsewhere"}'], $this->errorsOf($definition));
    }

    public function testWhoMayNotEditTheObjectCannotAddFieldsToIt(): void
    {
        $this->useMock('model');
        $this->existingObject();
        $this->permissionProvider->method('canEdit')->willThrowException(new ForbiddenException('no'));
        $this->model->expects($this->never())->method('save');

        $this->assertSame(['custom.object.import.error.forbidden {"%alias%":"course"}'], $this->errorsOf($this->definition()));
    }

    public function testAnObjectOpenedByAnotherPersonCannotReceiveFields(): void
    {
        $this->useMock('model');
        $this->existingObject();
        $this->model->method('isLocked')->willReturn(true);
        $this->model->expects($this->never())->method('save');

        $this->assertSame(['custom.object.import.error.locked {"%alias%":"course"}'], $this->errorsOf($this->definition()));
    }

    public function testTheRelatedObjectsOfTheFileAreNotCheckedForAnExistingObject(): void
    {
        $this->existingObject();
        $this->fieldRepository->method('isAliasUnique')->willReturn(false);
        $definition                      = $this->definition();
        $definition['masterObjectAlias'] = 'does-not-exist';

        $this->assertTrue($this->importer->import((string) json_encode($definition))->getCustomObject() instanceof CustomObject);
    }

    public function testTheResultOfANewObjectSaysItWasCreatedWithEveryField(): void
    {
        $result = $this->importer->import((string) json_encode($this->definition()));

        $this->assertTrue($result->isCreated());
        $this->assertSame(['a', 'b'], $result->getAddedAliases());
    }

    public function testTheProblemsOfTheObjectAndOfTheFieldsAreListedTogether(): void
    {
        $this->existingObject();
        $this->permissionProvider->method('canEdit')->willThrowException(new ForbiddenException('no'));
        $this->fieldRepository->method('isAliasUnique')->willReturnCallback(static fn (string $alias): bool => 'elsewhere' === $alias);
        $definition                 = $this->definition();
        $definition['customFields'] = [['alias' => 'elsewhere', 'label' => 'E', 'type' => 'text']];

        $errors = $this->errorsOf($definition);

        $this->assertCount(2, $errors);
        $this->assertStringStartsWith('custom.object.import.error.forbidden', $errors[0]);
        $this->assertStringStartsWith('custom.object.import.error.field_exists', $errors[1]);
    }

    public function testAnEmptyObjectAliasNeverLooksForAnExistingObject(): void
    {
        $this->useMock('objectRepository');
        $this->objectRepository->expects($this->never())->method('findOneBy');
        $definition          = $this->definition();
        $definition['alias'] = '';

        $this->assertStringContainsString('"%key%":"alias"', implode('', $this->errorsOf($definition)));
    }

    public function testARequiredFieldAddedToAnObjectWithItemsIsReported(): void
    {
        $this->useMock('itemRepository');
        $this->existingObject();
        $this->fieldRepository->method('isAliasUnique')->willReturnCallback(static fn (string $alias): bool => in_array($alias, ['a', 'b'], true));
        $this->itemRepository->expects($this->once())->method('getItemCount')->willReturn(3);
        $definition                 = $this->definition();
        $definition['customFields'] = [
            ['alias' => 'plain', 'label' => 'P', 'type' => 'text'],
            ['alias' => 'must', 'label' => 'M', 'type' => 'text', 'required' => true],
            ['alias' => 'code', 'label' => 'C', 'type' => 'text', 'isUniqueIdentifier' => true],
        ];

        $result = $this->importer->import((string) json_encode($definition));

        $this->assertSame(['plain', 'must', 'code'], $result->getAddedAliases());
        // a unique identifier is required by the plugin; the optional field is not reported
        $this->assertSame(['must', 'code'], $result->getRequiredWithItems());
    }

    public function testNothingIsReportedWhenTheObjectHasNoItems(): void
    {
        $this->existingObject();
        $this->itemRepository->method('getItemCount')->willReturn(0);
        $definition                 = $this->definition();
        $definition['customFields'] = [['alias' => 'must', 'label' => 'M', 'type' => 'text', 'required' => true]];

        $this->assertSame([], $this->importer->import((string) json_encode($definition))->getRequiredWithItems());
    }

    public function testTheItemsAreNotCountedWhenNoRequiredFieldIsAdded(): void
    {
        $this->useMock('itemRepository');
        $this->existingObject();
        $this->itemRepository->expects($this->never())->method('getItemCount');
        $definition                 = $this->definition();
        $definition['customFields'] = [['alias' => 'plain', 'label' => 'P', 'type' => 'text']];

        $this->assertSame([], $this->importer->import((string) json_encode($definition))->getRequiredWithItems());
    }

    public function testTheCheckSavesNothingForANewObject(): void
    {
        $this->useMock('model', 'categoryModel');
        $this->model->expects($this->never())->method('save');
        $this->categoryModel->expects($this->never())->method('saveEntity');

        $result = $this->importer->import((string) json_encode($this->definition()), false);

        $this->assertTrue($result->isCreated());
        $this->assertSame(['a', 'b'], $result->getAddedAliases());
    }

    public function testTheCheckSavesNothingAndLeavesAnExistingObjectAsItWas(): void
    {
        $this->useMock('model');
        $existing = $this->existingObject();
        $this->fieldRepository->method('isAliasUnique')->willReturnCallback(static fn (string $alias): bool => in_array($alias, ['a', 'b'], true));
        $this->itemRepository->method('getItemCount')->willReturn(2);
        $definition                 = $this->definition();
        $definition['customFields'] = [['alias' => 'c', 'label' => 'C', 'type' => 'text', 'required' => true], ['alias' => 'a', 'label' => 'A', 'type' => 'select']];
        $this->model->expects($this->never())->method('save');

        $result = $this->importer->import((string) json_encode($definition), false);

        $this->assertSame(['c'], $result->getAddedAliases());
        $this->assertSame(['c'], $result->getRequiredWithItems());
        $this->assertCount(2, $existing->getCustomFields());
    }

    public function testTheCheckStillRefusesAWrongFile(): void
    {
        $this->assertSame(['custom.object.import.error.format'], $this->errorsOf(['format' => 'other', 'version' => 1, 'alias' => 'x', 'nameSingular' => 'X', 'namePlural' => 'Xs', 'customFields' => []]));
    }

    public function testAnExistingCategoryOfTheFileIsUsed(): void
    {
        $this->useMock('categoryModel', 'categoryRepository');
        $category = new Category();
        $category->setAlias('school');
        $category->setTitle('Schools');
        $this->categoryRepository->expects($this->once())->method('findOneBy')->with(['alias' => 'school', 'bundle' => 'global'])->willReturn($category);
        $this->categoryModel->expects($this->never())->method('saveEntity');
        $definition             = $this->definition();
        $definition['category'] = ['alias' => 'school', 'title' => 'Ignored title', 'color' => '000000'];

        $result = $this->importer->import((string) json_encode($definition));

        $this->assertSame($category, $result->getCustomObject()->getCategory());
        $this->assertNull($result->getCreatedCategory());
    }

    public function testAMissingCategoryIsCreatedWithTheObject(): void
    {
        $this->useMock('categoryModel');
        $this->categoryRepository->method('findOneBy')->willReturn(null);
        $this->categoryModel->expects($this->once())->method('saveEntity')->with($this->callback(static function (Category $c): bool {
            return 'school' === $c->getAlias() && 'Schools' === $c->getTitle() && 'global' === $c->getBundle() && 'ff0000' === $c->getColor();
        }));
        $definition             = $this->definition();
        $definition['category'] = ['alias' => 'school', 'title' => 'Schools', 'color' => 'ff0000'];

        $result = $this->importer->import((string) json_encode($definition));

        $this->assertSame('Schools', $result->getCreatedCategory());
        $this->assertSame('school', $result->getCustomObject()->getCategory()->getAlias());
    }

    public function testTheCheckDoesNotCreateTheCategoryButSaysItWould(): void
    {
        $this->useMock('categoryModel');
        $this->categoryRepository->method('findOneBy')->willReturn(null);
        $this->categoryModel->expects($this->never())->method('saveEntity');
        $definition             = $this->definition();
        $definition['category'] = ['alias' => 'school', 'title' => 'Schools'];

        $result = $this->importer->import((string) json_encode($definition), false);

        $this->assertSame('Schools', $result->getCreatedCategory());
    }

    public function testACategoryWithoutAliasOrTitleIsRefused(): void
    {
        foreach ([['alias' => 'x'], ['title' => 'X'], 'text', ['alias' => ' ', 'title' => 'X'], ['alias' => 5, 'title' => 'X']] as $category) {
            $definition             = $this->definition();
            $definition['category'] = $category;

            $this->assertSame(['custom.object.import.error.category'], $this->errorsOf($definition));
        }
    }

    public function testNoCategoryInTheFileMeansNoCategory(): void
    {
        $this->useMock('categoryRepository');
        $this->categoryRepository->expects($this->never())->method('findOneBy');

        $this->assertNull($this->importer->import((string) json_encode($this->definition()))->getCustomObject()->getCategory());
    }

    public function testTheCategoryOfTheFileIsIgnoredForAnExistingObject(): void
    {
        $this->useMock('categoryRepository');
        $this->existingObject();
        $this->fieldRepository->method('isAliasUnique')->willReturn(false);
        $this->categoryRepository->expects($this->never())->method('findOneBy');
        $definition             = $this->definition();
        $definition['category'] = 'not even valid';

        $this->assertNull($this->importer->import((string) json_encode($definition))->getCreatedCategory());
    }

    public function testTheApplyIsOneTransaction(): void
    {
        $this->useMock('connection');
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');
        $this->connection->expects($this->never())->method('rollBack');

        $this->importer->import((string) json_encode($this->definition()));
    }

    public function testTheCheckOpensNoTransaction(): void
    {
        $this->useMock('connection');
        $this->connection->expects($this->never())->method('beginTransaction');

        $this->importer->import((string) json_encode($this->definition()), false);
    }

    public function testAFailureWhileSavingRollsBackAndIsAnErrorOfTheFile(): void
    {
        $this->useMock('connection');
        $this->model->method('save')->willThrowException(new \RuntimeException('column too long'));
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->assertSame(['custom.object.import.error.save {"%message%":"column too long"}'], $this->errorsOf($this->definition()));
    }

    public function testTheConstraintsOfTheEntitiesAreEnforcedBeforeAnythingIsSaved(): void
    {
        $this->useMock('model');
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList([new ConstraintViolation('too long', null, [], null, 'label', 'x')]));
        $importer = new CustomObjectDefinitionImporter(
            $this->model,
            $this->objectRepository,
            $this->fieldRepository,
            new CustomFieldFactory($this->typeProvider),
            $this->itemModel,
            $this->categoryModel,
            $this->permissionProvider,
            $this->security,
            $validator,
            $this->entityManager,
            $this->translator
        );
        $this->model->expects($this->never())->method('save');

        try {
            $importer->import((string) json_encode($this->definition()));
            $this->fail('The import should have been refused');
        } catch (DefinitionImportException $e) {
            // the object and each of its two fields are validated (the object's own constraints do not cascade to the fields)
            $this->assertSame(['course - label: too long', 'a - label: too long', 'b - label: too long'], $e->getErrors());
        }
    }

    public function testAnAliasThatTheModelWouldChangeIsRefused(): void
    {
        $definition          = $this->definition();
        $definition['alias'] = 'My Course';
        $definition['customFields'][0]['alias'] = 'Field_One';

        $errors = implode("\n", $this->errorsOf($definition));

        $this->assertStringContainsString('custom.object.import.error.alias_form {"%alias%":"My Course"}', $errors);
        $this->assertStringContainsString('custom.object.import.error.alias_form {"%alias%":"Field_One"}', $errors);
    }

    public function testARelationshipObjectNeedsItsMaster(): void
    {
        $definition         = $this->definition();
        $definition['type'] = CustomObject::TYPE_RELATIONSHIP;

        $this->assertSame(['custom.object.import.error.master_required'], $this->errorsOf($definition));
    }

    public function testANewCategoryNeedsThePermissionToCreateCategories(): void
    {
        $this->useMock('categoryModel');
        $security = $this->createMock(CorePermissions::class);
        $security->expects($this->once())->method('isGranted')->with('category:categories:create')->willReturn(false);
        $this->categoryRepository->method('findOneBy')->willReturn(null);
        $importer = new CustomObjectDefinitionImporter(
            $this->model,
            $this->objectRepository,
            $this->fieldRepository,
            new CustomFieldFactory($this->typeProvider),
            $this->itemModel,
            $this->categoryModel,
            $this->permissionProvider,
            $security,
            $this->validator,
            $this->entityManager,
            $this->translator
        );
        $this->categoryModel->expects($this->never())->method('saveEntity');
        $definition             = $this->definition();
        $definition['category'] = ['alias' => 'school', 'title' => 'Schools'];

        try {
            $importer->import((string) json_encode($definition));
            $this->fail('The import should have been refused');
        } catch (DefinitionImportException $e) {
            $this->assertSame(['custom.object.import.error.category_forbidden {"%alias%":"school"}'], $e->getErrors());
        }
    }
}
