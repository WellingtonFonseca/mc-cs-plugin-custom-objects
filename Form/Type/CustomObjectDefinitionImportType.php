<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The form of the import of a Custom Object definition: the JSON of the Clone tab pasted in a text area, checked first and then confirmed.
 */
class CustomObjectDefinitionImportType extends AbstractType
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

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

        // while checking: Conferir. Once checked: Confirmar importação, and a second way out, Limpar, placed before it (Cancelar, which leaves for the list, is last):
        // Limpar empties the text, makes it editable again and brings Conferir back, so another JSON can be pasted. A check that finds
        // nothing to do has the confirm button hidden, and Limpar is the way to try another file.
        $confirm = $options['confirming'] && $options['can_confirm'];
        $buttons = [
            'apply_text'     => false,
            'save_text'      => $confirm ? 'custom.object.import.confirm' : 'custom.object.import.check',
            'save_icon'      => $confirm ? 'ri-upload-2-line' : 'ri-search-line',
            'save_class'     => 'btn btn-primary btn-save'.($options['confirming'] && !$options['can_confirm'] ? ' hide' : ''),
            'cancel_onclick' => $options['cancel_onclick'],
        ];
        if ($options['confirming']) {
            $buttons['pre_extra_buttons'] = [[
                'name'  => 'reset',
                'label' => 'custom.object.import.reset',
                'attr'  => ['class' => 'btn btn-secondary btn-reset btn-dnd btn-nospin', 'icon' => 'ri-eraser-line', 'onclick' => $this->resetScript()],
            ]];
        }
        $builder->add('buttons', FormButtonsType::class, $buttons);

        $builder->setAction($options['action']);
    }

    /**
     * The click of Limpar (core copies the buttons of the form into the page header and the copy clicks the original, so this runs from
     * either; btn-dnd and btn-nospin keep core from disabling the other buttons and spinning the icon, as it does for a submit): the text is emptied and editable, the check is gone, the form goes back to the check step and its button says Conferir.
     */
    private function resetScript(): string
    {
        $label = json_encode($this->translator->trans('custom.object.import.check'), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

        return "var f = mQuery('form[name=custom_object_definition_import]');"
            ." f.find('textarea').val('').prop('readonly', false).removeAttr('readonly').focus();"
            ." f.find('input[name\$=\"[confirmed]\"]').val('0');"
            ." mQuery('.custom-object-import-check, .custom-object-import-help').hide();"
            ." mQuery('.btn-save').removeClass('hide').html(".$label." + '<i class=\"ri-search-line\"></i>');"
            ." mQuery('.btn-reset').hide();"
            .' return false;';
    }

    public function configureOptions(\Symfony\Component\OptionsResolver\OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['cancel_onclick' => '', 'action' => '', 'confirming' => false, 'can_confirm' => true]);
    }

    public function getBlockPrefix(): string
    {
        return 'custom_object_definition_import';
    }
}
