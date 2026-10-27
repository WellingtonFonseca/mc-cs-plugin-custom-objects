<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Helper;

use Mautic\CategoryBundle\Entity\Category;
use Mautic\CategoryBundle\Model\CategoryModel;
use MauticPlugin\CustomObjectsBundle\DTO\CustomObjectDefinitionImportResult;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField\Params;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldFactory;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldOption;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\DefinitionImportException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
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
 * it, and nothing is saved unless there is no error. With $apply false nothing is saved either way: it answers what an import WOULD do (the
 * check shown before the person confirms). The `order` of the file only gives the sequence of the new fields: in a new object they
 * are numbered 0, 1, 2...; in an existing one they continue after the last number it uses.
 */
class CustomObjectDefinitionImporter
{
    private const CATEGORY_BUNDLE = 'global';

    public function __construct(
        private CustomObjectModel $customObjectModel,
        private CustomObjectRepository $customObjectRepository,
        private CustomFieldRepository $customFieldRepository,
        private CustomFieldFactory $customFieldFactory,
        private CustomItemModel $customItemModel,
        private CategoryModel $categoryModel,
        private CustomObjectPermissionProvider $permissionProvider,
        private TranslatorInterface $translator
    ) {
    }

    /**
     * @throws DefinitionImportException
     */
    public function import(string $json, bool $apply = true): CustomObjectDefinitionImportResult
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

        $newCategory  = false;
        $category     = $existing instanceof CustomObject ? null : $this->findCategory($definition['category'] ?? null, $errors, $newCategory);
        $master       = $existing instanceof CustomObject ? null : $this->findRelated($definition['masterObjectAlias'] ?? null, $errors);
        $relationship = $existing instanceof CustomObject ? null : $this->findRelated($definition['relationshipObjectAlias'] ?? null, $errors);

        if ([] !== $errors) {
            throw new DefinitionImportException($errors);
        }

        if ($existing instanceof CustomObject) {
            return $this->addMissingFields($existing, $fields, $apply);
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

        $createdCategory = null;
        if ($category instanceof Category) {
            $customObject->setCategory($category);
            if ($newCategory) {
                $createdCategory = (string) $category->getTitle();
            }
        }

        if ($apply) {
            if ($newCategory) {
                $this->categoryModel->saveEntity($category);
            }
            $this->customObjectModel->save($customObject);
        }

        return new CustomObjectDefinitionImportResult($customObject, true, array_column($fields, 'alias'), [], [], [], $createdCategory);
    }

    /**
     * @param mixed[] $fields the fields of the file, in their sequence
     */
    private function addMissingFields(CustomObject $customObject, array $fields, bool $apply): CustomObjectDefinitionImportResult
    {
        $existingFields = [];
        $nextOrder      = 0;
        foreach ($customObject->getCustomFields() as $field) {
            $existingFields[$field->getAlias()] = $field;
            $nextOrder                          = max($nextOrder, (int) $field->getOrder() + 1);
        }

        $added      = [];
        $required   = [];
        $skipped    = [];
        $mismatches = [];
        foreach ($fields as $rawField) {
            $alias = $rawField['alias'];
            if (isset($existingFields[$alias])) {
                $skipped[] = $alias;
                if ($existingFields[$alias]->getType() !== $rawField['type']) {
                    $mismatches[] = $this->t('custom.object.import.skipped_type', [
                        '%alias%'    => $alias,
                        '%existing%' => (string) $existingFields[$alias]->getType(),
                        '%file%'     => $rawField['type'],
                    ]);
                }

                continue;
            }

            $field = $this->buildField($customObject, $rawField, $nextOrder++);
            if ($apply) {
                $customObject->addCustomField($field);
            }
            $added[] = $alias;
            if ($field->isRequired()) {
                $required[] = $alias;
            }
        }

        if ($apply && [] !== $added) {
            $this->customObjectModel->save($customObject);
        }

        // a required field (every unique identifier is one) added to an object with items: those items have it empty and cannot be saved
        // again until it has a value, which is said to who imports
        $requiredWithItems = [] !== $required && $this->hasItems($customObject) ? $required : [];

        return new CustomObjectDefinitionImportResult($customObject, false, $added, $skipped, $mismatches, $requiredWithItems);
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

    private function hasItems(CustomObject $customObject): bool
    {
        return $this->customItemModel->getRepository()->getItemCount((int) $customObject->getId()) > 0;
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
     * The category of the file: the one of that alias if it exists here (as a `global` category, the kind a Custom Object has), else a new one
     * with the title and color of the file, saved only when the import is applied.
     *
     * @param mixed    $data
     * @param string[] $errors
     * @param bool     $isNew  set to true when the category is not in the database yet
     */
    private function findCategory($data, array &$errors, bool &$isNew): ?Category
    {
        if (null === $data) {
            return null;
        }

        $alias = is_array($data) ? ($data['alias'] ?? null) : null;
        $title = is_array($data) ? ($data['title'] ?? null) : null;
        if (!is_string($alias) || '' === trim($alias) || !is_string($title) || '' === trim($title)) {
            $errors[] = $this->t('custom.object.import.error.category');

            return null;
        }

        $found = $this->categoryModel->getRepository()->findOneBy(['alias' => $alias, 'bundle' => self::CATEGORY_BUNDLE]);
        if ($found instanceof Category) {
            return $found;
        }

        $isNew    = true;
        $category = new Category();
        $category->setAlias($alias);
        $category->setTitle($title);
        $category->setBundle(self::CATEGORY_BUNDLE);
        $category->setColor(is_string($data['color'] ?? null) ? $data['color'] : null);
        $category->setIsPublished(true);

        return $category;
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
