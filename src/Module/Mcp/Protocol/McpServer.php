<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Protocol;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\Tools;
use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Support\Json;

/**
 * The MCP methods of a stateless server with tools.
 *
 * @internal
 */
final class McpServer
{
    /** Supported protocol versions, the latest first. */
    public const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    private const INSTRUCTIONS = "Buggregator Trap is a local debug server. It catches what the developer's "
        . 'application sends while running: dumps, logs, exceptions, mails, HTTP requests and profiles. '
        . 'Use its tools to read them.';

    public function __construct(
        private readonly Tools $tools,
    ) {}

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<non-empty-string, mixed>|\stdClass The result; a `\stdClass` for an empty JSON object.
     *
     * @throws RpcError
     */
    public function request(string $method, array $params): array|\stdClass
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'ping' => new \stdClass(),
            'tools/list' => ['tools' => \array_map(self::describe(...), $this->tools->all())],
            'tools/call' => $this->callTool($params),
            default => throw RpcError::methodNotFound($method),
        };
    }

    /**
     * Notifications, as `notifications/initialized`, need nothing from a stateless server.
     *
     * @param array<array-key, mixed> $params
     */
    public function notify(string $method, array $params): void {}

    /**
     * @return array<non-empty-string, mixed>
     */
    private static function describe(Tool $tool): array
    {
        $annotations = $tool->annotations();

        return [
            'name' => $tool->name(),
            'title' => $tool->title(),
            'description' => $tool->description(),
            'inputSchema' => $tool->inputSchema(),
            ...($annotations === [] ? [] : ['annotations' => $annotations]),
        ];
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<non-empty-string, mixed>
     */
    private function initialize(array $params): array
    {
        /** @var mixed $version */
        $version = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => \in_array($version, self::VERSIONS, true) ? $version : self::VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => Info::NAME, 'version' => Info::version()],
            'instructions' => self::INSTRUCTIONS,
        ];
    }

    /**
     * A failed tool is a result with `isError`, not a JSON-RPC error: the model sees what went wrong.
     *
     * @param array<array-key, mixed> $params
     *
     * @return array<non-empty-string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        \is_string($name) or throw RpcError::invalidParams('Tool name must be a string.');
        $tool = $this->tools->get($name) ?? throw RpcError::invalidParams("Unknown tool: $name");
        $arguments = $params['arguments'] ?? [];
        \is_array($arguments) && ($arguments === [] || !\array_is_list($arguments)) or throw RpcError::invalidParams('Tool arguments must be an object.');

        try {
            $result = $tool->call($arguments);
            $text = \is_string($result) ? $result : Json::encode($result);
            $isError = false;
        } catch (\Throwable $e) {
            $text = $e->getMessage();
            $isError = true;
        }

        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $isError,
        ];
    }
}
