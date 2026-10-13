<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Helper;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;

/**
 * The values already stored for a Custom Object filter of the segment form (cmf_<field id>: a text field, cmo_<object id>:
 * the item name), distinct and matching what the user typed. They are the suggestions of the including / excluding
 * chips; any other value can still be typed. Read access to the object is required.
 */
class SegmentFilterValues
{
    public const MAX = 50;

    private const TEXT_TYPES = ['text', 'email', 'phone', 'url', 'textarea'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CustomItemPermissionProvider $permissions
    ) {
    }

    /**
     * @return string[]
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    public function find(string $filterField, string $search, int $limit = 20): array
    {
        $limit  = max(1, min(self::MAX, $limit));
        $like   = '%'.addcslashes($search, '%_\\').'%';
        $conn   = $this->entityManager->getConnection();

        if (1 === preg_match('/^cmo_(\d+)$/', $filterField, $m)) {
            $this->permissions->canViewAtAll((int) $m[1]);

            return array_map('strval', $conn->fetchFirstColumn(
                'SELECT DISTINCT name FROM '.MAUTIC_TABLE_PREFIX.'custom_item WHERE custom_object_id = :object AND name LIKE :search AND name <> \'\' ORDER BY name LIMIT '.$limit,
                ['object' => (int) $m[1], 'search' => $like]
            ));
        }

        if (1 !== preg_match('/^cmf_(\d+)$/', $filterField, $m)) {
            throw new NotFoundException("{$filterField} is not a custom object filter");
        }

        $field = $this->entityManager->find(CustomField::class, (int) $m[1]);
        if (!$field instanceof CustomField || !in_array($field->getType(), self::TEXT_TYPES, true) || null === $field->getCustomObject()) {
            throw new NotFoundException("{$filterField} is not a text field");
        }

        $this->permissions->canViewAtAll((int) $field->getCustomObject()->getId());

        return array_map('strval', $conn->fetchFirstColumn(
            'SELECT DISTINCT value FROM '.$field->getTypeObject()->getPrefixedTableName().' WHERE custom_field_id = :field AND value LIKE :search AND value <> \'\' ORDER BY value LIMIT '.$limit,
            ['field' => (int) $m[1], 'search' => $like]
        ));
    }
}
