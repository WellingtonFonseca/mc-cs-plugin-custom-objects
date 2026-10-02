<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Helper;

use Doctrine\ORM\QueryBuilder;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueDate;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueDateTime;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueDecimal;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueInt;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueOption;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueText;
use MauticPlugin\CustomObjectsBundle\Exception\InvalidValueException;

/**
 * Filters Custom Items by the value of ONE field. This is the only place that
 * knows how to do it: the item list search ("alias:value") and the items API
 * ("?alias=value") both call apply().
 *
 * The meaning of the value comes from the REAL type of the field, never from
 * what the caller typed:
 *
 *   text, textarea, email, phone, url, hidden  contains, case-insensitive (the API asks for TEXT_EQUALS: exact)
 *   select, country, radio_group               exact
 *   int, decimal                               number; may start with > >= < <= (=); decimal comma or dot
 *   date                                       the whole day; dd/mm/yyyy or yyyy-mm-dd; may start with > >= < <=
 *   datetime                                   the whole day when only a date is given, exact when a time is given
 *   multiselect, checkbox_group                the item has that option
 *
 * Every filter is an EXISTS on the value table of that type, by field id, with
 * bound parameters (sfield<N>, sval<N>, and svalend<N> for the end of a day range), so the (field, value)
 * indexes can be used and the typed text never reaches the DQL.
 *
 * Date columns are DATETIME and hold "Y-m-d 00:00:00", so a day is compared as
 * a range [day 00:00:00, next day 00:00:00) instead of with DATE(value).
 * Datetime values are compared as they are stored (no time zone conversion).
 */
class ItemFieldFilter
{
    public const TEXT_CONTAINS = 'contains';
    public const TEXT_EQUALS   = 'equals';

    private const LIKE_ESCAPE = '!';

    private const FAMILY_BY_TYPE = [
        'text'           => 'text',
        'textarea'       => 'text',
        'email'          => 'text',
        'phone'          => 'text',
        'url'            => 'text',
        'hidden'         => 'text',
        'select'         => 'exact',
        'country'        => 'exact',
        'radio_group'    => 'exact',
        'int'            => 'int',
        'decimal'        => 'decimal',
        'date'           => 'date',
        'datetime'       => 'datetime',
        'multiselect'    => 'option',
        'checkbox_group' => 'option',
    ];

    private const VALUE_CLASS_BY_FAMILY = [
        'text'     => CustomFieldValueText::class,
        'exact'    => CustomFieldValueText::class,
        'option'   => CustomFieldValueOption::class,
        'int'      => CustomFieldValueInt::class,
        'decimal'  => CustomFieldValueDecimal::class,
        'date'     => CustomFieldValueDate::class,
        'datetime' => CustomFieldValueDateTime::class,
    ];

    public function isSupported(string $fieldType): bool
    {
        return isset(self::FAMILY_BY_TYPE[$fieldType]);
    }

    /**
     * Adds "AND EXISTS (value of this field matches)" to the query.
     *
     * @param string $rootAlias DQL alias of the CustomItem in $queryBuilder
     * @param string $textMode  TEXT_CONTAINS or TEXT_EQUALS (text types only)
     * @param int    $index     makes the parameter names unique for each call on the same query
     *
     * @throws \UnexpectedValueException when the field type cannot be filtered
     * @throws InvalidValueException     when the value is not valid for the type
     */
    public function apply(QueryBuilder $queryBuilder, string $rootAlias, CustomField $field, string $value, string $textMode, int $index): void
    {
        $type = (string) $field->getType();

        if (!$this->isSupported($type)) {
            throw new \UnexpectedValueException("Filtering by field type '{$type}' is not supported.");
        }

        $family = self::FAMILY_BY_TYPE[$type];
        $alias  = 'sv'.$index;
        $value  = trim($value);

        [$condition, $parameters] = match ($family) {
            'text'     => self::TEXT_EQUALS === $textMode ? $this->equals($alias, $index, $value) : $this->contains($alias, $index, $value),
            'exact',
            'option'   => $this->equals($alias, $index, $value),
            'int',
            'decimal'  => $this->number($alias, $index, $value),
            'date'     => $this->day($alias, $index, $value, false),
            'datetime' => $this->day($alias, $index, $value, true),
        };

        $queryBuilder->andWhere(sprintf(
            'EXISTS (SELECT 1 FROM %s %s WHERE IDENTITY(%s.customItem) = %s.id AND IDENTITY(%s.customField) = :sfield%d AND %s)',
            self::VALUE_CLASS_BY_FAMILY[$family],
            $alias,
            $alias,
            $rootAlias,
            $alias,
            $index,
            $condition
        ));
        $queryBuilder->setParameter('sfield'.$index, $field->getId());

        foreach ($parameters as $name => $parameterValue) {
            $queryBuilder->setParameter($name, $parameterValue);
        }
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function equals(string $alias, int $index, string $value): array
    {
        return ["{$alias}.value = :sval{$index}", ['sval'.$index => $value]];
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function contains(string $alias, int $index, string $value): array
    {
        $escape  = self::LIKE_ESCAPE;
        $escaped = str_replace([$escape, '%', '_'], [$escape.$escape, $escape.'%', $escape.'_'], mb_strtolower($value));

        return [
            "LOWER({$alias}.value) LIKE :sval{$index} ESCAPE '{$escape}'",
            ['sval'.$index => '%'.$escaped.'%'],
        ];
    }

    /**
     * @return array{string, array<string, string>}
     *
     * @throws InvalidValueException
     */
    private function number(string $alias, int $index, string $value): array
    {
        [$operator, $rest] = $this->splitOperator($value);

        if (!preg_match('/^[+-]?\d+(?:[.,]\d+)?$/', $rest)) {
            throw new InvalidValueException("'{$value}' is not a number.");
        }

        return ["{$alias}.value {$operator} :sval{$index}", ['sval'.$index => str_replace(',', '.', ltrim($rest, '+'))]];
    }

    /**
     * @return array{string, array<string, string>}
     *
     * @throws InvalidValueException
     */
    private function day(string $alias, int $index, string $value, bool $keepTime): array
    {
        [$operator, $rest] = $this->splitOperator($value);
        [$start, $hasTime] = $this->parseDate($rest, $value);

        if ($hasTime && $keepTime) {
            return ["{$alias}.value {$operator} :sval{$index}", ['sval'.$index => $start->format('Y-m-d H:i:s')]];
        }

        $start = $start->setTime(0, 0, 0);
        $next  = $start->modify('+1 day');
        $from  = 'sval'.$index;

        return match ($operator) {
            '='     => ["{$alias}.value >= :{$from} AND {$alias}.value < :svalend{$index}", [$from => $start->format('Y-m-d H:i:s'), 'svalend'.$index => $next->format('Y-m-d H:i:s')]],
            '<'     => ["{$alias}.value < :{$from}", [$from => $start->format('Y-m-d H:i:s')]],
            '<='    => ["{$alias}.value < :{$from}", [$from => $next->format('Y-m-d H:i:s')]],
            '>'     => ["{$alias}.value >= :{$from}", [$from => $next->format('Y-m-d H:i:s')]],
            default => ["{$alias}.value >= :{$from}", [$from => $start->format('Y-m-d H:i:s')]],
        };
    }

    /**
     * @return array{string, string} operator (= < <= > >=) and the rest of the value
     */
    private function splitOperator(string $value): array
    {
        if (preg_match('/^(>=|<=|>|<|=)\s*(.*)$/s', $value, $match)) {
            return [$match[1], trim($match[2])];
        }

        return ['=', $value];
    }

    /**
     * @return array{\DateTimeImmutable, bool} the moment and whether a time of day was given
     *
     * @throws InvalidValueException
     */
    private function parseDate(string $text, string $original): array
    {
        $pattern = '/^(?:(?<d>\d{2})\/(?<m>\d{2})\/(?<y>\d{4})|(?<y2>\d{4})-(?<m2>\d{2})-(?<d2>\d{2}))(?:[T ](?<h>\d{2}):(?<i>\d{2})(?::(?<s>\d{2}))?)?$/';

        if (!preg_match($pattern, $text, $match)) {
            throw new InvalidValueException("'{$original}' is not a date (use dd/mm/yyyy or yyyy-mm-dd).");
        }

        $year  = (int) ('' !== $match['y'] ? $match['y'] : $match['y2']);
        $month = (int) ('' !== $match['m'] ? $match['m'] : $match['m2']);
        $day   = (int) ('' !== $match['d'] ? $match['d'] : $match['d2']);
        $hour  = (int) ($match['h'] ?? 0);
        $min   = (int) ($match['i'] ?? 0);
        $sec   = (int) ($match['s'] ?? 0);

        if (!checkdate($month, $day, $year) || $hour > 23 || $min > 59 || $sec > 59) {
            throw new InvalidValueException("'{$original}' is not a valid date.");
        }

        $moment = (new \DateTimeImmutable('today'))->setDate($year, $month, $day)->setTime($hour, $min, $sec);

        return [$moment, isset($match['h']) && '' !== $match['h']];
    }
}
