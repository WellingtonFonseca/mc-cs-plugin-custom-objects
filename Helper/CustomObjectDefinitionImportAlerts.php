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
     * @param bool $preview the check shown BEFORE the person confirms: the same facts, in the future tense, and a new object lists its fields
     *
     * @return array<int, array{type: string, message: string, items: string[]}>
     */
    public function fromResult(CustomObjectDefinitionImportResult $result, bool $preview = false): array
    {
        $name   = (string) $result->getCustomObject()->getName();
        $suffix = $preview ? '.preview' : '';
        $alerts = [];

        if ($result->isCreated()) {
            $alerts[] = $this->alert('success', 'custom.object.import.done'.$suffix, ['%name%' => $name], $preview ? $result->getAddedAliases() : []);
        } elseif ([] === $result->getAddedAliases()) {
            $alerts[] = $this->alert('info', 'custom.object.import.nothing'.$suffix, ['%name%' => $name]);
        } else {
            $alerts[] = $this->alert('success', 'custom.object.import.added'.$suffix, ['%name%' => $name], $result->getAddedAliases());
        }

        if (null !== $result->getCreatedCategory()) {
            $alerts[] = $this->alert('info', 'custom.object.import.category'.$suffix, ['%name%' => $result->getCreatedCategory()]);
        }

        if ([] !== $result->getRequiredWithItems()) {
            $alerts[] = $this->alert('warning', 'custom.object.import.required_with_items'.$suffix, ['%name%' => $name], $result->getRequiredWithItems());
        }

        if ([] !== $result->getTypeMismatches()) {
            $alerts[] = $this->alert('warning', 'custom.object.import.mismatch'.$suffix, [], $result->getTypeMismatches());
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
