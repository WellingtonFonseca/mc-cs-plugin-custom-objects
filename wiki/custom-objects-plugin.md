# Custom Objects plugin (external fork)

`CustomObjectsBundle` adds Custom Objects, Custom Items and Custom Fields to Mautic, so contacts and companies can link to arbitrary data. It is not part of the `mautic/` repo: own git repo, sibling directory, bind-mounted into the three Mautic containers ([../wiki/docker-mautic5.md](../../wiki/docker-mautic5.md)).

```json
{
  "upstream": "https://github.com/acquia/mc-cs-plugin-custom-objects",
  "fork": "https://github.com/WellingtonFonseca/mc-cs-plugin-custom-objects",
  "local clone": "/home/paperclip/project/mc-cs-plugin-custom-objects-5",
  "mount": "docroot/plugins/CustomObjectsBundle (never plugins/...: see ../wiki/docker-mautic5.md)",
  "branch in use": "mautic5-base (also on origin), tracks upstream/5.x. origin/HEAD still points at the old staging branch: do not use it.",
  "why not staging": "staging predates Mautic 5: it uses Symfony's removed Templating component (.html.php views) and fails with fatal errors. upstream/5.x already has the full Twig port.",
  "version": "0.0.32 (Config/config.php)"
}
```

Install a new plugin with `mautic:plugins:install`; a new version (migrations) with `mautic:plugins:reload` (`Migrations/Version_0_0_NN.php`; Mautic re-checks every migration on each update). Clear the cache after editing plugin code ([../wiki/docker-mautic5.md](../../wiki/docker-mautic5.md)).

## What the live scripts cover instead of Tests/Functional

The 31 files of `Tests/Functional` need the Mautic monorepo and never run in this stack. The live scripts of `scripts/5/` cover the same ground through the real HTTP API and commands: the object TYPE API (test-objects-api.sh: create, view, delete, 400 / 409, DELETE with items and segments) and the items API (test-items-api.sh: POST / PATCH / PUT / DELETE, 400 / 404, atomic batches), the contact hook (test-contact-hook.sh), segments (test-segments-custom-fields.sh, test-segment-empty.sh, test-segment-filter-operators.sh, test-resolver-merged-filters.sh), decimal (test-decimal-field.sh), export, import and the campaign events. Not covered: the Custom Object / field forms, the item list and lookup screens, the relation between items (Tests/Functional/Segment/Query/Filter/CustomItemRelationQueryBuilderTest) and API Platform (not installed).

## Running the plugin's own tests

The plugin's `phpunit.xml` needs the Mautic monorepo. In this stack use the `Tests/Infection/` folder of the plugin (unit suite only, `Tests/Functional` never runs here), from the plugin folder inside the web container:

```json
{
  "unit tests": "docker exec --user www-data mautic-mautic_web-1 sh -c \"cd /var/www/html/docroot/plugins/CustomObjectsBundle && php /var/www/html/vendor/bin/phpunit --do-not-cache-result -c Tests/Infection/phpunit.xml\". AbstractFieldControllerTest is excluded (abstract: PHPUnit warns and Infection stops on a warning).",
  "coverage": "php -d pcov.enabled=1 /var/www/html/vendor/bin/phpunit --do-not-cache-result -c Tests/Infection/phpunit.xml --coverage-text (PCOV, see ../wiki/tests-mutation-and-coverage.md)",
  "mutation testing": "infection --configuration=Tests/Infection/infection.json5 --threads=8 --no-progress --initial-tests-php-options='-d pcov.enabled=1' --min-msi=0 [--filter=File.php]; about a minute for the whole plugin; the report is /tmp/infection-co/infection.log. Reads Model, EventListener, Helper, Segment, CustomFieldType, Provider, DTO and Repository.",
  "why a bootstrap": "Tests/Infection/bootstrap.php registers the project autoloader WITHOUT nikic/php-parser: Infection brings its own and loading both is a fatal error. Without it Infection cannot reflect the classes that extend Mautic's (models, query builders) and stops with 'Class ... does not exist'. Paths in infection.json5 and phpunit.xml are relative to the file; the bootstrap path of infection.json5 is relative to the plugin folder.",
  "traps": "The folder is under Tests/ on purpose: the plugin's Config/services.php loads EVERY .php file under the plugin root into the container (excluding Tests/ and a few others), so a bootstrap anywhere else is executed by cache:clear and the plugin-boots test (memory exhausted by the classmap). A PHP warning in any unit test stops Infection (it runs PHPUnit with failOnWarning). Do not commit Tests/Infection/.phpunit.result.cache (cacheResult is off). LeadRepository::exists does not exist in Mautic 5.2, so CustomItemImportModelTest is skipped here and the warning about unknown contacts of an import is unreachable; CustomItemImportModelFlowTest covers the rest of the import."
}
```

What is left escaped is noise (empty table prefix, casts, event priorities, JS/SQL fragments checked live) or needs a real database (the `from`/`exists` of the full-text subselects, the select/from of ReportSubscriber::onReportGenerate).

## Where to read

Read only the file of the area you touch:

- [wiki/custom-objects-api.md](custom-objects-api.md) — The `/api/customobjects/{alias}/items` routes (list/search, create, update, upsert, delete, batch delete), the list/search filter semantics and the field types (including `decimal`).
- [wiki/custom-objects-item-list.md](custom-objects-item-list.md) — The item list screen: search by field (alias or label), columns, the item view modal (click on the name) and their traps.
- [wiki/custom-objects-status-column.md](custom-objects-status-column.md) — The Status column (icon badge, Ativar / Desativar in the row menu) of the object list.
- [wiki/custom-objects-data.md](custom-objects-data.md) — Indexes and migrations, child (relationship) objects and item links, and the export and import of items.
- [wiki/custom-objects-segments-and-campaigns.md](custom-objects-segments-and-campaigns.md) — The segment filter `in` / `!in` on text fields and its values route, the campaign condition "<object> fields" and the link / unlink action, and `SegmentItemResolver` (which items a segment points at; shared with n8n-dispatch and the Wait action).

- [wiki/custom-objects-seed.md](custom-objects-seed.md) — The seed objects of the local database and the gotchas of the seed scripts.
- [wiki/custom-objects-merged-segment-filters.md](custom-objects-merged-segment-filters.md) — Segment criteria on Custom Object fields matched on the SAME item (merge filter): settings, how the merged query is built, n8n variables.
- [wiki/custom-objects-merged-segment-testing.md](custom-objects-merged-segment-testing.md) — The operators of the segment screen, the `disciplina` test data, the tests and the deploy checklist of the merge filter.
