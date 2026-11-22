# Custom Objects: clone an object to another instance (definition JSON)

The Clone tab shows the definition of an object (the object and its fields, no items) as read-only JSON; the import page on another instance creates the object from it, or adds the fields an existing one lacks. Nothing here moves items. What must not break: the JSON keeps no ids, a check never saves, and an existing object is never changed beyond new fields.

## The JSON

```json
{
  "where": "Helper/CustomObjectDefinitionExporter (toArray / toJson). Shown by CustomObject/_definition.html.twig in the Clone tab of the object page (ViewController::viewAction), of the edit page (FormController::editAction) and of the list modal (ViewController::detailsAction). Read only: a <pre>, never a form field; it shows what is SAVED (Salvar reloads the edit page, so it updates). SaveController builds the JSON BEFORE the submit (the submit changes the entity) and only for an existing object, so a Salvar that fails validation still shows the tab with the saved definition; form.html.twig hides the tab when definitionJson is empty, so every render of form.html.twig must pass it. The Copy button reads the text of the <pre>.",
  "keys": "format 'custom-object-definition', version 1, alias, nameSingular, namePlural, description, language, type (0 master, 1 relationship), category {alias, title, color} or null, masterObjectAlias, customFields[] {alias, label, type, order, required, defaultValue, isPublished, isUniqueIdentifier, showInCustomObjectDetailList, showInContactDetailList, params, options[] {label, value, order}}",
  "left out": "ids, uuids, dates, creators. The master of a relationship object is named by alias. The CHILD of a master object is not in the file: CustomObjectPostSaveSubscriber sets master.relationshipObject when the child is saved, and the column has a unique index, so a copy of a master that named the original's child failed with a duplicate key. Import the master first, then the child.",
  "default value": "CustomField::getStoredDefaultValue(): the stored string. getDefaultValue() is transformed for the type (a date becomes an object) and is not JSON.",
  "tab name": "translation custom.object.definition.tab: 'Clonar' (pt_BR), 'Clone' (en_US)"
}
```

## The import

```json
{
  "where": "menu next to New on the objects list ('Importar definição', CustomObjectButtonSubscriber, permission canCreate). Pages: Controller/CustomObject/ImportDefinitionController (GET and POST /s/custom/object/import-definition), Form/Type/CustomObjectDefinitionImportType, CustomObject/import_definition.html.twig. The logic: Helper/CustomObjectDefinitionImporter::import(json, apply).",
  "two steps": "Conferir posts with the hidden `confirmed` 0: the importer runs with apply=false (nothing saved, no category created) and the page lists what WOULD be done (Helper/CustomObjectDefinitionImportAlerts with the '.preview' texts). The page then comes back with the same text read only and Confirmar (confirmed 1), which applies it. The confirm validates everything again.",
  "buttons": "Limpar (pre_extra_buttons, before Confirmar): empties the text, makes it editable, hides the check and Confirmar and brings Conferir back, all in the browser. Cancelar goes to the objects list. A check with nothing to add keeps the save button hidden (class hide) so that Limpar can bring it back as Conferir. Core copies the buttons of a form into the page header and the copy disables its siblings and spins its icon: Limpar has btn-dnd and btn-nospin to avoid both.",
  "new alias": "creates the object with every field; order numbers 0, 1, 2... in the sequence of the file's `order` (the value itself is only a sort key). Master and relationship objects must exist by alias. The category is found by alias (bundle global) or created with the object.",
  "existing alias": "only the fields whose alias the object lacks are added, after the last order it uses. Fields it has are never changed (a different type is a warning). The object data of the file (names, type, category, related objects) is ignored. Needs canEdit and an object nobody has open (isLocked).",
  "errors": "every problem is collected and thrown together (DefinitionImportException): bad JSON or format or version, missing keys, an alias that is not in the clean form (CustomObjectModel::cleanAlias, else the model would rename it), a relationship object without masterObjectAlias, repeated field alias, unknown field type, missing master, a new category without the permission category:categories:create, no permission to edit the object, locked, and the constraints of the entities (ValidatorInterface on the object and on each new field: lengths, blank values, the default value of its type). Nothing is saved if there is one. The apply is ONE database transaction (the category, then the object): a failure rolls back and is an error of the file, not a 500 with an orphan category.",
  "form token": "the POST needs the form's token (isSubmitted and isValid): a page of another site cannot post an import for a logged-in admin.",
  "result": "shown on the object page as alerts (Bootstrap success / info / warning) from the session flash bag 'custom_object_import_alerts', read once by ViewController::viewAction and rendered in _detail_left_section.html.twig between the page actions and the details. Not the toast of addFlashMessage.",
  "order of the alerts": "created or added (success, the added fields as a list), category created (info), required fields added to an object with items (warning, list), kept fields with another type (warning, list). Fields the object already had are not mentioned."
}
```

## Traps

```json
{
  "field alias is unique in the WHOLE instance": "CustomFieldRepository::isAliasUnique checks every object, and CustomFieldModel renames a taken alias by adding a number. A copy must be faithful, so the importer refuses an alias another object's field has (for an existing object, its own fields are skipped, not refused).",
  "a unique identifier is required": "CustomField::setRequired() returns true for a unique identifier field. Adding one (or any required field) to an object with items is accepted, because the plugin only checks turning an EXISTING field unique, but the old items, with it empty, are not saved again until it has a value (the API answers 400 'Field ... is required'). The API does not refuse a repeated value. The importer warns (required_with_items) when it adds such a field to an object that has items.",
  "services": "Config/services.php loads every class under the plugin as a service: the result object lives in DTO/ (excluded), not in Helper/.",
  "translations": "the alert and page texts are in messages.ini; addFlashMessage texts would need flashes.ini and are not used.",
  "controller helper name": "a private method called renderForm collides with AbstractController::renderForm: the controller's is renderImportForm."
}
```

## Tests

PHPUnit: `Tests/Unit/Helper/CustomObjectDefinitionExporterTest`, `...ImporterTest` (dry run, existing object, category, errors), `...ImportAlertsTest`; Infection on the three (the escaped mutants left are casts and defaults that do not change the result). Browser specs: `custom-object-definition-tab.spec.js` (the tab, the copy), `custom-object-import-definition.spec.js` (button, creation in order, existing object, check without saving, Limpar and Cancelar, category, unique identifier on an object with items; own aliases, removed at the end). The seeded object `disciplina` only gives the JSON to copy.
