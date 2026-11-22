# Custom Objects plugin wiki

Docs of `CustomObjectsBundle`, the external fork of the Custom Objects plugin for Mautic 5. Start with the overview, then read only the file of the area you touch. Pattern files hold the rules that hold across features; each feature file points to the ones it follows.

## UI

- [ui-list.md](ui-list.md): lists, row menus, item / object modals and footers, status badge (links to the shared root rules).

## API

- [api-conventions.md](api-conventions.md): write rules, transactions, idempotent DELETE, 503 retry, permissions of the REST API.

## Testes

- [tests.md](tests.md): how to run unit suite, coverage and mutation; live-test conventions.

## Stack

- [stack-plugin.md](stack-plugin.md): install / reload, cache, assets, autowiring, 191-char limit, translations.

## Shared with other plugins (root wiki)

- [../../wiki/ui-status-column.md](../../wiki/ui-status-column.md): the Status column rules.
- [../../wiki/ui-badge.md](../../wiki/ui-badge.md): the icon badge rules.

## Funcionalidades

- [custom-objects-plugin.md](custom-objects-plugin.md): where the plugin lives, branches, tests (start with the overview).
- [custom-objects-api.md](custom-objects-api.md): REST API, search filters, field types (also the `README.md` REST API guide of the plugin repo: [../README.md](../README.md)).
- [custom-objects-item-list.md](custom-objects-item-list.md): item list screen, search by field, item modal.
- [custom-objects-status-column.md](custom-objects-status-column.md): the Status column (icon badge, Ativar / Desativar) of the object list.
- [custom-objects-definition-clone.md](custom-objects-definition-clone.md): clone an object to another instance (Clone tab, definition JSON, import).
- [custom-objects-data.md](custom-objects-data.md): indexes, migrations, child objects, export and import.
- [custom-objects-segments-and-campaigns.md](custom-objects-segments-and-campaigns.md): segment filter in / !in, campaign events, SegmentItemResolver.
- [custom-objects-seed.md](custom-objects-seed.md): seed data.
- [custom-objects-merged-segment-filters.md](custom-objects-merged-segment-filters.md): segment criteria on Custom Object fields (same item, merge filter), settings and query.
- [custom-objects-merged-segment-testing.md](custom-objects-merged-segment-testing.md): operators, test data, tests and deploy checklist of the merge filter.
- [../../wiki/segment-custom-object-filter-test.md](../../wiki/segment-custom-object-filter-test.md): root test of the segment filter on Custom Object fields.
