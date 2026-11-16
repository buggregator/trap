<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Protocol;

use Buggregator\Trap\Server\Logger;

/**
 * JSON-RPC 2.0 over the decoded JSON of a message or of a batch of them.
 *
 * @internal
 */
final class JsonRpc
{
    public function __construct(
        private readonly McpServer $server,
        private readonly ?Logger $logger = null,
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public static function error(int|string|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * @return array<array-key, mixed>|null The response, or the list of responses of a batch;
     *         null when nothing is answered: only notifications and responses of the client.
     */
    public function process(mixed $payload): ?array
    {
        if (!\is_array($payload) || !\array_is_list($payload)) {
            return $this->message($payload);
        }

        // `{}` decodes to an empty array too, and is an invalid request as `[]` is
        if ($payload === []) {
            return self::error(null, RpcError::INVALID_REQUEST, 'Invalid Request');
        }

        $responses = [];
        /** @var mixed $message */
        foreach ($payload as $message) {
            $response = $this->message($message);
            $response === null or $responses[] = $response;
        }

        return $responses === [] ? null : $responses;
    }

    /**
     * @return array<non-empty-string, mixed>|null
     */
    private function message(mixed $message): ?array
    {
        if (!\is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            return self::error(null, RpcError::INVALID_REQUEST, 'Not a JSON-RPC 2.0 message.');
        }

        $method = $message['method'] ?? null;
        if ($method === null && (\array_key_exists('result', $message) || \array_key_exists('error', $message))) {
            // A response of the client: the server sends it no requests, so there is nothing to match
            return null;
        }

        $hasId = \array_key_exists('id', $message);
        /** @var mixed $id */
        $id = $message['id'] ?? null;
        $validId = \is_string($id) || \is_int($id);
        $params = $message['params'] ?? [];
        if (!\is_string($method) || ($hasId && !$validId) || !\is_array($params)) {
            return self::error($validId ? $id : null, RpcError::INVALID_REQUEST, 'Invalid JSON-RPC request.');
        }

        if (!$hasId) {
            try {
                $this->server->notify($method, $params);
            } catch (\Throwable $e) {
                $this->logger?->exception($e, 'MCP notification failed');
            }

            return null;
        }

        try {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $this->server->request($method, $params)];
        } catch (RpcError $e) {
            return self::error($id, $e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger?->exception($e, 'MCP request failed');
            return self::error($id, RpcError::INTERNAL_ERROR, $e->getMessage());
        }
    }
}
