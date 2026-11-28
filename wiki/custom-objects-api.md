# Custom Objects: REST API, filters and field types

Padrão: [api-conventions.md](api-conventions.md). The `/api/customobjects/{alias}/items` routes (list/search, create, update, upsert, delete, batch delete), the list/search filter semantics and the field types (including `decimal`).

## API

The REST API is the routes in `routes.api` of `Config/config.php` (admin-UI vs API routes: api-conventions.md):

```json
{
  "list/search": "GET /api/customobjects/{alias}/items[?fieldAlias=value...] (CustomItemSearchApiController)",
  "get one": "GET /api/customobjects/{alias}/items/{itemId} (getAction): the serialized item + linkedContactCount (contacts linked to it). Same view permission as the list (viewown = only own items). 404 unknown alias, or an item that does not exist / belongs to another object / is not visible to the caller.",
  "create": "POST same path, body {\"data\": [{\"name\": \"...\", \"attributes\": {fieldAlias: value}}]}. 'id' on any entry = 400.",
  "update": "PATCH same path, same body shape with 'id' on every entry. 'id' missing or not found = 400.",
  "upsert": "PUT same path. 'id' found = update; 'id' missing or not found = create (fresh id). Matches core's own PUT convention.",
  "delete one": "DELETE /api/customobjects/{alias}/items/{itemId} -> the batch shape below. 404 for an unknown alias or an item of another object. An unknown item is 200 with the id in alreadyGone.",
  "delete batch": "DELETE /api/customobjects/{alias}/items with {\"data\": [{\"id\": ...}]} -> {\"total\": N, \"deleted\": [ids], \"alreadyGone\": [ids]}. Idempotent (api-conventions.md): an unknown id goes to alreadyGone and the rest is deleted; an id repeated in the call is deleted once and is never alreadyGone (a repeat used to fail with a 404 that named an existing id). One transaction: an id of ANOTHER object = 404 and nothing is deleted. A body without 'data', an entry without 'id', or an id that is not a positive integer = 400 (a typo must not become a success).",
  "write rules": "POST and PATCH strictly split, one transaction per batch, 'name' max 191 (api-conventions.md). PUT carries the silent-fallback risk by design.",
  "multiselect rows": "CustomFieldValueModel::deleteOptionsForField reads the ids of the option rows of the item and field WITHOUT a lock and deletes them by id (PK); no rows = no DELETE. The old DELETE by (custom_field_id, custom_item_id) scanned the index of custom_field_id and, when it found nothing (a new item), took a next-key lock on the END of that index: two transactions that then inserted there waited for each other (deadlock 1213, InnoDB status of 2026-11-19 12:57:23, index IDX_7D4C1F48A1E5E0D4). Live: scripts/5/test-items-concurrent-multiselect.sh (5 simultaneous POSTs to 5 own objects, a text-only discriminator, 10 rounds of 2 simultaneous PATCHes). NOT covered: POST/PATCH of contacts with customObjects.data (ApiSubscriber) uses the same CustomItemModel::save, so it has the same fix, but a deadlock that still happens there is a 500 of the core; the concurrent item import (CustomItemImportModel); the real isolation level and binlog of production. A PATCH still deletes and re-inserts all the options of the field of that item (a diff of the values is a later task).",
  "link": "POST /api/customobjects/items/{itemId}/links/{entityType}/{entityId} (CustomItemLinkApiController). entityType = contact | customItem, else 400. Edit permission on the item (403). Unknown item or target = 404. Already linked = 200 (idempotent).",
  "unlink": "DELETE same path. Edit permission. 404 when the link does not exist (NOT idempotent, unlike item DELETE); validated before anything is deleted, so a 404 never removes the child item of a relationship object.",
  "contact items": "GET /api/contacts/{contactId}/customitems[?alias=&limit=&offset=]: items of the contact grouped by object ({id, alias, items[{id,name,isPublished}]}). limit default 10, max 100, per object. offset is rounded down to a multiple of limit (page based); the response echoes the offset used. Objects the caller cannot view are left out; contact needs lead view permission (403).",
  "no auto-link": "the direct routes never link an item to a contact.",
  "not covered": "campaigns that use the fields of an object are not checked by the object DELETE."
}
```

### The Custom Object TYPE

`Controller/Api/CustomObjectApiController`: the object and its fields (no items). It reuses `Helper/CustomObjectDefinitionImporter`, so the body is the JSON of the Clone tab (`format` `custom-object-definition`, `version` 1) plus an optional `isPublished` (default true, read only for a new object; the exporter does not write it). No validation rule of its own: the rules are the importer's.

```json
{
  "create": "POST /api/customobjects, body = the definition. 201 {id, alias, customFields: [{id, alias, type}]}. Create-only: the importer runs with createOnly (import($json, true, true)), so an alias that already exists = 409 BEFORE anything is read or saved (without it the importer would take the alias as 'add the missing fields' and a 409 would already have saved fields). Any problem of the file = 400 {errors: [{message}...]} with ALL of them. One transaction.",
  "race": "closed: custom_object.alias is a UNIQUE index (Migrations/Version_0_0_32, see wiki/custom-objects-data.md). Two simultaneous POSTs of one new alias: one 201, the others 409. The importer catches the unique violation AND a deadlock / lock wait timeout (RetryableException), rolls back and checks the alias through the connection: alias there = 409, alias free = the generic 400. The create also refuses an alias that CustomObjectModel::save renamed ('<alias>1'): a create never saves under another alias.",
  "view": "GET /api/customobjects/{alias} = the Clone tab JSON + id, isPublished and the id of each field. 404 unknown alias.",
  "delete": "DELETE /api/customobjects/{alias}: fields and items go by cascade. 200 {alias, deleted, alreadyGone}; an unknown alias is 200 with alreadyGone true (idempotent), but only after the view permission is checked (a caller without it gets 403 for any alias, so 'alreadyGone' does not tell which aliases exist). 409 {errors, segments: [{id, name}]} when a segment filter uses the object. 409 naming the child when the object is a MASTER with a child (relationshipObject): the database would delete the child and its items by cascade (master_object ON DELETE CASCADE) without the segment check, so the caller deletes the child first. Same permission as the delete on the screen. It does NOT check campaigns that use the fields: teardown order is campaign, segment, child, master.",
  "permissions": "every action checks CustomObjectPermissionProvider (create / view / delete): 403 (see api-conventions.md).",
  "traps": "a field alias is unique in the WHOLE instance and must be clean (lower case, letters, digits, hyphen): a test makes '<runid><field>'. relationshipObject has a unique index: a master has one child; a child goes only under an object of the test, never under 'disciplina'.",
  "tests": "Tests/Unit/Controller/Api/CustomObjectApiControllerTest, the isPublished and createOnly tests of CustomObjectDefinitionImporterTest. Live: scripts/5/test-objects-api.sh. Helpers for tests: make_object / drop_object (scripts/5/lib/objects.sh) and createCustomObject / deleteCustomObject (e2e helpers.js)."
}
```

**Linking an item to a contact** only exists inside the contact hook (`EventListener/ApiSubscriber.php`, `SerializerSubscriber.php`; it fires only for `LeadApiController::(new|edit)Entity(ies)Action`):

```json
{
  "write + link": "PATCH /api/contacts/{id}/edit or POST /api/contacts/new with a top-level customObjects.data array (same item shape). Every item in it is linked to the contact. An item 'id' with no attributes links an existing item and changes nothing else.",
  "read with links": "GET /api/contacts/{id}?includeCustomObjects=true adds a customObjects key.",
  "no unlink": "no API route unlinks (CustomItemModel::unlinkEntity() is reachable only from the admin UI and the campaign action). Deleting the item removes its links as a side effect (ON DELETE CASCADE on the xref tables), for every contact at once: safe as an 'unlink' only while each item belongs to one contact.",
  "contact delete": "deleting a contact does NOT delete its items."
}
```

The guide for callers, with examples, is the REST API guide section of `mc-cs-plugin-custom-objects-5/README.md`.

`#[ApiResource]` annotations on the entities (a `/api/v2/...` CRUD) do not work here: `api-platform` is not installed in the image (`GET /api/v2/custom_items` is 404). Do not build on them. Decision: keep only the plain routes `/api/customobjects/{alias}/items` (what the production n8n uses for GET, POST and PATCH); the API Platform classes stay as unused code. The plain GET already paginates (`limit`/`offset`, see the list/search filter semantics).

### List/search filter semantics

Each query parameter is `<fieldAlias>=<value>`, ANDed. The filter is `Helper/ItemFieldFilter`, the same code as the item list search on the screen; text-like types need an EXACT value here (the screen does "contains"):

```json
{
  "text, textarea, email, phone, url, hidden, select-like": "exact value",
  "int, decimal, date, datetime": "exact, or a leading >, >=, <, <= (URL-encode > and <): ?progresso=%3E%3D0.5",
  "date": "the whole day, dd/mm/yyyy or yyyy-mm-dd. Dates in the answer are plain Y-m-d.",
  "multiselect": "matches when the item has that option",
  "errors": "404 unknown alias or a parameter that is not a field alias; 400 invalid value for the type (?inicio=31/02/2026). Pagination: reserved params limit (clamped to 1-100, default 25) and offset (>= 0, default 0), ordered by id; the answer has total (count of ALL matches, not of the page), limit, offset, items. Out-of-range values are clamped, not rejected. limit and offset are never field filters; any other non-field param (page, name) still answers 404. CALLERS (n8n flows): no limit now returns at most 25 items, not every item; loop with offset until offset+limit >= total.",
  "values read in batch": "CustomItemModel::populateCustomFieldsForItems -> CustomFieldValueModel::createValuesForItems reads the values of all items together (one query per value table, chunks of 1000). The old per-item populateCustomFields made one UNION ALL query per item: 7174 queries and ~2.3 s per call in production. Keep it batched."
}
```

## Field types

`decimal` is added by this fork: `CustomFieldType/DecimalType.php`, stored in `custom_field_value_decimal` as DECIMAL(20,6) (up to 14 integer digits and 6 places; MySQL rounds more places without an error). The `int` type ("Number") casts with `(int)`, so 3.7 is stored as 3: use `decimal` for fractions.

```json
{
  "entity": "Entity/CustomFieldValueDecimal.php: Doctrine returns DECIMAL as a string ('3.750000'); getValue() returns a float, null/'' -> null, 0 stays 0. The mapping must use createField()->precision(20)->scale(6): ClassMetadataBuilder::addNullableField() takes no precision/scale and silently loses them.",
  "table": "created by Migrations/Version_0_0_28.php. mautic:plugins:install does NOT create it on an existing install (it answers '0 updated'): run mautic:plugins:reload.",
  "UI": "Resources/views/CustomObject/Form/Panel/decimal.html.twig is required: the object form includes Form/Panel/<type>.html.twig for each field; without it Save/Close of the modal crashes (Twig LoaderError). RegisteredFieldTypesTest guards that every registered type has its panel and its translated label.",
  "other places": "Config/config.php (service tagged custom.field.type), Translations custom.field.type.decimal, Report/ReportColumnsBuilder ('decimal' => 'float')",
  "segments": "work like any type (>, <, =)",
  "n8n": "how n8n-dispatch formats decimals: ../n8n-dispatch-bundle-5/wiki/n8n-dispatch-payload-variables.md, the 'decimals' key",
  "tests": "DecimalTypeTest, CustomFieldValueDecimalTest (incl. the DECIMAL(20,6) mapping), RegisteredFieldTypesTest. Live: scripts/5/test-decimal-field.sh (throwaway item: POST/PATCH/empty, then deletes it)."
}
```
