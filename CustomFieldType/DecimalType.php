<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\CustomFieldType;

use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueDecimal;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueInterface;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;

/**
 * Number with a fractional part, stored as DECIMAL(20,6) (exact, so
 * "= 3.75" style segment filters don't suffer float rounding). IntType
 * casts with (int) and silently drops the fraction.
 */
class DecimalType extends AbstractCustomFieldType
{
    /**
     * @var string
     */
    public const NAME = 'custom.field.type.decimal';

    public const TABLE_NAME = 'custom_field_value_decimal';

    /**
     * @var string
     */
    protected $key = 'decimal';

    public function getSymfonyFormFieldType(): string
    {
        return \Symfony\Component\Form\Extension\Core\Type\NumberType::class;
    }

    public function getEntityClass(): string
    {
        return CustomFieldValueDecimal::class;
    }

    /**
     * @param mixed|null $value
     */
    public function createValueEntity(CustomField $customField, CustomItem $customItem, $value = null): CustomFieldValueInterface
    {
        return new CustomFieldValueDecimal($customField, $customItem, $value);
    }

    /**
     * Same operators as IntType, minus 'between'/'!between' (hidden from
     * the segment builder for every type, see IntType).
     *
     * @return string[]
     */
    public function getOperatorOptions(): array
    {
        $options = parent::getOperatorOptions();

        unset($options['between'], $options['!between']);

        return $options;
    }

    /**
     * @return mixed[]
     */
    public function getOperators(): array
    {
        $allOperators     = parent::getOperators();
        $allowedOperators = array_flip(['=', '!=', 'gt', 'gte', 'lt', 'lte', 'empty', '!empty', 'between', '!between']);

        return array_intersect_key($allOperators, $allowedOperators);
    }
}
