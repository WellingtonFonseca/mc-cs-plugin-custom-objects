# Custom Objects plugin for Mautic

Allows Mautic users to define Custom Objects with custom fields. Then create multiple custom items of each type and associate them to the Contact or Company entities.

You'll be able to 
- filter Segments by values in the Custom Objects
- create Campaign conditions based on the values in the Custom Objects

## Workflow Update

Until Github Actions' CI/CD are in place, we request to developers to:
1. Merge their unreviewed and unapproved PRs to `development` branch.
2. Merge their reviewed and approved PRs to `beta` branch.

## Glossary

- `Custom Field` represents one piece of information. Example: Price, Description, Color
- `Custom Object` is set of custom fields that will allow users to create multiple instances of this object. Example: Product, Invoice.
- `Custom Item` is created when Custom Object fields are populated with specific information. Example: Mautic T-shirt, Invoice 2022-02-22-123.

## Example Usage

As an example we can create a `Custom Object` **Product**. To create one go to the right hand side admin menu. This Custom Object will have these `custom fields`:
- Name is always there by default. No need to create a custom field for it.
- Price _(Number field)_
- Description _(Textarea field)_
- Color _(Select box with options: Red, Green, Blue)_

Once we save such Custom Object then on the left hand side menu we'll be able to see new **Product** menu item and we'll be able to start creating new product **custom items**:

Product 1:
Name: Mautic T-shirt
Price: $123
Description: Great T-shirt to support your favorite project and market it whenever you walk!
Color: Blue

Product 2:
Name: Mautic Hoodie
Price: $153
Description: Great hoodie to support your favorite project and market it whenever you walk and stay worm at the same time!
Color: Red

Once some of your contacts buy a Mautic T-shirt or hoodie you can link them with the product they've bought. You can automate that with a Mautic Campaign action, API or CSV import. Once these links are established you'll be able to build Segments based on who bought what, or if they for example bought products with price greater than $100 or products of red color. Then your automated workflows can hit the right audience.

## Campaign condition on several criteria of the same item

The campaign condition "<object> fields" (pt_BR "Campos de <objeto>") takes more than one criterion: the first (field, operator, value) and any number added with "Adicionar critério". The field is a Custom Field or the name of the item, the values may be relative dates (`-35 days`, `+10 days`). When there is more than one criterion, **one item** has to satisfy all of them (the same query as the merged segment filter), for example "an item named X that started within the last 35 days and ends within 10 days". Two condition nodes in a row would not guarantee the same item. The item name and the text fields (text, email, phone, url, textarea) also offer including / excluding (`in` / `!in`, several values joined with `|`) like the segment filter; the chips for them come from MauticPatchesBundle. The texts follow the system language (en_US and pt_BR). Needs an action before the condition in the campaign.

The node of the condition and of the link action on the canvas is named by translations the plugin adds for each object (`mautic.campaign.custom_item.<id>.fieldvalue` and `.linkcontact`), because core prints the translation of `mautic.campaign.<event type>` and the type holds the id of the object. They are added to the catalogue in use when the events are built (`CampaignSubscriber::registerNodeLabels`).

## Which items a segment points at (SegmentItemResolver)

A contact can have many items of the same object, and a segment only asks whether a matching item EXISTS. `Segment/SegmentItemResolver::resolveItemIds($contact, $segments, $customObject)` answers which items the segments' conditions on that object select: the AND conditions must hold for the SAME item, OR groups add their items together, several segments add together, operators the segment applies as NOT EXISTS keep every other item, and a merged filter (`custom_object_merge_filter`) is applied as written. It returns `null` when no segment the contact is in has a condition on the object (nothing says which items count), an empty list when there is a condition and no item satisfies it, otherwise the sorted item ids. `Segment/SegmentItemMatcher` is its SQL half (it reuses the plugin's own `QueryFilterHelper`, so operators, values and relative dates match the segment). Other plugins use it: the n8n dispatch variables and the campaign action "Wait" of the patches bundle. Tests: `Tests/Unit/Segment`.

## Export and import of items

The export folder is `custom_item_export_dir` (`MAUTIC_CUSTOM_ITEM_EXPORT_DIR`); when it is not set the export is written to `media/files/temp` of the install. An import row is one transaction: a row with an invalid value is refused and leaves no item behind.

## Segment filters: including / excluding on text fields

The segment form offers `including` / `excluding` (`in` / `!in`) on the text fields of a Custom Object (text, email, phone, url, textarea) and on the item name, so several values fit in one criterion, joined with `|` (`A|B|C`), instead of one criterion per value joined by OR. The suggestions of the segment form's value chips (from MauticPatchesBundle) come from `GET /s/custom/segment-filter-values.json?field=cmf_<field id>|cmo_<object id>&q=&limit=` (distinct stored values, view access to the object required). Code: `EventListener/SegmentTextInOperatorSubscriber.php`, `Controller/SegmentFilterValuesController.php`, `Helper/SegmentFilterValues.php`.

## Item list: search by label and view modal

The search box of an object's item list takes `field:value` terms where the field is the alias or the label shown on the screens (`Curso:Matematica`, or `"Nome do Curso":Matematica` when the label has spaces; case does not matter). A click on an item's name opens a modal with the same detail rows as the item page (category, created by and on, modified by and on, ID), its field values and its linked contacts (up to 50), in the item list and in the Custom Objects tab of a contact; ctrl / cmd / shift click opens the full page. The footer has Editar (only for who may edit the item), Detalhes (the item page) and Fechar. The modal follows the item page's permissions, and shows the contacts only to users who may view contacts.

## Screens (Custom Objects admin)

- **Lists:** the three-dots menu of a row (objects and items) has "Detalhes" (the page), above Edit. A click on an object's name opens a modal with its fields (label, alias, type, required, unique identifier, default value, options); on an item's name, a modal with its values. Both modals have a footer with Editar (only with the edit permission), Detalhes (the page) and Fechar. Ctrl / cmd / shift click opens the page. Long texts without spaces break inside the cell.
- **View pages:** a "Back to <list>" button above the title (objects list for an object, items list for an item).
- **Object edit form:** the edit and delete buttons of a field use core's ghost style (md size, like the form builder of core); delete asks for confirmation first (the field is only removed when the object is saved). The plugin JS is bundled in core's `app.js`: run `mautic:assets:generate` after deploying a change to `Assets/js`.

## Requirements

- Plugin supports PHP 7.1+.

## Documentation

See [Wiki](https://github.com/acquia/mc-cs-plugin-custom-objects/wiki)

## Tests

The plugin has currently test coverage of 91%. Each new PR must be covered by tests to be considered to be merged. To run the tests execute `composer test -- --filter CustomObjects` from the Mautic root dir.

Without the Mautic monorepo (the Docker stack of this fork), the unit suite and mutation testing run from the plugin folder inside the web container, with the files in `Tests/Infection/`:

```bash
php /var/www/html/vendor/bin/phpunit -c Tests/Infection/phpunit.xml
infection --configuration=Tests/Infection/infection.json5 --threads=8 --no-progress --initial-tests-php-options='-d pcov.enabled=1' --min-msi=0
```

`Tests/Infection/bootstrap.php` loads the project autoloader without nikic/php-parser (Infection brings its own). `Tests/Functional` needs the monorepo and does not run there.

## Static Analysis

PHPSTAN is used for static analysis. Here is how to run it locally from the Mautic's root dir:
```
bin/phpstan --configuration=plugins/CustomObjectsBundle/phpstan.neon
```

This plugin has its own PHPSTAN configuration because there is too big tech debt pile already and so it must have its own PHPSTAN baseline file.

The tech debt is visible in the phpstan-baseline.neon file and can be regenerated with:
```
bin/phpstan --configuration=plugins/CustomObjectsBundle/phpstan.neon --generate-baseline=plugins/CustomObjectsBundle/phpstan-baseline.neon && sed -i 's/plugins\/CustomObjectsBundle\//g' plugins/CustomObjectsBundle/phpstan-neon.php
```
Please do not add more tech debt on this pile. Fix all the new PHPSTAN errors and possibly some old ones with your code changes.

## License

Copyright (C) 2022 Acquia, Inc.

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
