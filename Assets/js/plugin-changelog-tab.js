// The change log of the plugin as its own tab on Settings > Plugins > Custom Objects. Core draws the tabs of that modal by itself and gives a
// plugin no way to add one, so Resources/views/Integration/_changelog.html.twig (the 'custom' note of the first tab) marks the block with
// data-onload-callback="customObjectsChangelogTab" and this moves it into a new tab once the modal is up. Without the script the block just
// stays, with its title, under the fields of the first tab.

Mautic.customObjectsChangelogTab = function(element) {
    var $          = mQuery;
    var paneId     = 'customobjects-changelog-container';
    var $block     = $(element);
    var $content   = $block.closest('.tab-content');
    // core draws the tab list just before the form that holds the tab content
    var $tabs      = $content.closest('form').prev('ul.nav-tabs');

    if (!$content.length || !$tabs.length || $('#' + paneId).length) {
        return;
    }

    $block.find('.customobjects-changelog-title').remove();

    $('<li id="customobjects-changelog-tab"></li>')
        .append($('<a role="tab" data-toggle="tab"></a>').attr('href', '#' + paneId).text($block.attr('data-tab-label')))
        .appendTo($tabs);

    $('<div class="tab-pane fade bdr-w-0"></div>').attr('id', paneId).append($block).appendTo($content);
};
