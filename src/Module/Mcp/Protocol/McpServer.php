<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Protocol;

use Buggregator\Trap\Module\Mcp\McpStatus;
use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\Tool\Arguments;
use Buggregator\Trap\Module\Mcp\Tools;
use Buggregator\Trap\Module\Mcp\WaitingTool;
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
        . 'application sends while running: dumps, logs, exceptions, mails, HTTP requests and profiles as events, '
        . 'and OpenTelemetry traces. Use its tools to read them: the `*_events` tools for events, '
        . '`list_traces` and `get_trace` for traces.';

    public function __construct(
        private readonly Tools $tools,
        private readonly McpStatus $status = new McpStatus(),
    ) {}

    /**
     * @param array<array-key, mixed> $params
     * @param bool $batch Whether the request comes in a JSON-RPC batch: a wait for events fails there.
     *
     * @return array<non-empty-string, mixed>|\stdClass The result; a `\stdClass` for an empty JSON object.
     *
     * @throws RpcError
     */
    public function request(string $method, array $params, bool $batch = false): array|\stdClass
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'ping' => new \stdClass(),
            'tools/list' => ['tools' => \array_map(self::describe(...), $this->tools->all())],
            'tools/call' => $this->callTool($params, $batch),
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
        $outputSchema = $tool->outputSchema();

        return [
            'name' => $tool->name(),
            'title' => $tool->title(),
            'description' => $tool->description(),
            'inputSchema' => $tool->inputSchema(),
            ...($outputSchema === null ? [] : ['outputSchema' => $outputSchema]),
            ...($annotations === [] ? [] : ['annotations' => $annotations]),
        ];
    }

    /**
     * @return array{content: list<array{type: 'text', text: string}>, isError: bool}
     */
    private static function result(string $text, bool $isError = false): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $isError,
        ];
    }

    /**
     * Structured content must be a JSON object, which an empty PHP array is not encoded as.
     *
     * @param array<array-key, mixed> $result
     *
     * @return array<array-key, mixed>|\stdClass
     *
     * @throws \LogicException For a list.
     */
    private static function object(array $result, string $tool): array|\stdClass
    {
        if ($result === []) {
            return new \stdClass();
        }

        \array_is_list($result) and throw new \LogicException(
            "Tool $tool returned a list instead of an object.",
        );

        return $result;
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
        /** @var mixed $client */
        $client = $params['clientInfo'] ?? null;
        /** @var mixed $name */
        $name = \is_array($client) ? $client['name'] ?? null : null;
        \is_string($name) and $this->status->clientNamed($name);

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
     * A wait in a batch fails: the calls of a batch run one after another, so it would hold up the rest.
     *
     * @param array<array-key, mixed> $params
     *
     * @return array<non-empty-string, mixed>
     */
    private function callTool(array $params, bool $batch): array
    {
        $name = $params['name'] ?? null;
        \is_string($name) or throw RpcError::invalidParams('Tool name must be a string.');
        $tool = $this->tools->get($name) ?? throw RpcError::invalidParams("Unknown tool: $name");
        if ($batch && $tool instanceof WaitingTool) {
            return self::result(
                "`$name` can't run in a JSON-RPC batch: the batch is answered once all its calls are done, "
                . 'so the wait would hold up the rest. Send it as a request of its own.',
                isError: true,
            );
        }

        $arguments = $params['arguments'] ?? [];
        \is_array($arguments) && ($arguments === [] || !\array_is_list($arguments)) or throw RpcError::invalidParams('Tool arguments must be an object.');

        $this->status->toolCalled();
        try {
            Arguments::declared($arguments, $tool->inputSchema(), $name);
            $result = $tool->call($arguments);
            if (\is_string($result)) {
                return self::result($result);
            }

            $structured = self::object($result, $name);
            // The same JSON as text too: clients of 2025-03-26 and the ones that give the model only `content`
            return self::result(Json::encode($structured)) + ['structuredContent' => $structured];
        } catch (\Throwable $e) {
            return self::result($e->getMessage(), isError: true);
        }
    }
}
