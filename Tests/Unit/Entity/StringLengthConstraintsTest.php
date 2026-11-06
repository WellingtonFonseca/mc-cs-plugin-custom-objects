<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Entity;

use Mautic\LeadBundle\Provider\FilterOperatorProviderInterface;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\TextType;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldOption;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The string columns of these tables are VARCHAR(191) in a schema built from the entities (the old migrations of the original
 * plugin declare some as 255). The Length constraint of each one must say 191: with 255 a value of 192 to 255 characters passed
 * the validation and failed in the database (a 500 on the API and on the form). The number here is written out on purpose: it is
 * the check of StringColumn::LENGTH. CustomItemTest checks the name of an item the same way.
 */
final class StringLengthConstraintsTest extends TestCase
{
    private const COLUMN_LENGTH = 191;

    #[DataProvider('stringPropertyProvider')]
    public function testValueOfTheColumnLengthIsValid(string $class, string $property, string $setter): void
    {
        $this->assertCount(0, $this->violations($class, $property, $setter, self::COLUMN_LENGTH));
    }

    #[DataProvider('stringPropertyProvider')]
    public function testValueOneCharacterOverTheColumnIsRefusedAndTheMessageSaysTheLimit(string $class, string $property, string $setter): void
    {
        $violations = $this->violations($class, $property, $setter, self::COLUMN_LENGTH + 1);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString((string) self::COLUMN_LENGTH, (string) $violations->get(0)->getMessage());
    }

    /**
     * @return array<string, array{class-string, string, string}>
     */
    public static function stringPropertyProvider(): array
    {
        return [
            'object alias'          => [CustomObject::class, 'alias', 'setAlias'],
            'object name singular'  => [CustomObject::class, 'nameSingular', 'setNameSingular'],
            'object name plural'    => [CustomObject::class, 'namePlural', 'setNamePlural'],
            'object description'    => [CustomObject::class, 'description', 'setDescription'],
            'field label'           => [CustomField::class, 'label', 'setLabel'],
            'field alias'           => [CustomField::class, 'alias', 'setAlias'],
            'field type'            => [CustomField::class, 'type', 'setType'],
            'field default value'   => [CustomField::class, 'defaultValue', 'setDefaultValue'],
            'option label'          => [CustomFieldOption::class, 'label', 'setLabel'],
            'option value'          => [CustomFieldOption::class, 'value', 'setValue'],
            'item name (reference)' => [CustomItem::class, 'name', 'setName'],
        ];
    }

    /**
     * @param class-string $class
     */
    private function violations(string $class, string $property, string $setter, int $length): ConstraintViolationListInterface
    {
        $entity = CustomItem::class === $class ? new CustomItem(new CustomObject()) : new $class();

        if ($entity instanceof CustomField) {
            // setDefaultValue() asks the type of the field for its transformer
            $entity->setTypeObject(new TextType($this->createMock(TranslatorInterface::class), $this->createMock(FilterOperatorProviderInterface::class)));
        }

        $entity->{$setter}(str_repeat('a', $length));

        $validator = Validation::createValidatorBuilder()->addMethodMapping('loadValidatorMetadata')->getValidator();

        return $validator->validateProperty($entity, $property);
    }
}
