# Custom Object segment filters: same-item matching (merge filter)

How the merge filter, its two settings and the merged query work, and the n8n variables that read it. What must not break: one operator and one value per criterion, the value table from the real field type. Operators, test data, how to test and the deploy checklist: wiki/custom-objects-merged-segment-testing.md.

## What it is

A segment with two criteria on a Custom Object ("start date before today" AND "end date after today"): does Mautic check both on the SAME item, or on the contact as a whole?

```json
{
  "merge filter OFF": "each criterion is its own EXISTS check on the contact. A contact with item A (ended) and item B (not started) matches, because A satisfies one criterion and B the other.",
  "merge filter ON": "all AND criteria on the object are packed into one subquery that joins every field value to the SAME item (one custom_item_xref_contact row). Both criteria must be true on one item. Only Custom Object criteria joined by AND are grouped; an OR starts a new group; contact-field criteria are not grouped."
}
```

## Settings

```json
{
  "custom_object_merge_filter": "true turns the merge on. Plugin default: true.",
  "custom_object_item_value_to_contact_relation_limit": "must be 0 for the merge to work: any other value turns the merge off even when the flag is true. Plugin default: 0. (It also limits chains of item-to-item links, wiki/custom-objects-plugin.md.)",
  "defaults live in": "mc-cs-plugin-custom-objects/Config/config.php, 'parameters'",
  "who wins": "config/local.php and MAUTIC_* environment variables override the plugin defaults. The compose fallbacks (0 and 3) also override them: ../wiki/docker-mautic5.md. If an environment has either value saved, that value beats the plugin default.",
  "check in code": "ConfigProvider::isCustomObjectMergeFilterEnabled()",
  "in production": "the plugin defaults alone are enough: no env var and no config/local.php entry are needed. If it stops working, check the two settings first (a value in config/local.php or a MAUTIC_* variable beats the default), then cache:clear as www-data. No screen in Mautic shows these two values."
}
```

Setting from the environment: Mautic maps `MAUTIC_<PARAMETER_NAME_UPPERCASE>` and casts it (`1` and `0` are fine): `MAUTIC_CUSTOM_OBJECT_MERGE_FILTER=1`, `MAUTIC_CUSTOM_OBJECT_ITEM_VALUE_TO_CONTACT_RELATION_LIMIT=0`. A server without Docker: Mautic loads `.env` in the project root, but a `.env.local.php` (composer dump-env) makes it ignore `.env`; the safest is `config/local.php`: `'custom_object_merge_filter' => true, 'custom_object_item_value_to_contact_relation_limit' => 0` (read by the site, the console and the cron). Clear the cache as the web user: `sudo -u www-data php bin/console cache:clear` (not root, or the cache gets the wrong owner).

## How the plugin builds the merged query (rules that hold)

All in `QueryFilterHelper` (`createMergeFilterQuery`, `getMergeExpression`), each pinned by a test in `Tests/Unit/Helper/QueryFilterHelperTest.php`:

```json
{
  "operator per criterion": "getMergeExpression() must use each criterion's own operator (from merged_property), never the merged filter's single getOperator(). Otherwise 'start < today AND end > today' runs as 'start > today AND end > today'.",
  "value per criterion": "read the value from the criterion, not from the merged filter: the merged filter only reports its LAST criterion's value, so a 'between' that was not last got a scalar and threw 'expects an array with exactly two elements'. 'not between' is a real negation (NOT value BETWEEN a AND b). The segment screen does not offer between; a segment saved through the API can carry it.",
  "excluding (!in / notIn)": "QueryFilterHelper::addOperatorExpression must apply the expression of notIn like any other operator: it used to skip it (case notIn: break), so in the merged query a !in on a field became 'has any item' and on an item name 'has any item of the object' (an excluding with 3 values matched 128 contacts where three not-equal criteria matched 38). getMergeExpression() now builds the real negation on the item's own value (value IS NULL OR value NOT IN (list); the item name likewise), as for neq / notLike; the single item name builder (CustomItemNameFilterQueryBuilder) lists notIn with the NOT EXISTS operators. Multiselect / select option fields have their own EXISTS / NOT EXISTS path (addMergeOptionCondition) and were never affected.",
  "two criteria on the item name": "an item name has no value table: only the FIRST cmo criterion of an object joins the item, a later one must not fall into joinMergeCustomField (it read the object id as a field id and inner-joined nothing: 0 contacts for name <> A AND name <> B). The check is !$isCmoFilter in createMergeFilterQuery.",
  "negated operators": "the single-filter builders wrap notLike, item-name neq, etc. in NOT EXISTS, so their expressions are the POSITIVE condition. The merged query has no such wrapper: build the real negation on the item's own value (notLike -> IS NULL OR NOT LIKE; item-name neq -> <> OR IS NULL).",
  "value table from the REAL field type": "the segment screen saves the type 'select' for ANY choice field (SegmentFiltersChoicesGenerateSubscriber::getFieldProperties). Never pick the value table from the saved type: a multiselect would be read from custom_field_value_text instead of custom_field_value_option, and every operator on it matches nobody. Look the type up by field id. in / notIn / empty / notEmpty on option fields are EXISTS / NOT EXISTS on the item's option rows (one subquery per condition, so two conditions on the same field can both hold on one item); 'not in' and 'empty' cannot be a join, because an item with no option has no row.",
  "'not in' on a multiselect saved as 'select'": "core (ContactSegmentFilterCrate::setOperator) turns in / !in into multiselect / !multiselect only when the saved type is exactly 'multiselect'; with 'select' the operator stays 'notIn'. CustomFieldFilterQueryBuilder::isNegatedOperator() lists notIn and the helper treats it as !multiselect. Delegating to the multiselect decorator does not help: the operator is fixed in the crate constructor.",
  "0 is not empty": "MySQL says 0 = '' for numeric columns. Only the text table keeps the '' comparison for empty / not empty; numeric and date columns are checked for NULL / NOT NULL alone (Mautic itself skips the comparison only for date/datetime: ContactSegmentFilter::doesColumnSupportEmptyValue looks at the saved type).",
  "empty = no filled value, row or not": "in the merged path 'empty' on a field value (text, int, decimal, date) is 'an item of the field's own object with no filled value': EXISTS(item of the field's object) AND NOT EXISTS(value row that is not NULL, and not '' for text) (QueryFilterHelper::addMergeEmptyCondition). It must NOT be an inner join on the value table: an item with no row there (created before the field existed, or saved without it) would never match 'empty'. A contact with no item of that object matches neither empty nor not empty. Multiselect 'empty' is the NOT EXISTS on the option table.",
  "unfilled number is NULL": "IntType: '' and null are empty (NULL), 0 and '0' are values (CustomFieldValueInt::setValue() the same). Rows already saved as 0 for an empty number cannot be told apart from a real 0 and stay as they are: check any int field that already holds data in a new environment. DecimalType already kept NULL.",
  "public query for the items": "QueryFilterHelper::createMergedItemIdsQuery(filter, contactId) returns the ids of one contact's items that satisfy all criteria on the same item. n8n-dispatch calls it (a segment only asks whether one exists, the variable needs WHICH). Do not rename or reach into the plugin's internal alias.",
  "merge OFF path": "the per-condition query (QueryFilterFactory::createQuery()) must also take the value table from the real field type by id, and handle notIn and numeric 0 as above."
}
```

Known gaps, not fixed:

```json
{
  "between in the merged path": "the merged path reads each criterion's value now, but between is not offered by the screen and is only tested through the API.",
  "regexp / !regexp": "work on the item name (tested), not offered for fields.",
  "item name 'empty'": "merge ON: needs an item whose name is empty (none). Merge OFF: the plugin builds 'no item has an empty name', which for a name that can never be empty means every item. Two semantics; the live test expects each."
}
```

## n8n variables and merged filters

With the merge on, `getSegmentFilters()` returns ONE filter per group of AND conditions (the object of the last nested criterion, with all criteria in the crate's `merged_property`), and every Custom Object filter is of the merged type, even a single one. `CustomObjectVariableResolver` in n8n-dispatch resolves it: `SegmentItemMatcher::mergedFilterConcernsObject()` and `findMergedItemIds()` go through the plugin's `createMergedItemIdsQuery()`, restricted to the criteria on the resolved object, so all criteria must match the same item.

```json
{
  "deploy together": "n8n-dispatch calls a method that only the newer plugin has (QueryFilterHelper::createMergedItemIdsQuery). Plugin only = every Custom Object variable empty; n8n only = works but with the plugin's old SQL (the bugs above).",
  "target field of the variable (the value that goes into the message)": {
    "date": "the date column is a DATETIME, so a date field is stored as 'Y-m-d 00:00:00'; DateFormatter accepts both shapes and drops the time (25/09/2026 in pt_BR).",
    "empty entries": "an unfilled field ('' or NULL) is skipped when joining, so there is no stray separator ('<br>sim').",
    "multiselect": "the Custom Objects plugin's SegmentItemMatcher::groupOptionValues() joins the options of ONE item with ', ' in the order the options are defined: one entry per item, so the options of one item do not look like several items.",
    "'not in' with the merge OFF": "NEGATED_FIELD_OPERATORS includes notIn, like the segment"
  },
  "tests": "Tests/Unit/Resolver in n8n-dispatch-bundle, plus the two live resolver scripts above"
}
```
