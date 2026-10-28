<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Integration;

use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\CustomObjectsBundle\Helper\Changelog;

/**
 * Gives the plugin its tile on Settings > Plugins and a settings modal with no keys and no features: the modal exists to show the change
 * log (CHANGELOG.md) in a tab of its own (Assets/js/plugin-changelog-tab.js). The "Ativo" switch of the modal switches nothing (the plugin is
 * turned off by the custom_objects_enabled parameter), so a new integration is born on and its tile is colored.
 * The tile's icon is Assets/img/customobjects.png (core looks for <integration name in lower case>.png, not for icon.png).
 */
class CustomObjectsIntegration extends AbstractIntegration
{
    public const NAME = 'CustomObjects';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return 'Custom Objects';
    }

    /**
     * Core builds the row of a new integration (no id yet) and hands it here before saving it, unpublished. It is published here.
     */
    public function setIntegrationSettings(Integration $settings): void
    {
        if (null === $settings->getId()) {
            $settings->setIsPublished(true);
        }

        parent::setIntegrationSettings($settings);
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    /**
     * The change log under the fields of the first tab (core draws the 'custom' note there).
     * Core asks for the other sections too, and wants its own [text, type] pair for them.
     *
     * @return array<int|string, mixed>
     */
    public function getFormNotes($section)
    {
        if ('custom' !== $section) {
            return parent::getFormNotes($section);
        }

        return [
            'custom'     => true,
            'template'   => '@CustomObjects/Integration/_changelog.html.twig',
            'parameters' => ['days' => Changelog::days()],
        ];
    }
}
