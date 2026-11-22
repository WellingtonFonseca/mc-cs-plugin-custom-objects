# Custom Objects: the Status column of the object list

The Custom Objects list shows the status of a row as an icon badge in a Status column, and "Desativar" / "Ativar" as the first item of the row menu, the same pattern as the lists of MauticPatchesBundle and N8nDispatchBundle. The items list does NOT have it (see `items list`). What must not break: core's toggle icon of the row stays in the page (hidden), the badge and the menu item follow it, and the plugin works without the other plugins.

## What it does

```json
{
  "where": "Resources/views/Common/_status_badge.html.twig (the badge, printed by CustomObject/_list.html.twig), Assets/js/list-status.js, Assets/css/custom-objects.css (`.customobjects-*`), EventListener/ListStatusSubscriber.php (the menu item). The script is loaded on every admin page by AssetsSubscriber.",
  "column": "`th.col-custom-status` (plain header, key custom.object.status.column) and `td.col-custom-status` as the SECOND column, after the menu column., the table is #custom-objects-table.",
  "badge": "`span.customobjects-badge.customobjects-status`: a 22px circle with `ri-play-fill` on green or `ri-stop-fill` on red; the word (mautic.core.form.active / inactive) is `.customobjects-status__text`, the title and the aria-label. Hover: max-width 22px -> 140px, the word fades in, the column widens and the browser lays the table out (the badge is alone in its cell and grows in the flow, CSS only). Orange spinner while core works.",
  "status follows core": "core's `publishstatus_icon` stays in the name cell (`i[class*=-publish-icon]`, hidden by CSS); the script (MutationObserver, once per frame) brings the badge and the menu item to its data-status, `busy` while it has `ri-spin`. The menu item CLICKS that icon, so core sends the request (model custom.object) and checks the permission.",
  "menu item": "ListStatusSubscriber (CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS): `data-toggle=co-status` (without a data-toggle core adds `ajax` and navigates), `data-co-status-action=activate|deactivate`, the texts in `data-text-activate/deactivate`, priority 800 (above Details 600), only for who may edit the object (CustomObjectPermissionProvider::canEdit). Texts: custom.object.status.activate / deactivate / column in en_US and pt_BR.",
  "items list": "NOT done, on purpose (not asked yet): CustomItem/_list.html.twig prints core's toggle icon with `filterEntityId is empty ? icon : ''`, but the controller passes `(int) filterEntityId`, so it is 0, and Twig's `empty` test is FALSE for the integer 0: the items list has never shown any status icon. Giving it the badge needs `not filterEntityId` there (and the column only outside a contact / item tab), the same subscriber branch with CustomItemPermissionProvider::canEdit, and #assetTable in the script and the CSS.",
  "description": "the description under the name is NOT hidden here (the patches and n8n lists hide it).",
  "twin": "the look is a copy of `.patches-badge` / `.n8ndispatch-badge` and of the icon badge CSS of the other two plugins, on purpose (CLAUDE.md, "Plugins are independent"): a visual change is written in the three.",
  "tests": "PHPUnit Tests/Unit/EventListener/ListStatusSubscriberTest.php, Node Tests/js/list-status.test.js, browser spec scripts/5/e2e/tests/custom-object-status.spec.js (objects made in the database).",
  "no column layout by script": "Assets/css/custom-objects.css: the cells of `#custom-objects-table` are `white-space:nowrap`, so the wider Status column makes the table scroll in its box instead of squeezing the names; no script fixes the widths. TRAP: with wrapping cells the open badge shrank the other columns, the rows re-wrapped and the badge slid from under the pointer (it flickered). Same rule as the lists of MauticPatchesBundle (../mautic-patches-bundle-5/wiki/patches-status-column.md, `badges box and layout`), duplicated on purpose."
}
```
