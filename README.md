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

## Clone an object to another instance (definition JSON)

The **Clone** tab of the object page, of the object edit page and of the object modal of the list shows the definition of the object (the object and its fields, options included; no items, ids or dates) as read-only JSON with a Copy button. It shows what is saved (also after a Save that fails validation, when the edit page reloads). `Helper/CustomObjectDefinitionExporter` builds it; the keys are `format` (`custom-object-definition`), `version` (1), `alias`, names, description, language, `type`, `category` (alias, title, color), the alias of the master object (for a relationship object; a master's child is not in the file, the plugin links it when the child is saved) and `customFields`.

On the other instance, **Importar definição** (menu next to New on the objects list) takes that JSON in two steps. **Conferir** shows what would be done and saves nothing; **Confirmar** applies the same text (read only by then; **Limpar** empties it to paste another). An alias that does not exist creates the object with all its fields in the sequence of the file's `order`. An alias that exists receives only the fields it does not have (by the field alias), after the last order it uses; what it has is never changed. A category that does not exist is created. Every problem of a file is listed at once and nothing is saved when there is one. The result is shown as alerts on the object page (success, warning when a required field, which every unique identifier is, was added to an object that has items, or a kept field has another type in the file). `Helper/CustomObjectDefinitionImporter`, `Controller/CustomObject/ImportDefinitionController`. Field aliases are unique in the whole instance: an alias taken by another object's field is refused, not renamed.

## Screens (Custom Objects admin)

- **Lists:** the three-dots menu of a row (objects and items) has "Detalhes" (the page), above Edit. A click on an object's name opens a modal with its fields (label, alias, type, required, unique identifier, default value, options); on an item's name, a modal with its values. Both modals have a footer with Editar (only with the edit permission), Detalhes (the page) and Fechar. Ctrl / cmd / shift click opens the page. Long texts without spaces break inside the cell.
- **View pages:** a "Back to <list>" button above the title (objects list for an object, items list for an item). The object page has the **Campos** tab (the same table as the modal) above the chart of items in time, and the **Clonar** tab. The object modal of the list has the same two tabs.
- **Options of a select / multiselect field:** the add button is below the list of values (`FormTheme/options_row.html.twig`; the block prefix of `OptionsType` is its own, so it does not depend on the patches bundle).
- **Row menus:** the Delete item of an object and of an item has the `danger` class and core's delete icon, so core's red hover reaches it like in the other lists.
- **Object edit form:** the edit and delete buttons of a field use core's ghost style (md size, like the form builder of core); delete asks for confirmation first (the field is only removed when the object is saved). The plugin JS is bundled in core's `app.js`: run `mautic:assets:generate` after deploying a change to `Assets/js`.

## Change log and settings tile

`CHANGELOG.md` lists what this fork changed, by day, from 2026-08-24 (newest first, one `## YYYY-MM-DD` block per day, one `- ` line per change, no versions; the upstream history is not in it). For it the plugin has a tile on Settings > Plugins (`Integration/CustomObjectsIntegration.php`: no keys, no features; born published; the plugin is turned off by the `custom_objects_enabled` parameter, not by its "Ativo" switch) and the modal shows the file in a "Histórico de mudanças" tab (`Helper/Changelog.php`, `Assets/js/plugin-changelog-tab.js`). After a deploy run `mautic:plugins:reload` and open Settings > Plugins once: the tile is created when the plugin version changes (0.0.31). Add a line to the block of the day to update the log; no code changes.

## Requirements

- Plugin supports PHP 7.1+.

## Documentation

See [Wiki](https://github.com/acquia/mc-cs-plugin-custom-objects/wiki)

## REST API guide

This document explains how to read and write Custom Objects/Custom Items in this Mautic
stack, using the real HTTP verbs, against a running instance.

Background reading (not repeated here): `wiki/custom-objects-plugin.md` (workspace wiki)
explains why this API surface looks the way it does, and
`wiki/custom-objects-seed.md` (workspace wiki) has the concrete field aliases
used in the examples below (`courses` object, fields `cursid`/`cursname`). That object was an
old seed and no longer exists in the local database (the current objects are `disciplina`,
`atividade` and `documents`): read the examples as the shape of the calls, and swap in a real
alias.

### The one thing to understand first

Custom Items are created, updated, and listed **directly**, against the Custom Object
itself — no contact required:

- `GET /api/customobjects/{objectAlias}/items` — list/search
- `POST /api/customobjects/{objectAlias}/items` — create only, `id` must be absent
- `PATCH /api/customobjects/{objectAlias}/items` — update only, `id` is required and must exist
- `PUT /api/customobjects/{objectAlias}/items` — upsert by `id` (Mautic core's own convention)
- `DELETE /api/customobjects/{objectAlias}/items/{itemId}` — delete one item by id (see its
  section below — it also unlinks, but only as a side effect, with a caveat)
- `DELETE /api/customobjects/{objectAlias}/items` — delete a batch, `{"data": [{"id": ...}]}`

These routes were purpose-built for this project and added to the plugin — there was no
REST resource for Custom Items before.

**POST and PATCH are strictly split — neither falls back to the other's behavior, and neither
upserts.** Send `id` on a POST entry and it's a 400, no item created. Omit `id` on a PATCH
entry, or send one that doesn't exist, and it's also a 400, no item touched. Custom Items have
no dedup/uniqueness (see `wiki/custom-objects-seed.md` (workspace wiki)), so a
silent fallback either way could produce a duplicate item or a confusingly-wrong update with
no error to catch it — these two verbs are for callers who already know, from their own
lookup, whether the item exists.

**PUT is the one exception, and matches Mautic core's own upsert convention** (see the PUT
section below): `id` present and found = update; `id` present but not found, or absent
entirely = create. Added for API callers who expect that standard Mautic behavior and don't
want to look up existence themselves first — it carries the same silent-fallback risk POST/
PATCH were built to avoid, so prefer POST/PATCH (with your own existence check) when you
control the caller and want that risk closed off; use PUT when you're building for callers
who expect the platform-standard verb and won't do that check.

**Linking an item to a contact is a separate concern**, and still only works one way: through
the **Contact API** (`/api/contacts/...`). The plugin listens for contact create/edit
requests and looks for an extra top-level `customObjects` key in the payload — saving that
way both writes the item(s) *and* auto-links them to the contact. There is no "link" call on
the direct routes above; use the contact route whenever you actually need the link, use the
direct routes for everything else. This also covers the common case now that items are
usually created unlinked: `PATCH /api/contacts/{id}/edit` with just the item's `id` (no
`attributes` needed) links an already-existing item to that contact — see "Linking an
already-existing item to a contact" under PATCH below.

The Custom Object type itself (the object and its fields) has its own routes: see "The Custom
Object type" below.

All examples use HTTP Basic Auth (the Mautic admin user/password from `.env`) and were run
against a live local stack (`docker compose up`) on 2026-08-25.

```bash
AUTH="admin:<password from .env>"
BASE="http://localhost:8080"
```

---

### GET — read

#### List/search all items on a Custom Object (no contact involved)

```bash
# every item on "courses"
curl -s -u "$AUTH" "$BASE/api/customobjects/courses/items"

# filtered by one field
curl -s -u "$AUTH" "$BASE/api/customobjects/courses/items?cursid=311-999"

# multiple filters = AND, not OR
curl -s -u "$AUTH" "$BASE/api/customobjects/disciplines/items?discclass=3110987_54321&discstart=2026-09-20"
```

Verified live:

```json
{"total":1,"items":[{"id":1,"name":"311-999 - nome do meu curso","attributes":{"cursname":"nome do meu curso","cursid":"311-999"}}]}
```

Every query parameter is `<fieldAlias>=<value>`, joined with AND. Every field type can be
filtered (same code as the item list search on the screen):

```json
{
  "text, textarea, email, phone, url, hidden, select-like": "EXACT value",
  "int, decimal, date, datetime": "exact, or a leading >, >=, <, <= (URL-encode > and <): ?progresso=%3E%3D0.5",
  "date": "matches the whole day; dd/mm/yyyy or yyyy-mm-dd",
  "multiselect": "matches when the item has that option"
}
```

Errors: `404` if the object alias or a filter field alias doesn't exist, `400` if the value is
invalid for the field's type (for example `?inicio=31/02/2026`), `401` with no/bad auth.
There is **no pagination**: any query parameter that is not a field alias (`limit`, `page`,
`name`...) answers `404`, and a call without filters returns every item of the object. Filter by
a field when the object is big. The field values of all the items are read in batch, so the
number of SQL queries does not grow with the number of items returned.

#### Read the items linked to one contact

Only useful when you specifically need to know *which items are linked to which contact* —
for plain item lookups, use the direct list/search above instead.

```bash
curl -s -u "$AUTH" "$BASE/api/contacts/3?includeCustomObjects=true"
```

Adds a `customObjects` key to the contact response:

```json
{
  "data": [
    {
      "id": 1,
      "alias": "courses",
      "data": [
        {
          "id": 5,
          "name": "curso teste doc",
          "attributes": { "cursname": "curso teste doc", "cursid": "DOC-001" }
        }
      ]
    }
  ]
}
```

#### Limitation: you can't filter by `name`

Every query string parameter is matched against the Custom Object's **custom fields**
(`cursid`, `cursname`, etc.) — not against the item's own built-in `name` column. Filtering
by `name` fails the same way an unknown field would:

```bash
curl -s -u "$AUTH" "$BASE/api/customobjects/courses/items?name=311-999%20-%20nome%20do%20meu%20curso"
```

Verified live: `HTTP 404`, `{"errors":[{"message":"Field 'name' not found on Custom Object 'courses'."}]}`.
If you need to find an item by its `name`, list/filter by a custom field instead (e.g.
`cursid`) and read `name` off the result — there's no way to search by `name` directly today.

---

### POST — create

#### Create item(s) directly (no contact involved)

```bash
curl -s -u "$AUTH" -X POST "$BASE/api/customobjects/courses/items" \
  -H "Content-Type: application/json" \
  -d '{
    "data": [
      {"name": "curso teste doc", "attributes": {"cursid": "DOC-001", "cursname": "curso teste doc"}}
    ]
  }'
```

`id` must be **absent** on every entry — POST only creates. Sending `id` here is a 400, not
an update (use PATCH below for that). A single request can carry multiple entries in `data`
to create several items at once — verified live with a 3-item batch, all 3 created correctly
in one call.

Verified live:

```json
{"total":1,"items":[{"id":11,"name":"curso teste doc2","attributes":{"cursname":"curso teste doc2","cursid":"DOC-002"}}]}
```

Sending `id` anyway:

```bash
curl -s -u "$AUTH" -X POST "$BASE/api/customobjects/courses/items" \
  -H "Content-Type: application/json" \
  -d '{"data": [{"id": 1, "name": "não deveria funcionar", "attributes": {"cursid": "X"}}]}'
# 400 {"errors":[{"message":"POST never accepts \"id\" — it only creates new items, it never updates one. Use PATCH to update."}]}
```

`name` is required on the item object — omitting it fails validation with `400`
`"This value should not be blank."` (see `wiki/custom-objects-seed.md` (workspace wiki)
for the same gotcha under the contact route, where the same missing-`name` case instead
surfaces as an unhelpful generic 500 — the direct endpoint reports it cleanly).

`name` has at most **191 characters**: a longer name fails with `400` `"This value is too long. It should have 191 characters or less."` (before, 192 to 255 characters answered a 500). Field values of type text have no such limit.

Errors: `404` if the object alias doesn't exist; `400` if the body has no `data` array, an
entry isn't an object, an entry has `id`, an `attributes` key isn't a real field alias on
that object, or an item fails validation; `401` with no/bad auth.

#### Create a contact and link a brand-new item in the same request

Only needed when you actually want the **link** to a contact — use this instead of (or in
addition to) the direct create above. Put a `customObjects.data` array alongside the normal
contact fields; an item with no `id` is always created new and gets auto-linked.

```bash
curl -s -u "$AUTH" -X POST "$BASE/api/contacts/new" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "api-doc-tester@email.com",
    "ra": "9998887776",
    "fullname": "API Doc Tester",
    "customObjects": {
      "data": [
        {
          "alias": "courses",
          "data": [
            {"name": "curso teste doc", "attributes": {"cursid": "DOC-001", "cursname": "curso teste doc"}}
          ]
        }
      ]
    }
  }'
```

Verified live: `HTTP 201`, contact created (`id: 3`), and the item existed right after,
both from `?includeCustomObjects=true` and from
`GET /api/customobjects/courses/items?cursid=DOC-001`.

Same `name`-required rule applies, but through this route a missing `name` fails with an
unhelpful generic 500 instead of a clean 400 (see
`wiki/custom-objects-seed.md` (workspace wiki)) — one more reason to prefer the
direct route above unless you specifically need the contact link.

---

### PATCH — partial update

#### Update item(s) directly (no contact involved)

Same path as the direct POST, same `data` array shape — but on PATCH, `id` is **required**
on every entry. PATCH only updates; it never creates. Send only the fields you're changing.

```bash
curl -s -u "$AUTH" -X PATCH "$BASE/api/customobjects/courses/items" \
  -H "Content-Type: application/json" \
  -d '{
    "data": [
      {"id": 11, "attributes": {"cursname": "curso teste doc (atualizado)"}}
    ]
  }'
```

Verified live: `HTTP 200`, and a follow-up search showed only `cursname` changed —
`cursid` and everything else on the item untouched.

If **any** entry in the `data` array is missing `id`, the whole request is rejected before
anything is written — a batch can't partially apply:

```bash
curl -s -u "$AUTH" -X PATCH "$BASE/api/customobjects/courses/items" \
  -H "Content-Type: application/json" \
  -d '{"data": [{"id": 11, "attributes": {"cursname": "ok"}}, {"attributes": {"cursname": "sem id"}}]}'
```

Verified live: `HTTP 400`,
`{"errors":[{"message":"PATCH requires \"id\" on every entry in \"data\" — it only updates existing items, it never creates one. Use POST to create."}]}`
— and the first entry's update (`id: 11`) was confirmed **not** applied either.

#### Batching multiple updates in one call

`data` can hold several entries with different `id`s — all are applied in the same request,
no need to call once per item. Verified live: created 3 items, then updated all 3 in a
single PATCH, each with a different `cursname` — all 3 came back correctly updated:

```bash
curl -s -u "$AUTH" -X PATCH "$BASE/api/customobjects/courses/items" \
  -H "Content-Type: application/json" \
  -d '{"data": [
    {"id": 13, "attributes": {"cursname": "curso a ATUALIZADO"}},
    {"id": 14, "attributes": {"cursname": "curso b ATUALIZADO"}},
    {"id": 15, "attributes": {"cursname": "curso c ATUALIZADO"}}
  ]}'
```

#### A batch is one DB transaction — a failure partway through rolls back everything already written

Applies to `POST`, `PATCH`, and `PUT` alike. If entry 3 of a 3-entry `data` array fails for
any reason (validation error, unknown field alias, `id` not found on `PATCH`, etc.), entries
1 and 2 — even though they'd have succeeded on their own and were already written to the DB
by the time entry 3 failed — get rolled back too. A successful response means every entry in
that call's `data` committed together; a `400` response means none of them did.

This wasn't true when the endpoint was first built — only the `id`-presence rule (checked in
a pass over the whole array before any item was saved) was atomic. Reproduced live: a 3-item
`POST` where items 1-2 were valid and item 3 was missing a required field returned `400`, but
a follow-up `GET` showed items 1-2 had been created anyway. Fixed by wrapping the whole save
loop in one `Doctrine\ORM\EntityManagerInterface` transaction
(`Controller/Api/CustomItemWriteApiController.php`) — re-ran the identical request afterward
and confirmed items 1-2 were no longer left behind.

#### An `id` that doesn't exist is a 400 on PATCH — use PUT if you want the create fallback

Mautic core's own entity API (`ApiBundle/Controller/CommonApiController.php::editEntityAction`,
used by e.g. `/api/contacts/{id}/edit`) has an upsert rule: if the given `id` doesn't exist,
`PATCH` 404s but `PUT` creates a new entity instead. **PATCH here matches that — it never
falls back to create.** POST also rejects `id` outright (see POST section above). If you want
the create-on-missing-id fallback, that's what `PUT` is for on this route — see the PUT
section further down:

```bash
curl -s -u "$AUTH" -X PATCH "$BASE/api/customobjects/courses/items" \
  -H "Content-Type: application/json" \
  -d '{"data": [{"id": 99999, "attributes": {"cursid": "X"}}]}'
# 400 {"errors":[{"message":"Custom Item with ID = 99999 was not found"}]}
```

Deliberate on PATCH: Custom Items have no dedup (see
`wiki/custom-objects-seed.md` (workspace wiki)), so a silent create-on-invalid-id
here could mask a bug in the caller's own id lookup (e.g. an ETL matching the wrong `id_turma`)
by quietly creating a stray item instead of failing loudly. If you'd rather have that fallback
than the error — e.g. you're not doing your own existence check first — use PUT instead.

##### Renaming an item (its `name`, not an attribute)

`name` is the item's own field, separate from `attributes` — send it alongside (or instead
of) `attributes`:

```bash
curl -s -u "$AUTH" -X PATCH "$BASE/api/customobjects/courses/items" \
  -H "Content-Type: application/json" \
  -d '{"data": [{"id": 11, "name": "DOC-002 - renomeado via patch direto"}]}'
```

Verified live: `HTTP 200`, `name` changed, `attributes` left exactly as they were.

#### Update an existing contact and/or its linked items

Only needed when the update should also touch the contact, or when you're relying on the
auto-link. Same `customObjects.data` shape, item `id` = update — but here `id` stays
optional per entry (you can mix an update and a create in the same call, one entry with
`id`, another without).

```bash
curl -s -u "$AUTH" -X PATCH "$BASE/api/contacts/3/edit" \
  -H "Content-Type: application/json" \
  -d '{
    "customObjects": {
      "data": [
        {
          "alias": "courses",
          "data": [
            {"id": 5, "attributes": {"cursname": "curso teste doc (atualizado)"}}
          ]
        }
      ]
    }
  }'
```

Verified live: `HTTP 200`, and a follow-up search showed only `cursname` changed —
`cursid` and everything else on the item untouched.

##### Linking an already-existing item to a contact

This is the piece that only this route can do — since items are now usually created
unlinked (via the direct POST above), linking one to a contact afterward needs its own call.
Pass just the item's `id`, no `attributes` or `name` needed — the plugin links on save
regardless of whether anything on the item actually changed:

```bash
curl -s -u "$AUTH" -X PATCH "$BASE/api/contacts/1/edit" \
  -H "Content-Type: application/json" \
  -d '{"customObjects": {"data": [{"alias": "courses", "data": [{"id": 12}]}]}}'
```

Verified live: item `id: 12` (created directly, moments earlier, with no link to anyone) was
confirmed **absent** from contact `1`'s `?includeCustomObjects=true` response before this
call, and **present** right after — nothing about the item itself changed, only the link was
created. The same call works to link an item that's already linked to a *different* contact
too (it doesn't move the link, it adds one — an item can be linked to more than one contact).

This is also how the seeded `courses` item got renamed to `"<cursid> - <cursname>"` (see
`wiki/custom-objects-seed.md` (workspace wiki)), same `name`-alongside-`attributes`
rule as the direct route above:

```bash
curl -s -u "$AUTH" -X PATCH "$BASE/api/contacts/1/edit" \
  -H "Content-Type: application/json" \
  -d '{
    "customObjects": {
      "data": [
        {
          "alias": "courses",
          "data": [
            {"id": 1, "name": "311-999 - nome do meu curso"}
          ]
        }
      ]
    }
  }'
```

Verified live: `HTTP 200`, item `id: 1`'s `name` changed to `"311-999 - nome do meu
curso"`, `attributes` (`cursid`, `cursname`) left exactly as they were — omitting
`attributes` from the payload leaves it untouched, only `name` is affected.

---

### PUT — full update

#### Upsert item(s) directly (no contact involved)

Same path/body shape as POST and PATCH — but PUT upserts by `id`, matching Mautic core's own
convention on its entity edit routes
(`ApiBundle/Controller/CommonApiController.php::editEntityAction`, used by e.g.
`/api/contacts/{id}/edit`): `id` present and found = update; `id` present but not found, or
absent entirely = create (a fresh `id` is assigned — you can't force a specific one).

```bash
# no id -> creates
curl -s -u "$AUTH" -X PUT "$BASE/api/customobjects/courses/items" \
  -H "Content-Type: application/json" \
  -d '{"data": [{"name": "PUT-CREATE-NOID", "attributes": {"cursid": "PUT-01", "cursname": "criado via put sem id"}}]}'
```

Verified live: `HTTP 200`, item created (`id: 17`).

```bash
# existing id -> updates
curl -s -u "$AUTH" -X PUT "$BASE/api/customobjects/courses/items" \
  -H "Content-Type: application/json" \
  -d '{"data": [{"id": 17, "attributes": {"cursname": "atualizado via put"}}]}'
```

Verified live: `HTTP 200`, `cursname` updated on item `17`, rest untouched.

```bash
# nonexistent id -> falls back to create, a *different* id comes back
curl -s -u "$AUTH" -X PUT "$BASE/api/customobjects/courses/items" \
  -H "Content-Type: application/json" \
  -d '{"data": [{"id": 88888, "name": "PUT-UPSERT-FALLBACK", "attributes": {"cursid": "PUT-02"}}]}'
```

Verified live: `HTTP 200`, a new item was created with `id: 18` — the requested `88888` was
never a real id, and does not appear anywhere in the response.

**This is the one place on the direct route where a wrong `id` is silently absorbed instead
of erroring.** POST and PATCH (above) deliberately reject a mismatched `id` instead, precisely
to avoid this kind of silent create — PUT exists as the explicit opt-in for callers who want
the standard Mautic upsert behavior anyway and are willing to accept that risk (e.g. a race
where an item is deleted between your own lookup and this call: with PATCH you'd get a clean
400 to catch and react to; with PUT you'd silently get a new item instead, with no error to
notice the race happened).

Errors: `404` if the object alias doesn't exist; `400` if the body has no `data` array, an
entry isn't an object, an `attributes` key isn't a real field alias on that object, or an item
fails validation; `401` with no/bad auth. No `id`-related 400 exists on this verb — that's the
whole point of upsert.

#### `PUT` via the contact route

`PUT` also works through the contact route, same URL as PATCH (`/api/contacts/{id}/edit`),
same `customObjects` payload shape, same result. Mautic's contact API routes both `PUT` and
`PATCH` to the same edit handler, and the plugin's hook doesn't distinguish between them —
it fires either way.

```bash
curl -s -u "$AUTH" -X PUT "$BASE/api/contacts/3/edit" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "api-doc-tester@email.com",
    "ra": "9998887776",
    "fullname": "API Doc Tester",
    "customObjects": {
      "data": [
        {
          "alias": "courses",
          "data": [
            {"id": 5, "attributes": {"cursname": "curso teste doc (via PUT)"}}
          ]
        }
      ]
    }
  }'
```

Verified live: `HTTP 200`, item `id: 5`'s `cursname` updated to "curso teste doc (via
PUT)" — identical behavior to PATCH for the Custom Items part. The difference between PUT
and PATCH that applies elsewhere in REST (full replace vs. partial merge) is not something
this plugin's hook implements; for Custom Items, always send only the `id` plus whatever
attributes you're changing, with either verb.

Renaming an item's `name` works the same way as under PATCH above — just swap the verb:

```bash
curl -s -u "$AUTH" -X PUT "$BASE/api/contacts/1/edit" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "wellington@email.com",
    "ra": "3110987654",
    "fullname": "Wellington Fonseca",
    "customObjects": {
      "data": [
        {
          "alias": "courses",
          "data": [
            {"id": 1, "name": "311-999 - nome do meu curso"}
          ]
        }
      ]
    }
  }'
```

Verified live: `HTTP 200`, `name` updated, `attributes` untouched — same result as PATCH.
Note PUT still needs the contact's other required fields (`ra`, `fullname`) in the body;
PATCH doesn't. Confirmed by sending the same request with only `customObjects` and no
contact fields: `HTTP 400`, `"ra: This field is required., fullname: This field is
required."` — the `customObjects` part is never even reached, and the item's `name` stayed
unchanged. PATCH is a true partial update for the contact itself; PUT is not.

---

### Unlinking an item from a contact — still not available as a real "unlink" via API

**Practical consequence: a link made by mistake through the API stays until someone fixes it
by hand.** The direct PATCH and the contact-hook PATCH both link on an `id` match with no
confirmation step and no API undo — link the wrong item to the wrong contact and there is no
follow-up API call that reverses it. Double-check the `id` (and which contact) before sending
a linking PATCH, especially in scripts.

There is still no route that only removes the *association* while keeping the item itself
intact and available to relink or to any other contact it's linked to. The plugin does have
an `unlinkEntity()` method (`Model/CustomItemModel.php`) and a route for it,
`POST /custom/item/{itemId}/unlink/{entityType}/{entityId}.json` — but it's registered under
the admin-UI route group (`/custom/item/...`, not `/api/...`), session-authenticated only.
Confirmed live:

```bash
curl -s -u "$AUTH" -X POST "$BASE/s/custom/item/1/unlink/contact/1.json" -D - -o /dev/null
# HTTP/1.1 302 Found
# Location: http://localhost:8080/s/login
```

Valid API Basic Auth credentials still redirect to the login page instead of unlinking
anything — item `1` was confirmed still linked to contact `1` afterward. Two ways to get an
unlink effect today:

- If the item is exclusive to one contact (never shared, current use case for
  `courses`/`disciplines`): use `DELETE` below — it removes the item's links as a side effect.
- If the item is or might be linked to more than one contact/company, and you need to remove
  only one of those links while the item keeps existing: use the admin UI (logged in as a
  user), or delete the row directly from `custom_item_xref_contact` in the database
  (`custom_item_id`, `contact_id`) — same direct-DB approach used elsewhere in this doc when
  the API has no route for something.

---

### DELETE — delete an item (and unlink it, as a side effect)

```bash
curl -s -u "$AUTH" -X DELETE "$BASE/api/customobjects/courses/items/29"

# several items in one call (one DB transaction)
curl -s -u "$AUTH" -X DELETE "$BASE/api/customobjects/documents/items" \
  -H "Content-Type: application/json" -d '{"data": [{"id": 13451}, {"id": 13452}]}'
# {"total":2,"deleted":[13451,13452],"alreadyGone":[]}
# an id that does not exist is NOT an error: it is listed in "alreadyGone" and the rest is deleted;
# an id repeated in the call is deleted once (it never shows in "alreadyGone");
# 404 and NOTHING deleted if an id belongs to another Custom Object;
# 400 if the body has no "data" array, an entry has no "id", or an "id" is not a positive integer
```

The single and the batch route answer the same shape, so a caller reads one format:
`{"total": N, "deleted": [ids], "alreadyGone": [ids that did not exist]}`. A DELETE that finds the
item already gone is a success: the item is not there, which is what the caller wanted. Read
`alreadyGone` to catch a wrong id.

Added `DELETE /api/customobjects/{objectAlias}/items/{itemId}` — before this, there was no way
to delete a Custom Item through the API at all (not through the contact route, not through the
direct `/api/customobjects/{alias}/items` route, and the only existing delete route,
`/custom/object/{objectId}/item/delete/{itemId}`, is admin-UI, session-authenticated, under
`/s`, same login-redirect behavior as the unlink route above).

Verified live: `HTTP 200`, `{"total": 1, "deleted": [29], "alreadyGone": []}`, item confirmed gone from a follow-up
`GET /api/customobjects/courses/items`.

```json
{
  "errors": "404 if the object alias doesn't exist; 200 with the id in "alreadyGone" if the item id doesn't exist; 404 if the item exists but belongs to a different Custom Object than the one in the URL; 401 with no/bad auth",
  "no body": "DELETE takes no request body — the item id is in the URL"
}
```

**This also removes the item's contact/company links, because the database foreign key
(`custom_item_xref_contact.custom_item_id`, and the equivalent company/custom-item xref
tables) cascades on delete** — deleting the item deletes its xref rows automatically. Verified
live end to end: linked a fresh item to contact `1` via the contact PATCH hook, confirmed the
link with `GET /api/contacts/1?includeCustomObjects=true`, deleted the item through this
route, and it was gone from that same contact response afterward.

**The caveat:** this is only an unlink *as a side effect* of a permanent delete (no
trash/recovery) — not a real, targeted unlink. If an item is ever linked to more than one
contact or company, `DELETE` removes it — and every one of its links — for all of them at
once, not just the one you meant to detach. It's only safe to use this as "unlink" while each
item stays exclusive to one contact, which is the current design for `courses`/`disciplines`.
If items start being shared across contacts, use the workaround in the "Unlinking" section
above instead (admin UI or direct DB row delete on `custom_item_xref_contact`) so the item and
its other links survive.

Deleting a contact (`DELETE /api/contacts/{id}/delete`, a separate, pre-existing API call)
still does **not** delete its linked Custom Items — verified live previously: after deleting a
contact, its items were still returned by `GET /api/customobjects/courses/items`, just no
longer linked to any contact. `DELETE` on the item route is the only way to remove the item
itself.

---

### The Custom Object type — create, read and delete the object

The object (not its items) is created, read and deleted with the same Basic Auth. The body of the
create is the JSON of the Clone tab (`format` `custom-object-definition`, `version` 1), with an
optional `isPublished` (default `true`). The rules are the ones of the Clone tab.

```bash
curl -s -u "$AUTH" -X POST "$BASE/api/customobjects" -H 'Content-Type: application/json' -d '{
  "format": "custom-object-definition", "version": 1,
  "alias": "prova", "nameSingular": "Prova", "namePlural": "Provas", "type": 0,
  "customFields": [{"alias": "provanome", "label": "Nome", "type": "text", "order": 0}]
}'
# 201 {"id":7,"alias":"prova","customFields":[{"id":31,"alias":"provanome","type":"text"}]}

curl -s -u "$AUTH" "$BASE/api/customobjects/prova"            # the same JSON + id of the object and of each field
curl -s -u "$AUTH" -X DELETE "$BASE/api/customobjects/prova"   # {"alias":"prova","deleted":true,"alreadyGone":false}
```

- Create: `400` with the list of every problem of the file (nothing is saved); `409` when the alias
  already exists (nothing is saved, the fields of the file are NOT added); `403` without the permission to create.
  Risk: the alias index is not unique, so two POSTs of the same new alias at the same moment can both succeed.
- A field alias is unique in the whole instance and must be clean (lower case, letters, digits, hyphen).
- Delete: its fields and its items go with it. An alias that does not exist is `200` with `"alreadyGone": true`
  (after the permission check: `403` without the permission, for any alias).
  An object used by the filter of a segment is `409` with `segments: [{id, name}]`. A master object that has a child
  is `409` naming the child: delete the child first. Campaigns that use its fields are not checked.

---

### Quick reference

| Verb | Endpoint | What it does |
|---|---|---|
| GET | `/api/customobjects/{alias}/items[?field=value...]` | List/search items on one Custom Object, no contact — `field` must be a custom field alias, **not** `name` |
| POST | `/api/customobjects/{alias}/items` (`data[]`, `id` forbidden) | Create item(s) directly, no contact — create-only, 400 if `id` present on any entry |
| PATCH | `/api/customobjects/{alias}/items` (`data[]`, `id` required) | Update item(s) directly, no contact — update-only, 400 if `id` missing **or** doesn't exist on any entry (no upsert, unlike core's PUT) |
| PUT | `/api/customobjects/{alias}/items` (`data[]`, `id` optional) | Upsert item(s) directly, no contact — `id` found = update, `id` missing/not-found = create (fresh id assigned) |
| DELETE | `/api/customobjects/{alias}/items/{itemId}` | Delete one item by id — also removes its contact/company links via DB cascade, but permanently and for every link at once (see caveat above); not a targeted unlink |
| DELETE | `/api/customobjects/{alias}/items` (`data[]`, `id` required) | Delete several items in one transaction — an unknown id is listed in `alreadyGone`, a foreign id cancels the whole batch (404) |
| POST | `/api/customobjects` (Clone tab JSON) | Create a Custom Object type with its fields — 201 with the ids, 400 with all errors, 409 if the alias exists |
| GET | `/api/customobjects/{alias}` | Read the type (Clone tab JSON + ids) |
| DELETE | `/api/customobjects/{alias}` | Delete the type, its fields and its items — idempotent (`alreadyGone`), 409 if a segment filter uses it or it has a child object |
| GET | `/api/contacts/{id}?includeCustomObjects=true` | Read items linked to one contact |
| POST | `/api/contacts/new` (+ `customObjects.data`, no item `id`) | Create a contact and/or new Custom Item(s), auto-linked |
| PATCH/PUT | `/api/contacts/{id}/edit` (+ `customObjects.data`, item `id` = update) | Update contact and/or Custom Item(s), keeps/creates the link |
| PATCH/PUT | `/api/contacts/{id}/edit` (+ `customObjects.data`, item `id` only, no `attributes`) | Link an already-existing item to this contact, nothing else changed |
| *(none)* | *(none)* | Targeted unlink (item survives, only the association is removed) — not exposed via API, admin UI or direct DB only |

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
