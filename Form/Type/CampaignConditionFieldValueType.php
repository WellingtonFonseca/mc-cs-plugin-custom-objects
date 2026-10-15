<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Form\Type;

use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The campaign condition "<object> field value": one criterion in its own field / operator / value (what existing
 * conditions already store) plus `additionalCriteria`, more of them. When there are several, ONE item must satisfy all.
 */
class CampaignConditionFieldValueType extends AbstractType
{
    public function __construct(
        private CustomObjectModel $customObjectModel,
        private CampaignConditionCriterionType $criterionType
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $customObject = $this->customObjectModel->fetchEntity($options['customObjectId']);

        $this->criterionType->addCriterionFields($builder, $customObject, $options['data']['field'] ?? null);

        // The first value is required, as before; the additional rows may leave an empty/not-empty operator valueless.
        $builder->add('value', TextType::class, [
            'required' => true,
            'label'    => 'custom.item.field.value',
            'attr'     => ['class' => 'form-control co-criterion-value'],
        ]);

        $builder->add('additionalCriteria', CollectionType::class, [
            'entry_type'     => CampaignConditionCriterionType::class,
            'entry_options'  => ['customObjectId' => $options['customObjectId']],
            'allow_add'      => true,
            'allow_delete'   => true,
            'prototype'      => true,
            'required'       => false,
            'label'          => false,
        ]);

        $builder->add('customObjectId', HiddenType::class, ['data' => $options['customObjectId']]);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['customObjectId']);
    }
}
