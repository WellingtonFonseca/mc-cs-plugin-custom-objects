categoria: stack
regras:
- Install a new plugin with `mautic:plugins:install`; a new version (migrations) with `mautic:plugins:reload` (`Migrations/Version_0_0_NN.php`; Mautic re-checks every migration on each update). `plugins:install` does NOT create new tables on an existing install ('0 updated').
- Clear the cache after editing plugin code ([../../wiki/docker-mautic5.md](../../wiki/docker-mautic5.md)); read the `cache:clear` output: unit tests and live scripts do not catch container errors.
- Autowiring trap (breaks `cache:clear`): Mautic autowires every class under `Helper/` (only `DTO/`, `Entity/`, `Event/`, `Exception/`... are excluded: `MauticCoreExtension::DEFAULT_EXCLUDES`). A value object with a `string` constructor argument there fails with "Cannot autowire service ... argument $alias is type-hinted string": put it in `DTO/` (`ItemSearchTerm`, `ParsedItemSearch`, the import result object).
- `Config/services.php` loads every class under the plugin as a service (exclusions above).
- The plugin JS is bundled into core's `media/js/app.js`: after editing `Assets/js` run `mautic:assets:generate` (as www-data), or the browser keeps the old script.
- Every string column of the entities is VARCHAR(191) (utf8mb4 index limit); every `Assert\Length` uses `StringColumn::LENGTH` (`Entity/StringColumn.php`). A limit of 255 passes the validation and fails in the database (500 instead of 400 / form error). A new string column needs its constraint at `StringColumn::LENGTH`.
- Translations: alert and page texts are in `messages.ini` (en_US and pt_BR); `addFlashMessage` texts would need `flashes.ini` and are not used.
- A private controller method called `renderForm` collides with `AbstractController::renderForm`: name it differently (`renderImportForm`).
- A field alias is unique in the WHOLE instance (`CustomFieldRepository::isAliasUnique` checks every object; `CustomFieldModel` renames a taken alias by adding a number). Mautic strips underscores from the alias ('curs_id' becomes 'cursid'): always read the real aliases.
