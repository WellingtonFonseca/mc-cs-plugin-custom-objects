# Custom Object merge filter: operators, test data, tests and deploy checklist

The operators the segment screen offers, the permanent test data, how to run the tests and the checklist for a new environment. Padrão: [testes.md](testes.md) (how to run the unit tests, PHPUnit 10, functional tests, SQL log). What must not break: the seed is rebuilt by a script and every test finds its ids by alias. How the merge works: wiki/custom-objects-merged-segment-filters.md.

## Operators the segment screen offers (pinned by test-segment-filter-operators.sh)

```json
{
  "text, email, phone, url, textarea": "=, !=, empty, !empty, like, !like, startsWith, endsWith, contains",
  "int, decimal, date, datetime": "=, !=, gt, gte, lt, lte, empty, !empty (no between: OperatorOptions hides between and notBetween for every field type)",
  "select, country": "=, !=, empty, !empty",
  "multiselect": "empty, !empty, in, !in",
  "item name (cmo_)": "= != empty !empty like !like regexp !regexp startsWith endsWith contains",
  "hidden": "not offered",
  "date filter JSON that works": "{\"object\":\"custom_object\",\"type\":\"date\",\"field\":\"cmf_<id>\",\"operator\":\"lt\",\"properties\":{\"filter\":\"today\"},\"filter\":\"today\"} (properties.filter is the value that counts: ../wiki/segment-custom-object-filter-test.md)"
}
```

`checkbox` and `radio` exist as classes but are commented out in the plugin's `config.php`: they cannot be created or tested.

## Test data (permanent, rebuilt by a script)

`scripts/5/seed-segment-merge-test.sh` is destructive and safe to re-run: it deletes ALL contacts (API) and ALL Custom Items (SQL, FKs cascade), then recreates the data below and the two segments. Dates are computed from today, so the scenarios hold on any day. Custom field ids change if a field is recreated, so every script looks them up by alias. Segment ids change on every run; tests find them by alias.

```json
{
  "object": "disciplina",
  "fields": {
    "nome1": "text", "inicio": "date", "fim": "date", "tipo": "multiselect (grad, pos, extensao)",
    "atual": "select (sim, nao)", "posicao": "int", "progresso": "decimal", "momento": "datetime",
    "pais": "country", "email": "email", "telefone": "phone", "site": "url", "descricao": "textarea", "codigo": "hidden"
  },
  "values": "each field cycles through its own list per discipline (lists at the top of the seed script); some items are left empty on purpose to test 'empty'; momento is the start date plus an hour.",
  "kinds of discipline": {
    "P (past)": "inicio today-60, fim today-30",
    "C (running)": "inicio today-10, fim today+20",
    "F (future)": "inicio today+10, fim today+40",
    "jitter": "each contact shifts its dates by n%4 days, without changing the kind"
  },
  "contacts": "aluno01@example.com to aluno10@example.com. The seed script still sends ra and fullname, fields that no longer exist (../wiki/contact-api-and-fields.md): they are dropped, the contact is found by email.",
  "plan (2 disciplines each)": {"01": "CF", "02": "PP", "03": "CC", "04": "PC", "05": "FF", "06": "PF", "07": "FP", "08": "CP", "09": "PP", "10": "FC"},
  "segments": {
    "Disciplina em andamento (alias disciplina-em-andamento)": "inicio < today AND fim > today. Expected members with merge on: 01, 03, 04, 08, 10",
    "Disciplina encerrada (alias disciplina-encerrada)": "inicio < today AND fim < today. Expected members with merge on: 02, 04, 06, 07, 08, 09"
  },
  "contacts that prove the merge": "06 (P+F) and 07 (F+P): with the merge off they enter 'em andamento' (one item matches each criterion); with it on they must stay out",
  "behavior check with two contacts": "segment 'inicio < today AND fim > today': contact A with item 1 fully past and item 2 fully future, contact B with one running item. Merge off: A and B both enter. Merge on with the operator bug: B stays out and A enters. Merge on and correct: A stays out, B enters."
}
```

Scripts in `mautic/scripts`:

```json
{
  "add-test-fields.sh": "creates the 7 fields added after nome1/inicio/fim/tipo/atual/posicao/progresso. Re-runnable. It persists ONLY the new fields: saving the whole object through CustomObjectModel::save() once wiped the options of the select and multiselect fields.",
  "seed-segment-merge-test.sh": "wipes contacts and items, recreates the 10 contacts, 20 items and the two segments. It creates segments through the API: with the required segment prefix (../mautic-patches-bundle-5/wiki/mautic-patches-bundle.md) it needs to send `prefix`.",
  "test-resolver-merged-filters.sh": "live test of the n8n CustomObjectVariableResolver: 120 cases over both base segments (every operator of every field type the screen offers, mixes on one item, an OR group). Own data per run: it needs NO seed; `scripts/5/lib/own-disciplina-data.sh` makes, under the RUN_ID, an object like `disciplina` (14 fields, 10 contacts, 20 items) and the two base segments, and the EXIT trap removes them (it never touches the seeded `disciplina`, so two copies run together). Expected items are computed from the rows of that data. Fails when it is incomplete (fewer than 20 items). MERGE_FILTER=0 runs the older per-condition path (also 120 of 120).",
  "test-resolver-target-fields.sh": "the same resolver with every field type as the TARGET of the variable (the value that goes into the message): 154 checks, on the same own data per run (lib/own-disciplina-data.sh). Accepts MERGE_FILTER=0.",
  "test-segment-filter-operators.sh": "pins the operators above and that a hidden field is not offered"
}
```

## How to test

```json
{
  "default config without env vars": "run the console with env -u MAUTIC_CUSTOM_OBJECT_MERGE_FILTER -u MAUTIC_CUSTOM_OBJECT_ITEM_VALUE_TO_CONTACT_RELATION_LIMIT (as www-data, after a cache:clear in the same state). The effective values must read true and 0 from the plugin default alone.",
  "a test that proves the operator bug needs both settings": "CustomObjectMergedFilterQueryBuilderTest sets both settings itself and counts contacts on one text field: it cannot tell operators apart and proves nothing about the environment's config. Use the live scripts for that."
}
```

## New environment checklist

1. Deploy the plugin (`mautic5-base`) and n8n-dispatch together; `git pull` in `plugins/CustomObjectsBundle`.
2. `sudo -u www-data php bin/console cache:clear`.
3. Check `config/local.php` and `.env`: if `custom_object_merge_filter` or the relation limit is saved there, set `true` and `0` or remove it (it beats the plugin default).
4. Int fields: unfilled numbers are NULL now; rows already saved as 0 stay 0. Decide whether those zeros should become empty.
5. Reprocess the affected segments: `mautic:segments:update --list-id=N` (or wait for the cron). Segments using 'does not contain', 'not equal' on the item name, a multiselect, or empty/not empty on a number change membership: check them before sending a campaign.
6. Validate by behavior with two contacts and a segment 'inicio < today AND fim > today' (see "behavior check with two contacts" under Test data).
