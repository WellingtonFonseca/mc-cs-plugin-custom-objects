# Custom Objects: seed data and gotchas

## Objects in the local database

```json
{
  "disciplina": "master object (fields nome1, inicio, fim, tipo, atual, posicao, progresso, momento, pais, email, telefone, site, descricao, codigo). 10 fixed contacts, rebuilt by scripts/5/seed-segment-merge-test.sh. See wiki/custom-objects-merged-segment-testing.md.",
  "atividade": "child (relationship) object of disciplina: fields nome, nota, entrega, status. Created by scripts/5/seed-custom-objects-children.sh (idempotent). See wiki/custom-objects-plugin.md, 'Child objects'.",
  "documents": "master object with ONE text field, label doc_code, alias doccode. scripts/5/seed-documents.sh creates it (logs in like a browser and posts the admin form) and 5 items (Documento 1..5, doccode DOC-001..DOC-005) linked to contact 196 by PATCH /api/contacts/196/edit. Idempotent: object, field and items (looked up by doccode) are not duplicated. It shows that the contact page's Custom Object tabs (filter, link existing, link new) exist for EVERY master object: ContactTabSubscriber loops over all of them, with no alias in the code. Child objects get no tab on the contact."
}
```

The old `courses` and `disciplines` objects (and the seed contact found by `ra`) do not exist any more, and their seed scripts were removed.

## How seeding works

A Custom Object TYPE (its fields) has no API: it is created in the admin UI, or by posting the admin form like a browser (the seed scripts do this: create the object, then a second post to `/s/custom/object/save/{id}` that carries the fields). Items are written through the API, either directly (`/api/customobjects/{alias}/items`, see wiki/custom-objects-plugin.md) or through the contact hook: `PATCH /api/contacts/{id}/edit` with `customObjects.data` (object by alias, item by its attributes), which also links the item to the contact. Update an item by sending its `id`; to change only the name, omit `attributes`.

## Gotchas

```json
{
  "field alias is not the label": "Mautic strips underscores when it makes the alias: label 'curs_id' became 'cursid', 'doc_code' became 'doccode', 'data_inicio' became 'datainicio'. Aliases are unique across ALL objects: a clash gets a suffix (nome became nome12). Always read the real aliases: SELECT id, custom_object_id, alias, label FROM custom_field;",
  "item needs a top-level name": "the payload item is {\"name\": \"...\", \"attributes\": {...}}. Without name, validation fails ('This value should not be blank') inside CustomItemModel::save(), and the API reports a generic 500 that hides the cause. The real error is in var/logs/prod-*.php (filter out the mautic_worker's recurring 'SyncTransport' lines).",
  "no dedup for items": "unlike contact/company fields, a Custom Field has no isUniqueIdentifier. The create payload without 'id' called twice makes two items. Seed scripts look up an existing item by a value (query the custom_item / custom_field_value_text tables, or GET /api/customobjects/{alias}/items?field=value) and send its id to update instead.",
  "silent no-op": "a customObjects write that answers 200 and changes nothing means the plugin's API listener is not loaded: the cache was not cleared after the container was recreated (../wiki/docker-mautic5.md). Check that GET .../{id}?includeCustomObjects=true returns data before suspecting the payload.",
  "name convention": "items are named '<key> - <name>' (for example '311-999 - nome do curso', '<discclass> - <discname>'), so the name alone says what the item is.",
  "segments seed": "seed-segments.sh makes one segment per field type (cf<type>) plus disciplina-em-andamento and disciplina-encerrada."
}
```
