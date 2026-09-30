<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

class CustomFieldValueDecimal extends AbstractCustomFieldValue
{
    /**
     * Doctrine hands DECIMAL columns back as strings ("3.750000"), so this
     * holds a string when hydrated and a float/string when set by code;
     * getValue() always returns a float (or null).
     *
     * @var string|float|null
     */
    private $value;

    public function __construct(CustomField $customField, CustomItem $customItem, $value = null)
    {
        parent::__construct($customField, $customItem);

        $this->setValue($value);
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('custom_field_value_decimal');
        $builder->addIndex(['value'], 'value_index');
        // addNullableField() takes no precision/scale, hence createField();
        // keep in sync with Migrations/Version_0_0_28.php.
        $builder->createField('value', Types::DECIMAL)->nullable()->precision(20)->scale(6)->build();

        parent::addReferenceColumns($builder);
    }

    /**
     * @param mixed $value
     */
    public function setValue($value = null): void
    {
        $this->value = (null === $value || '' === $value) ? null : (float) $value;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return null === $this->value ? null : (float) $this->value;
    }
}
