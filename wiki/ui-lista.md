categoria: ui
regras:
- Status column and icon badge (the Status column of lists, Ativar / Desativar): shared rules in [../../wiki/ui-status-column.md](../../wiki/ui-status-column.md) and [../../wiki/ui-badge.md](../../wiki/ui-badge.md). The code is duplicated per plugin on purpose.
- The look of the Custom Objects badge is a copy of `.patches-badge` / `.n8ndispatch-badge` and of the icon badge CSS of the other two plugins, on purpose (CLAUDE.md, "Plugins are independent"): a visual change is written in the three.
- Core's toggle icon of the row stays in the page (hidden); the badge and the menu item follow it and CLICK it, so core sends the request and checks the permission. The plugin works without the other plugins.
- No column layout by script: the cells of the list table are `white-space:nowrap`, so a wider Status column makes the table scroll in its box instead of squeezing the names. TRAP: with wrapping cells the open badge shrank the other columns, the rows re-wrapped and the badge slid from under the pointer (it flickered). Same rule as the lists of MauticPatchesBundle (../../mautic-patches-bundle-5/wiki/patches-status-column.md, `badges box and layout`), duplicated on purpose.
- Modal of an item or object (plain click on the NAME): opens in core's shared modal (`Mautic.ajaxifyModal`, an inline `onclick` on the link); ctrl / cmd / shift click opens the full page. The link has no `data-toggle="ajax"` on purpose: core's handler would take the click.
- The shared modal's body has its own padding: the template adds none (a wrapper with `pa-md` doubled it).
- Footer of the modals: a `.bottom-form-buttons` block at the end of `details_modal.html.twig`, which core copies into the shared modal's footer: Editar (`btn btn-primary`, only when `canEdit`), Detalhes (`btn-secondary`, icon `ri-information-line` like every Detalhes of the project: segment, contact, object and item modals and the row menus) and Fechar (`btn-tertiary btn-cancel`, `data-dismiss="modal"`).
- Keep a single `.btn-primary` in the footer: core draws a second one outlined by position.
- Editar and Detalhes hide the modal and call `Mautic.loadContent(path(...)|e('js'))`.
- Row three-dots menu: "Detalhes" (icon `ri-information-line`) above Edit, in the lists of objects and items.
- Delete item of the row menus carries the `danger` class and the icon `ri-delete-bin-line`: core's red hover and the patches bundle's dark-theme text color (../../mautic-patches-bundle-5/wiki/patches-themes.md) are matched by `a.danger`; a `text-danger` icon stays red on the red hover (spec menu-delete-hover).
- Back button of the view pages goes in the `indexButton` block (core renders it above the title; in `actions` it lands beside Edit); core's pt_BR `mautic.core.close_back` names its placeholder `%alvo%`, not `%target%`, so pass both.
- A cell with a long text without spaces must break inside the cell (`overflow-wrap:anywhere`), or the table leaves the modal.
- Edit / delete buttons of a panel use core's `Helper/button.html.twig` (ghost, `md`, `ri-*-line ri-lg`, like FormBundle `Builder/_actions`): the old Bootstrap `btn-default` was a white block in the dark theme.
- Every text is a translation key with en_US and pt_BR (`Translations/*/messages.ini`); other locales fall back to en_US.
