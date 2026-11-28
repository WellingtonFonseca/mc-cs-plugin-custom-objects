<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Mcp;

use MauticPlugin\MauticMcpBundle\Mcp\Tool\AnnotatedToolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Shared answer shapes of the Custom Objects MCP tools. Loaded only when MauticMcpBundle is installed (see Config/services.php), because
 * ToolInterface belongs to that plugin.
 */
abstract class AbstractCustomObjectsTool implements AnnotatedToolInterface
{
    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    protected function ok(array $content): array
    {
        return [
            'isError'           => false,
            'structuredContent' => $content,
            'content'           => [['type' => 'text', 'text' => json_encode($content, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function error(string $message): array
    {
        return ['isError' => true, 'content' => [['type' => 'text', 'text' => $message]]];
    }

    /**
     * @param array<string, mixed> $args
     * @param string[]             $allowed
     */
    protected function unknownArguments(array $args, array $allowed): ?string
    {
        $unknown = array_diff(array_keys($args), $allowed);

        return [] === $unknown ? null : 'Unknown argument: '.implode(', ', $unknown).'.';
    }

    /**
     * The answer of an item API controller action: its JSON on success, its first error message otherwise.
     *
     * @return array<string, mixed>
     */
    protected function fromApiResponse(JsonResponse $response): array
    {
        $body = json_decode((string) $response->getContent(), true);
        if ($response->getStatusCode() >= 400) {
            return $this->error(is_array($body) && isset($body['errors'][0]['message']) ? (string) $body['errors'][0]['message'] : 'The request failed (HTTP '.$response->getStatusCode().').');
        }

        return $this->ok(is_array($body) ? $body : []);
    }
}
