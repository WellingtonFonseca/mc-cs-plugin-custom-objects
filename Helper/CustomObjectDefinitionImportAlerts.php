<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Helper;

use MauticPlugin\CustomObjectsBundle\DTO\CustomObjectDefinitionImportResult;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the object page says after an import of a definition, as alerts (Bootstrap types: success, info, warning): the object was created or
 * received fields (success), nothing was added (info), a required field was added to an object that has items, or a kept field has another type in the file (warning).
 */
class CustomObjectDefinitionImportAlerts
{
    public const SESSION_KEY = 'custom_object_import_alerts';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @return array<int, array{type: string, message: string, items: string[]}>
     */
    public function fromResult(CustomObjectDefinitionImportResult $result): array
    {
        $name   = (string) $result->getCustomObject()->getName();
        $alerts = [];

        if ($result->isCreated()) {
            $alerts[] = $this->alert('success', 'custom.object.import.done', ['%name%' => $name]);
        } elseif ([] === $result->getAddedAliases()) {
            $alerts[] = $this->alert('info', 'custom.object.import.nothing', ['%name%' => $name]);
        } else {
            $alerts[] = $this->alert('success', 'custom.object.import.added', ['%name%' => $name], $result->getAddedAliases());
        }

        if ([] !== $result->getRequiredWithItems()) {
            $alerts[] = $this->alert('warning', 'custom.object.import.required_with_items', ['%name%' => $name], $result->getRequiredWithItems());
        }

        if ([] !== $result->getTypeMismatches()) {
            $alerts[] = $this->alert('warning', 'custom.object.import.mismatch', ['%fields%' => implode('; ', $result->getTypeMismatches())]);
        }

        return $alerts;
    }

    /**
     * @param string[] $params
     * @param string[] $items  shown as a bulleted list under the message
     *
     * @return array{type: string, message: string, items: string[]}
     */
    private function alert(string $type, string $id, array $params, array $items = []): array
    {
        return ['type' => $type, 'message' => $this->translator->trans($id, $params), 'items' => $items];
    }
}
