<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Helper;

use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldOption;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;

/**
 * The definition of a Custom Object (the object and its fields, no items) as an array and as pretty JSON, to be copied to another Mautic
 * instance. Ids, uuids and dates are left out: they belong to this database. A related object (the master and the relationship of a
 * relationship object) is named by its alias, which is what exists on the other instance. The `format` and `version` keys let an importer
 * refuse a file it does not know.
 */
class CustomObjectDefinitionExporter
{
    public const FORMAT  = 'custom-object-definition';
    public const VERSION = 1;

    /**
     * @return mixed[]
     */
    public function toArray(CustomObject $customObject): array
    {
        $fields = $customObject->getCustomFields()->toArray();
        usort($fields, static fn (CustomField $a, CustomField $b): int => $a->getOrder() <=> $b->getOrder());

        return [
            'format'                => self::FORMAT,
            'version'               => self::VERSION,
            'alias'                 => $customObject->getAlias(),
            'nameSingular'          => $customObject->getNameSingular(),
            'namePlural'            => $customObject->getNamePlural(),
            'description'           => $customObject->getDescription(),
            'language'              => $customObject->getLanguage(),
            'type'                  => $customObject->getType() ?? CustomObject::TYPE_MASTER,
            'masterObjectAlias'     => $customObject->getMasterObject() ? $customObject->getMasterObject()->getAlias() : null,
            'relationshipObjectAlias' => $customObject->getRelationshipObject() ? $customObject->getRelationshipObject()->getAlias() : null,
            'customFields'          => array_map([$this, 'fieldToArray'], $fields),
        ];
    }

    public function toJson(CustomObject $customObject): string
    {
        return (string) json_encode($this->toArray($customObject), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return mixed[]
     */
    private function fieldToArray(CustomField $field): array
    {
        $options = $field->getOptions()->toArray();
        usort($options, static fn (CustomFieldOption $a, CustomFieldOption $b): int => $a->getOrder() <=> $b->getOrder());

        return [
            'alias'                        => $field->getAlias(),
            'label'                        => $field->getLabel(),
            'type'                         => $field->getType(),
            'order'                        => $field->getOrder(),
            'required'                     => $field->isRequired(),
            'defaultValue'                 => $field->getStoredDefaultValue(),
            'isPublished'                  => (bool) $field->getIsPublished(),
            'isUniqueIdentifier'           => $field->getIsUniqueIdentifier(),
            'showInCustomObjectDetailList' => $field->isShowInCustomObjectDetailList(),
            'showInContactDetailList'      => $field->isShowInContactDetailList(),
            'params'                       => $field->getParams()->__toArray(),
            'options'                      => array_map(
                static fn (CustomFieldOption $option): array => [
                    'label' => $option->getLabel(),
                    'value' => $option->getValue(),
                    'order' => $option->getOrder(),
                ],
                $options
            ),
        ];
    }
}
