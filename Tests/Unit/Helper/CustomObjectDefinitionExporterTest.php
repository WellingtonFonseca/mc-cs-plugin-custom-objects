<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Helper;

use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldOption;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\UndefinedTransformerException;
use MauticPlugin\CustomObjectsBundle\Helper\CustomObjectDefinitionExporter;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\CustomFieldTypeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class CustomObjectDefinitionExporterTest extends TestCase
{
    private function field(string $alias, int $order, string $type = 'text'): CustomField
    {
        $field = new CustomField();
        $field->setAlias($alias);
        $field->setLabel('Label '.$alias);
        $field->setType($type);
        $typeObject = $this->createStub(CustomFieldTypeInterface::class);
        $typeObject->method('createDefaultValueTransformer')->willThrowException(new UndefinedTransformerException());
        $typeObject->method('getSymfonyFormFieldType')->willReturn('select' === $type ? ChoiceType::class : TextType::class);
        $field->setTypeObject($typeObject);
        $field->setOrder($order);

        return $field;
    }

    private function object(): CustomObject
    {
        $object = new CustomObject();
        $object->setAlias('course');
        $object->setNameSingular('Curso');
        $object->setNamePlural('Cursos');
        $object->setDescription('Os cursos');
        $object->setLanguage('pt_BR');
        $object->setType(CustomObject::TYPE_MASTER);

        return $object;
    }

    public function testTheObjectKeysAndTheFormatMarker(): void
    {
        $array = (new CustomObjectDefinitionExporter())->toArray($this->object());

        $this->assertSame('custom-object-definition', $array['format']);
        $this->assertSame(1, $array['version']);
        $this->assertSame('course', $array['alias']);
        $this->assertSame('Curso', $array['nameSingular']);
        $this->assertSame('Cursos', $array['namePlural']);
        $this->assertSame('Os cursos', $array['description']);
        $this->assertSame('pt_BR', $array['language']);
        $this->assertSame(CustomObject::TYPE_MASTER, $array['type']);
        $this->assertNull($array['category']);
        $this->assertNull($array['masterObjectAlias']);
        $this->assertArrayNotHasKey('relationshipObjectAlias', $array);
        $this->assertArrayNotHasKey('id', $array);
    }

    public function testTheMasterOfARelationshipObjectIsNamedByAliasAndTheChildOfAMasterIsNotInTheFile(): void
    {
        $master = new CustomObject();
        $master->setAlias('school');
        $related = new CustomObject();
        $related->setAlias('student');
        $object = $this->object();
        $object->setType(CustomObject::TYPE_RELATIONSHIP);
        $object->setMasterObject($master);
        $object->setRelationshipObject($related);

        $array = (new CustomObjectDefinitionExporter())->toArray($object);

        $this->assertSame(CustomObject::TYPE_RELATIONSHIP, $array['type']);
        $this->assertSame('school', $array['masterObjectAlias']);
        // the child is linked by the plugin when it is saved; naming it here made a copy clash with the original on a unique index
        $this->assertArrayNotHasKey('relationshipObjectAlias', $array);
    }

    public function testFieldsComeInTheirOrderWithTheirSettingsAndNoIds(): void
    {
        $object = $this->object();
        $second = $this->field('second', 2);
        $second->setRequired(true);
        $second->setDefaultValue('x');
        $this->assertSame('x', $second->getStoredDefaultValue());
        $second->setShowInContactDetailList(true);
        $object->addCustomField($this->field('first', 1));
        $object->addCustomField($second);
        $object->addCustomField($this->field('zero', 0));

        $fields = (new CustomObjectDefinitionExporter())->toArray($object)['customFields'];

        $this->assertSame(['zero', 'first', 'second'], array_column($fields, 'alias'));
        $this->assertSame('Label second', $fields[2]['label']);
        $this->assertSame('text', $fields[2]['type']);
        $this->assertTrue($fields[2]['required']);
        $this->assertSame('x', $fields[2]['defaultValue']);
        $this->assertTrue($fields[2]['showInContactDetailList']);
        $this->assertFalse($fields[1]['required']);
        $this->assertArrayNotHasKey('id', $fields[0]);
        $this->assertSame([], $fields[0]['options']);
    }

    public function testTheOptionsOfAChoiceFieldComeInTheirOrder(): void
    {
        $object = $this->object();
        $field  = $this->field('level', 1, 'select');
        $advanced = new CustomFieldOption(['label' => 'Advanced', 'value' => 'adv']);
        $basic    = new CustomFieldOption(['label' => 'Basic', 'value' => 'bas']);
        $field->addOption($advanced);
        $field->addOption($basic);
        $advanced->setOrder(2);
        $basic->setOrder(1);
        $object->addCustomField($field);

        $options = (new CustomObjectDefinitionExporter())->toArray($object)['customFields'][0]['options'];

        $this->assertSame(
            [['label' => 'Basic', 'value' => 'bas', 'order' => 1], ['label' => 'Advanced', 'value' => 'adv', 'order' => 2]],
            $options
        );
    }

    public function testTheJsonIsPrettyAndKeepsAccents(): void
    {
        $json = (new CustomObjectDefinitionExporter())->toJson($this->object());

        $this->assertStringContainsString("\n    \"alias\": \"course\"", $json);
        $this->assertStringContainsString('"description": "Os cursos"', $json);
        $this->assertSame('custom-object-definition', json_decode($json, true)['format']);
    }

    public function testAFieldIsExportedWithEveryKeyAndValue(): void
    {
        $object = $this->object();
        $field  = $this->field('code', 4);
        $field->setRequired(true);
        $field->setDefaultValue('abc');
        $field->setIsPublished(false);
        $field->setIsUniqueIdentifier(true);
        $field->setShowInCustomObjectDetailList(true);
        $field->setShowInContactDetailList(false);
        $field->setParams(new \MauticPlugin\CustomObjectsBundle\Entity\CustomField\Params(['placeholder' => 'Type it']));
        $object->addCustomField($field);

        $exported = (new CustomObjectDefinitionExporter())->toArray($object)['customFields'][0];

        $this->assertSame([
            'alias'                        => 'code',
            'label'                        => 'Label code',
            'type'                         => 'text',
            'order'                        => 4,
            'required'                     => true,
            'defaultValue'                 => 'abc',
            'isPublished'                  => false,
            'isUniqueIdentifier'           => true,
            'showInCustomObjectDetailList' => true,
            'showInContactDetailList'      => false,
            'params'                       => ['placeholder' => 'Type it'],
            'options'                      => [],
        ], $exported);
    }

    public function testTheJsonKeepsAccentsAndSlashesAsTheyAre(): void
    {
        $object = $this->object();
        $object->setDescription('Ação / curso');

        $json = (new CustomObjectDefinitionExporter())->toJson($object);

        $this->assertStringContainsString('"description": "Ação / curso"', $json);
    }

    public function testTheCategoryIsExportedByAliasTitleAndColor(): void
    {
        $category = new \Mautic\CategoryBundle\Entity\Category();
        $category->setAlias('school');
        $category->setTitle('Schools');
        $category->setColor('ff0000');
        $object = $this->object();
        $object->setCategory($category);

        $this->assertSame(
            ['alias' => 'school', 'title' => 'Schools', 'color' => 'ff0000'],
            (new CustomObjectDefinitionExporter())->toArray($object)['category']
        );
    }
}
