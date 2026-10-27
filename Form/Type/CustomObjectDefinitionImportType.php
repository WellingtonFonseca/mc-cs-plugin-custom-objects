<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The form of the import of a Custom Object definition: the JSON of the Clone tab pasted in a text area, checked first and then confirmed.
 */
class CustomObjectDefinitionImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // two steps: the JSON is first CHECKED (nothing is saved, the page shows what would be done), then confirmed with the same text, which
        // is then read only so that what was checked is what is imported
        $builder->add('definition', TextareaType::class, [
            'label'    => 'custom.object.import.label',
            'required' => false,
            'attr'     => array_filter([
                'class'    => 'form-control',
                'rows'     => 24,
                'style'    => 'font-family:monospace;white-space:pre',
                'tooltip'  => 'custom.object.import.help',
                'readonly' => $options['confirming'] ? 'readonly' : null,
            ]),
        ]);

        $builder->add('confirmed', HiddenType::class, ['data' => $options['confirming'] ? '1' : '0', 'mapped' => false]);

        $builder->add('buttons', FormButtonsType::class, [
            'apply_text'     => false,
            'save_text'      => $options['confirming'] ? 'custom.object.import.confirm' : 'custom.object.import.check',
            'save_icon'      => $options['confirming'] ? 'ri-upload-2-line' : 'ri-search-line',
            'cancel_onclick' => $options['cancel_onclick'],
        ]);

        $builder->setAction($options['action']);
    }

    public function configureOptions(\Symfony\Component\OptionsResolver\OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['cancel_onclick' => '', 'action' => '', 'confirming' => false]);
    }

    public function getBlockPrefix(): string
    {
        return 'custom_object_definition_import';
    }
}
