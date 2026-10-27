<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Helper;

use MauticPlugin\CustomObjectsBundle\DTO\CustomObjectDefinitionImportResult;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField\Params;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldFactory;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldOption;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\DefinitionImportException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Repository\CustomFieldRepository;
use MauticPlugin\CustomObjectsBundle\Repository\CustomObjectRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Imports the JSON of CustomObjectDefinitionExporter (the Clone tab of another instance). An alias that is new creates the object, with all
 * its fields and options, no items. An alias that exists only RECEIVES the fields of the file that the object does not have yet (by the
 * alias of the field); what the object already has is never changed, and the data of the object in the file (names, type, related objects)
 * is ignored. Everything wrong with the file is collected first and thrown together (DefinitionImportException), so one attempt shows all of
 * it, and nothing is saved unless there is no error. The `order` of the file only gives the sequence of the new fields: in a new object they
 * are numbered 0, 1, 2...; in an existing one they continue after the last number it uses.
 */
class CustomObjectDefinitionImporter
{
    public function __construct(
        private CustomObjectModel $customObjectModel,
        private CustomObjectRepository $customObjectRepository,
        private CustomFieldRepository $customFieldRepository,
        private CustomFieldFactory $customFieldFactory,
        private CustomObjectPermissionProvider $permissionProvider,
        private TranslatorInterface $translator
    ) {
    }

    /**
     * @throws DefinitionImportException
     */
    public function import(string $json): CustomObjectDefinitionImportResult
    {
        $definition = json_decode($json, true);
        if (!is_array($definition)) {
            throw new DefinitionImportException([$this->t('custom.object.import.error.json')]);
        }

        $existing = $this->findExisting($definition);
        $errors   = $this->validateObject($definition);
        if ($existing instanceof CustomObject) {
            $errors = array_merge($errors, $this->validateExisting($existing));
        }

        $fields = [];
        if (isset($definition['customFields']) && is_array($definition['customFields'])) {
            // a list: the position of a field in a message is its place in the file, whatever the keys of the JSON
            $fields = array_values($definition['customFields']);
            $own    = $existing instanceof CustomObject ? $this->aliasesOf($existing) : [];
            $errors = array_merge($errors, $this->validateFields($fields, $own));
            usort($fields, fn ($a, $b): int => $this->orderOf($a) <=> $this->orderOf($b));
        }

        $master       = $existing instanceof CustomObject ? null : $this->findRelated($definition['masterObjectAlias'] ?? null, $errors);
        $relationship = $existing instanceof CustomObject ? null : $this->findRelated($definition['relationshipObjectAlias'] ?? null, $errors);

        if ([] !== $errors) {
            throw new DefinitionImportException($errors);
        }

        if ($existing instanceof CustomObject) {
            return $this->addMissingFields($existing, $fields);
        }

        $customObject = new CustomObject();
        $customObject->setAlias($definition['alias']);
        $customObject->setNameSingular($definition['nameSingular']);
        $customObject->setNamePlural($definition['namePlural']);
        $customObject->setDescription($definition['description'] ?? null);
        $customObject->setLanguage($definition['language'] ?? null);
        $customObject->setType((int) ($definition['type'] ?? CustomObject::TYPE_MASTER));
        $customObject->setMasterObject($master);
        $customObject->setRelationshipObject($relationship);

        foreach ($fields as $order => $rawField) {
            $customObject->addCustomField($this->buildField($customObject, $rawField, $order));
        }

        $this->customObjectModel->save($customObject);

        return new CustomObjectDefinitionImportResult($customObject, true, array_column($fields, 'alias'), []);
    }

    /**
     * @param mixed[] $fields the fields of the file, in their sequence
     */
    private function addMissingFields(CustomObject $customObject, array $fields): CustomObjectDefinitionImportResult
    {
        $existingFields = [];
        $nextOrder      = 0;
        foreach ($customObject->getCustomFields() as $field) {
            $existingFields[$field->getAlias()] = $field;
            $nextOrder                          = max($nextOrder, (int) $field->getOrder() + 1);
        }

        $added   = [];
        $skipped = [];
        foreach ($fields as $rawField) {
            $alias = $rawField['alias'];
            if (isset($existingFields[$alias])) {
                $skipped[] = $this->skippedMessage($existingFields[$alias], $rawField);

                continue;
            }

            $customObject->addCustomField($this->buildField($customObject, $rawField, $nextOrder++));
            $added[] = $alias;
        }

        if ([] !== $added) {
            $this->customObjectModel->save($customObject);
        }

        return new CustomObjectDefinitionImportResult($customObject, false, $added, $skipped);
    }

    /**
     * A field the object already has is left as it is; a different type is said, since it means the two instances have drifted apart.
     *
     * @param mixed[] $rawField
     */
    private function skippedMessage(CustomField $existing, array $rawField): string
    {
        if ($existing->getType() === $rawField['type']) {
            return (string) $rawField['alias'];
        }

        return $this->t('custom.object.import.skipped_type', [
            '%alias%'    => $rawField['alias'],
            '%existing%' => (string) $existing->getType(),
            '%file%'     => $rawField['type'],
        ]);
    }

    /**
     * @param mixed[] $definition
     */
    private function findExisting(array $definition): ?CustomObject
    {
        $alias = $definition['alias'] ?? null;
        if (!is_string($alias) || '' === $alias) {
            return null;
        }

        $existing = $this->customObjectRepository->findOneBy(['alias' => $alias]);
        if (!$existing instanceof CustomObject) {
            return null;
        }

        $existing->createFieldsSnapshot();

        return $existing;
    }

    /**
     * Fields can be added only by who may edit the object, and not while someone else has it open.
     *
     * @return string[]
     */
    private function validateExisting(CustomObject $existing): array
    {
        try {
            $this->permissionProvider->canEdit($existing);
        } catch (ForbiddenException) {
            return [$this->t('custom.object.import.error.forbidden', ['%alias%' => (string) $existing->getAlias()])];
        }

        if ($this->customObjectModel->isLocked($existing)) {
            return [$this->t('custom.object.import.error.locked', ['%alias%' => (string) $existing->getAlias()])];
        }

        return [];
    }

    /**
     * @return string[]
     */
    private function aliasesOf(CustomObject $customObject): array
    {
        return array_map(static fn (CustomField $field): string => (string) $field->getAlias(), $customObject->getCustomFields()->toArray());
    }

    /**
     * @param mixed[] $definition
     *
     * @return string[]
     */
    private function validateObject(array $definition): array
    {
        $errors = [];
        if (CustomObjectDefinitionExporter::FORMAT !== ($definition['format'] ?? null)) {
            $errors[] = $this->t('custom.object.import.error.format');
        } elseif (CustomObjectDefinitionExporter::VERSION !== ($definition['version'] ?? null)) {
            $errors[] = $this->t('custom.object.import.error.version', ['%version%' => (string) json_encode($definition['version'] ?? null)]);
        }

        foreach (['alias', 'nameSingular', 'namePlural'] as $key) {
            if (!isset($definition[$key]) || !is_string($definition[$key]) || '' === trim($definition[$key])) {
                $errors[] = $this->t('custom.object.import.error.required', ['%key%' => $key]);
            }
        }

        if (isset($definition['type']) && !in_array($definition['type'], [CustomObject::TYPE_MASTER, CustomObject::TYPE_RELATIONSHIP], true)) {
            $errors[] = $this->t('custom.object.import.error.type');
        }

        if (!isset($definition['customFields']) || !is_array($definition['customFields'])) {
            $errors[] = $this->t('custom.object.import.error.fields');
        }

        return $errors;
    }

    /**
     * @param mixed[]  $fields
     * @param string[] $ownAliases the aliases of the fields the object already has (taken, but by itself: they are skipped, not an error)
     *
     * @return string[]
     */
    private function validateFields(array $fields, array $ownAliases): array
    {
        $errors = [];
        $seen   = [];
        foreach ($fields as $index => $field) {
            $position = (string) ($index + 1);
            if (!is_array($field)) {
                $errors[] = $this->t('custom.object.import.error.field_shape', ['%position%' => $position]);

                continue;
            }

            foreach (['alias', 'label', 'type'] as $key) {
                if (!isset($field[$key]) || !is_string($field[$key]) || '' === trim($field[$key])) {
                    $errors[] = $this->t('custom.object.import.error.field_required', ['%position%' => $position, '%key%' => $key]);
                }
            }

            if (!isset($field['alias']) || !is_string($field['alias']) || '' === $field['alias']) {
                continue;
            }

            if (in_array($field['alias'], $seen, true)) {
                $errors[] = $this->t('custom.object.import.error.field_repeated', ['%alias%' => $field['alias']]);
            }
            $seen[] = $field['alias'];

            // field aliases are unique in the whole instance (CustomFieldModel renames a taken one by adding a number, which a copy must not)
            if (!in_array($field['alias'], $ownAliases, true) && $this->customFieldRepository->isAliasUnique($field['alias'])) {
                $errors[] = $this->t('custom.object.import.error.field_exists', ['%alias%' => $field['alias']]);
            }

            if (isset($field['type']) && is_string($field['type']) && !$this->typeExists($field['type'])) {
                $errors[] = $this->t('custom.object.import.error.field_type', ['%alias%' => $field['alias'], '%type%' => $field['type']]);
            }
        }

        return $errors;
    }

    /**
     * @param mixed $item
     */
    private function orderOf($item): int
    {
        return is_array($item) ? (int) ($item['order'] ?? 0) : 0;
    }

    private function typeExists(string $type): bool
    {
        try {
            $this->customFieldFactory->create($type, new CustomObject());
        } catch (NotFoundException) {
            return false;
        }

        return true;
    }

    /**
     * @param mixed    $alias
     * @param string[] $errors
     */
    private function findRelated($alias, array &$errors): ?CustomObject
    {
        if (null === $alias || '' === $alias) {
            return null;
        }

        $related = is_string($alias) ? $this->customObjectRepository->findOneBy(['alias' => $alias]) : null;
        if (!$related instanceof CustomObject) {
            $errors[] = $this->t('custom.object.import.error.related', ['%alias%' => is_string($alias) ? $alias : (string) json_encode($alias)]);

            return null;
        }

        return $related;
    }

    /**
     * @param mixed[] $raw
     */
    private function buildField(CustomObject $customObject, array $raw, int $order): CustomField
    {
        $field = $this->customFieldFactory->create($raw['type'], $customObject);
        $field->setAlias($raw['alias']);
        $field->setLabel($raw['label']);
        $field->setOrder($order);
        $field->setRequired((bool) ($raw['required'] ?? false));
        $field->setIsPublished((bool) ($raw['isPublished'] ?? true));
        $field->setIsUniqueIdentifier((bool) ($raw['isUniqueIdentifier'] ?? false));
        $field->setShowInCustomObjectDetailList((bool) ($raw['showInCustomObjectDetailList'] ?? false));
        $field->setShowInContactDetailList((bool) ($raw['showInContactDetailList'] ?? false));
        $field->setStoredDefaultValue(isset($raw['defaultValue']) ? (string) $raw['defaultValue'] : null);
        $field->setParams(new Params(is_array($raw['params'] ?? null) ? $raw['params'] : []));

        $options = is_array($raw['options'] ?? null) ? $raw['options'] : [];
        usort($options, fn ($a, $b): int => $this->orderOf($a) <=> $this->orderOf($b));
        foreach ($options as $option) {
            if (is_array($option) && isset($option['label'], $option['value'])) {
                $field->addOption(new CustomFieldOption(['label' => (string) $option['label'], 'value' => (string) $option['value']]));
            }
        }

        return $field;
    }

    /**
     * @param string[] $params
     */
    private function t(string $id, array $params = []): string
    {
        return $this->translator->trans($id, $params);
    }
}
