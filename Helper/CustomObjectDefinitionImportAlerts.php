<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Helper;

use MauticPlugin\CustomObjectsBundle\DTO\CustomObjectDefinitionImportResult;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the object page says after an import of a definition, as alerts (Bootstrap types: success, info, warning): the object was created or
 * received fields (success), nothing was added or some fields were kept (info), a kept field has another type in the file (warning).
 */
class CustomObjectDefinitionImportAlerts
{
    public const SESSION_KEY = 'custom_object_import_alerts';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @return array<int, array{type: string, message: string}>
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
            $alerts[] = $this->alert('success', 'custom.object.import.added', ['%name%' => $name, '%fields%' => implode(', ', $result->getAddedAliases())]);
        }

        if ([] !== $result->getSkipped()) {
            $alerts[] = $this->alert('info', 'custom.object.import.skipped', ['%fields%' => implode(', ', $result->getSkipped())]);
        }

        if ([] !== $result->getTypeMismatches()) {
            $alerts[] = $this->alert('warning', 'custom.object.import.mismatch', ['%fields%' => implode('; ', $result->getTypeMismatches())]);
        }

        return $alerts;
    }

    /**
     * @param string[] $params
     *
     * @return array{type: string, message: string}
     */
    private function alert(string $type, string $id, array $params): array
    {
        return ['type' => $type, 'message' => $this->translator->trans($id, $params)];
    }
}
