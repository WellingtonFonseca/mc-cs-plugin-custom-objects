<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The form of the import of a Custom Object definition: the JSON of the Clone tab pasted in a text area.
 */
class CustomObjectDefinitionImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('definition', TextareaType::class, [
            'label'    => 'custom.object.import.label',
            'required' => false,
            'attr'     => [
                'class' => 'form-control',
                'rows'  => 24,
                'style' => 'font-family:monospace;white-space:pre',
                'tooltip' => 'custom.object.import.help',
            ],
        ]);

        $builder->add('buttons', FormButtonsType::class, [
            'apply_text'     => false,
            'save_text'      => 'custom.object.import.submit',
            'save_icon'      => 'ri-upload-2-line',
            'cancel_onclick' => $options['cancel_onclick'],
        ]);

        $builder->setAction($options['action']);
    }

    public function configureOptions(\Symfony\Component\OptionsResolver\OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['cancel_onclick' => '', 'action' => '']);
    }

    public function getBlockPrefix(): string
    {
        return 'custom_object_definition_import';
    }
}
