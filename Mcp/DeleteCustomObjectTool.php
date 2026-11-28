<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Mcp;

use MauticPlugin\CustomObjectsBundle\Controller\Api\CustomObjectApiController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Wraps DELETE /api/customobjects/{objectAlias} (Controller/Api/CustomObjectApiController.php::deleteAction), so the permission, the
 * child-object rule and the segment 409 are the REST ones. dryRun maps to its ?dryRun=1.
 */
class DeleteCustomObjectTool extends AbstractCustomObjectsTool
{
    public function __construct(private CustomObjectApiController $controller)
    {
    }

    public function name(): string
    {
        return 'custom_objects_delete_object';
    }

    /**
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true];
    }

    public function description(): string
    {
        return 'DESTRUCTIVE. Permanently delete a whole Custom Object (its definition, fields and ALL its items) by alias. Refused, deleting nothing, when the object has a child object (delete the child first) or when segment filters use it (the error lists the segments). An alias that does not exist is not an error: it answers "alreadyGone" true. With "dryRun" true (default) it runs the same permission and rule checks and deletes NOTHING: it answers "itemCount" (items that would be lost) and "wouldDelete" true; pass false to delete. Use custom_objects_get_definition first to see the object.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'alias'  => ['type' => 'string', 'description' => 'Alias of the Custom Object.'],
                'dryRun' => ['type' => 'boolean', 'default' => true, 'description' => 'true (default): only report. false: delete.'],
            ],
            'required'             => ['alias'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'alias'       => ['type' => 'string'],
                'dryRun'      => ['type' => 'boolean'],
                'deleted'     => ['type' => 'boolean'],
                'alreadyGone' => ['type' => 'boolean', 'description' => 'The alias does not exist (not in dryRun answers).'],
                'itemCount'   => ['type' => 'integer', 'description' => 'dryRun only: items that would be deleted with the object.'],
                'wouldDelete' => ['type' => 'boolean', 'description' => 'dryRun only: every check passed.'],
            ],
            'required' => ['alias', 'deleted'],
        ];
    }

    public function call(array $args): array
    {
        if ($message = $this->unknownArguments($args, ['alias', 'dryRun'])) {
            return $this->error($message);
        }

        if (!isset($args['alias']) || !is_string($args['alias']) || '' === $args['alias']) {
            return $this->error('"alias" is required.');
        }

        $dryRun = $args['dryRun'] ?? true;
        if (!is_bool($dryRun)) {
            return $this->error('"dryRun" must be a boolean.');
        }

        return $this->fromApiResponse($this->controller->deleteAction(new Request($dryRun ? ['dryRun' => '1'] : []), $args['alias']));
    }
}
