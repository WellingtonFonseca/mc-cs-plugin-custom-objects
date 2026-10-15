<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Form\Type;

use Mautic\LeadBundle\Provider\FilterOperatorProviderInterface;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\IntType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\TextType as CustomTextType;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Form\Type\CampaignConditionCriterionType;
use MauticPlugin\CustomObjectsBundle\Form\Type\CampaignConditionFieldValueType;
use MauticPlugin\CustomObjectsBundle\Helper\MergedItemCriteriaMatcher;
use MauticPlugin\CustomObjectsBundle\Model\CustomFieldModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomFieldTypeProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The form of the campaign condition "<object> field value", built with real Symfony forms: the item name offered as a
 * field, the operators of each field, the one criterion of its own and the `additionalCriteria` collection.
 */
final class CampaignConditionFieldValueTypeTest extends TestCase
{
    private FormFactoryInterface $formFactory;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $operatorProvider = $this->createMock(FilterOperatorProviderInterface::class);
        $operatorProvider->method('getAllOperators')->willReturn(['=' => ['label' => 'a'], '!=' => ['label' => 'b'], 'empty' => ['label' => 'c']]);

        $customObject = new CustomObject();
        $customObject->setId(42);
        $customField = new CustomField();
        $customField->setId(42);
        $customField->setLabel('Cheese');
        $customField->setType('int');
        $customField->setTypeObject(new IntType($translator, $operatorProvider));

        $objectModel = $this->createMock(CustomObjectModel::class);
        $objectModel->method('fetchEntity')->with(42)->willReturn($customObject);
        $fieldModel = $this->createMock(CustomFieldModel::class);
        $fieldModel->method('fetchCustomFieldsForObject')->with($customObject)->willReturn([42 => $customField]);
        $typeProvider = $this->createMock(CustomFieldTypeProvider::class);
        $typeProvider->method('getType')->with('text')->willReturn(new CustomTextType($translator, $operatorProvider));

        $criterion = new CampaignConditionCriterionType($fieldModel, $objectModel, $typeProvider, $translator);
        $condition = new CampaignConditionFieldValueType($objectModel, $criterion);

        $this->formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new PreloadedExtension([CampaignConditionCriterionType::class => $criterion, CampaignConditionFieldValueType::class => $condition], []))
            ->getFormFactory();
    }

    public function testTheConditionHasItsOwnCriterionAndTheAdditionalOnes(): void
    {
        $form = $this->formFactory->create(CampaignConditionFieldValueType::class, null, ['customObjectId' => 42]);

        foreach (['field', 'operator', 'value', 'additionalCriteria', 'customObjectId'] as $child) {
            $this->assertTrue($form->has($child), $child);
        }
        $this->assertSame(CollectionType::class, $form->get('additionalCriteria')->getConfig()->getType()->getInnerType()::class);
        $this->assertTrue($form->get('additionalCriteria')->getConfig()->getOption('allow_add'));
        $this->assertTrue($form->get('additionalCriteria')->getConfig()->getOption('allow_delete'));
    }

    public function testTheItemNameIsOfferedFirstAsAFieldWithTextOperators(): void
    {
        $form    = $this->formFactory->create(CampaignConditionFieldValueType::class, null, ['customObjectId' => 42]);
        $choices = $form->get('field')->getConfig()->getOption('choices');

        $this->assertSame([MergedItemCriteriaMatcher::ITEM_NAME, 42], array_values($choices));
        $this->assertSame('custom.item.name.label', array_key_first($choices));

        $attr = $form->get('field')->getConfig()->getOption('choice_attr');
        $this->assertSame('text', $attr['custom.item.name.label']['data-field-type']);
        $this->assertSame('int', $attr['Cheese']['data-field-type']);
    }

    public function testASavedConditionWithOneCriterionStillFits(): void
    {
        $form = $this->formFactory->create(CampaignConditionFieldValueType::class, null, ['customObjectId' => 42]);
        $form->submit(['field' => '42', 'operator' => '=', 'value' => '7', 'customObjectId' => '42']);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame(42, $form->get('field')->getData());
        $this->assertSame('7', $form->get('value')->getData());
        $this->assertSame([], $form->get('additionalCriteria')->getData() ?? []);
    }

    public function testSeveralCriteriaAreSubmittedAsAList(): void
    {
        $form = $this->formFactory->create(CampaignConditionFieldValueType::class, null, ['customObjectId' => 42]);
        $form->submit([
            'field'              => 'name',
            'operator'           => '=',
            'value'              => 'Disciplina X',
            'customObjectId'     => '42',
            'additionalCriteria' => [
                ['field' => '42', 'operator' => '>=', 'value' => '-35 days'],
                ['field' => '42', 'operator' => '<=', 'value' => '+10 days'],
            ],
        ]);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame('name', $form->get('field')->getData());
        $this->assertSame(
            [['field' => 42, 'operator' => '>=', 'value' => '-35 days'], ['field' => 42, 'operator' => '<=', 'value' => '+10 days']],
            $form->get('additionalCriteria')->getData()
        );
    }

    public function testTheRowPrototypeExistsForTheBrowserToAddRows(): void
    {
        $view = $this->formFactory->create(CampaignConditionFieldValueType::class, null, ['customObjectId' => 42])->createView();

        $this->assertArrayHasKey('prototype', $view['additionalCriteria']->vars);
        $this->assertArrayHasKey('field', $view['additionalCriteria']->vars['prototype']);
        $this->assertArrayHasKey('operator', $view['additionalCriteria']->vars['prototype']);
        $this->assertArrayHasKey('value', $view['additionalCriteria']->vars['prototype']);
    }
}
