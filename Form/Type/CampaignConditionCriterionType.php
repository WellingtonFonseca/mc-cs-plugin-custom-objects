<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Form\Type;

use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\EventListener\SegmentTextInOperatorSubscriber;
use MauticPlugin\CustomObjectsBundle\Helper\MergedItemCriteriaMatcher;
use MauticPlugin\CustomObjectsBundle\Model\CustomFieldModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomFieldTypeProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One criterion (field, operator, value) of the campaign condition "<object> field value". The condition holds one of
 * them in its own fields and any number more as `additionalCriteria`; the item must satisfy all of them on the SAME item.
 * The field is a Custom Field id, or `name` for the name of the item.
 */
class CampaignConditionCriterionType extends AbstractType
{
    public function __construct(
        private CustomFieldModel $customFieldModel,
        private CustomObjectModel $customObjectModel,
        private CustomFieldTypeProvider $fieldTypeProvider,
        private TranslatorInterface $translator
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addCriterionFields($builder, $this->customObjectModel->fetchEntity($options['customObjectId']), $options['data']['field'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    private function operatorsOf(CustomField $field): array
    {
        return $this->withIncludingExcluding($field->getTypeObject()->getOperatorOptions(), $field->getType());
    }

    /**
     * "Including" / "excluding" (in / !in) after "not like" on the text fields and the item name, as the segment filter
     * offers them (SegmentTextInOperatorSubscriber): the value A|B|C holds several values in ONE criterion.
     *
     * @param array<string, string> $operators
     *
     * @return array<string, string>
     */
    private function withIncludingExcluding(array $operators, string $type): array
    {
        if (!in_array($type, SegmentTextInOperatorSubscriber::TEXT_TYPES, true) || isset($operators['in'])) {
            return $operators;
        }

        $result = [];
        foreach ($operators as $operator => $label) {
            $result[$operator] = $label;
            if ('!like' === $operator) {
                $result['in']  = $this->translator->trans('mautic.lead.list.form.operator.in');
                $result['!in'] = $this->translator->trans('mautic.lead.list.form.operator.notin');
            }
        }

        return $result;
    }

    /**
     * The field / operator / value controls, shared with the condition's own first criterion.
     *
     * @param int|string|null $selected the field already chosen (a Custom Field id or "name")
     */
    public function addCriterionFields(FormBuilderInterface $builder, CustomObject $customObject, $selected = null): void
    {
        $fields     = $this->customFieldModel->fetchCustomFieldsForObject($customObject);
        $nameOps    = $this->withIncludingExcluding($this->fieldTypeProvider->getType('text')->getOperatorOptions(), 'text');
        $choices    = [$this->translator->trans('custom.item.criterion.name') => MergedItemCriteriaMatcher::ITEM_NAME];
        $optionAttr = [
            $this->translator->trans('custom.item.criterion.name') => [
                'data-operators'  => json_encode($nameOps),
                'data-options'    => json_encode([]),
                'data-field-type' => 'text',
            ],
        ];

        foreach ($fields as $field) {
            $choices[$field->getLabel()]    = $field->getId();
            $optionAttr[$field->getLabel()] = [
                'data-operators'  => json_encode($this->operatorsOf($field)),
                'data-options'    => json_encode($field->getChoices()),
                'data-field-type' => $field->getType(),
            ];
        }

        $builder->add('field', ChoiceType::class, [
            'required'    => true,
            'label'       => 'custom.item.field',
            'choices'     => $choices,
            'attr'        => ['class' => 'form-control co-criterion-field'],
            'choice_attr' => $optionAttr,
        ]);

        if (null !== $selected && isset($fields[$selected])) {
            $operators = $this->operatorsOf($fields[$selected]);
        } elseif (MergedItemCriteriaMatcher::ITEM_NAME === $selected || [] === $fields) {
            $operators = $nameOps;
        } else {
            $operators = $this->operatorsOf(array_values($fields)[0]);
        }

        $builder->add('operator', ChoiceType::class, [
            'required' => true,
            'label'    => 'custom.item.operator',
            'choices'  => array_flip($operators),
            'attr'     => ['class' => 'form-control co-criterion-operator'],
        ]);

        // Each field has its own operators: the list is replaced by the browser, so the choice is not validated against it.
        $builder->get('operator')->resetViewTransformers();

        $builder->add('value', TextType::class, [
            'required' => false,
            'label'    => 'custom.item.field.value',
            'attr'     => ['class' => 'form-control co-criterion-value'],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['customObjectId']);
    }
}
