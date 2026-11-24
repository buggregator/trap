<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Protocol;

use Buggregator\Trap\Module\Mcp\McpStatus;
use Buggregator\Trap\Module\Mcp\Protocol\McpServer;
use Buggregator\Trap\Module\Mcp\Protocol\RpcError;
use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\Tool\Arguments;
use Buggregator\Trap\Module\Mcp\Tools;
use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\WaitingToolsModule;
use Buggregator\Trap\Tests\Unit\Module\Mcp\ToolsTest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(McpServer::class)]
#[Covers(Arguments::class)]
final class McpServerTest
{
    /**
     * @param list<class-string> $modules
     */
    public static function server(?array $modules = null): McpServer
    {
        return new McpServer($modules === null ? ToolsTest::create() : ToolsTest::create($modules));
    }

    #[DataSet(['2025-11-25', '2025-11-25'], 'latest')]
    #[DataSet(['2025-06-18', '2025-06-18'], 'older supported')]
    #[DataSet(['2025-03-26', '2025-03-26'], 'oldest supported')]
    #[DataSet(['2024-11-05', '2025-11-25'], 'unsupported')]
    #[DataSet([null, '2025-11-25'], 'missing')]
    public function testInitializeNegotiatesVersion(?string $requested, string $expected): void
    {
        $result = self::server()->request('initialize', ['protocolVersion' => $requested]);

        Assert::same($result['protocolVersion'], $expected);
    }

    public function testInitializeDescribesServer(): void
    {
        $result = self::server()->request('initialize', ['protocolVersion' => '2025-06-18']);

        Assert::same($result['capabilities'], ['tools' => ['listChanged' => false]]);
        Assert::same($result['serverInfo'], ['name' => 'Buggregator Trap', 'version' => Info::version()]);
        Assert::string($result['instructions'])->contains('Trap')->contains('`list_feedback`')->contains('`list_crons`');
    }

    public function testPingIsEmptyObject(): void
    {
        Assert::equals(self::server()->request('ping', []), new \stdClass());
    }

    public function testToolsList(): void
    {
        $result = self::server()->request('tools/list', []);

        Assert::equals($result, ['tools' => [
            [
                'name' => 'echo',
                'title' => 'Echo',
                'description' => 'Returns the arguments.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'outputSchema' => ['type' => 'object'],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'plain',
                'title' => 'Plain',
                'description' => 'Does nothing.',
                'inputSchema' => ['type' => 'object'],
            ],
        ]]);
    }

    public function testToolsListWithoutTools(): void
    {
        Assert::same(self::server([])->request('tools/list', []), ['tools' => []]);
    }

    public function testToolCallReturnsText(): void
    {
        $result = self::server()->request('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'hi']]);

        Assert::same($result, ['content' => [['type' => 'text', 'text' => 'hi']], 'isError' => false]);
    }

    public function testToolCallIsNoted(): void
    {
        $status = new McpStatus();
        $server = new McpServer(ToolsTest::create(), $status);
        $server->request('tools/list', []);
        $listed = $status->toolCalledWithin(60.0);

        $server->request('tools/call', ['name' => 'echo', 'arguments' => []]);

        Assert::false($listed);
        Assert::true($status->toolCalledWithin(60.0));
    }

    public function testToolCallReturnsArrayAsStructuredContentAndJsonText(): void
    {
        $result = self::server()->request('tools/call', ['name' => 'echo', 'arguments' => ['id' => 1]]);

        Assert::same($result, [
            'content' => [['type' => 'text', 'text' => '{"id":1}']],
            'isError' => false,
            'structuredContent' => ['id' => 1],
        ]);
    }

    public function testEmptyArrayIsEmptyObject(): void
    {
        $result = self::server()->request('tools/call', ['name' => 'echo']);

        Assert::same($result['content'], [['type' => 'text', 'text' => '{}']]);
        Assert::equals($result['structuredContent'], new \stdClass());
    }

    public function testListResultIsToolError(): void
    {
        $result = self::server()->request('tools/call', ['name' => 'echo', 'arguments' => ['list' => [1, 2]]]);

        Assert::same($result, [
            'content' => [['type' => 'text', 'text' => 'Tool echo returned a list instead of an object.']],
            'isError' => true,
        ]);
    }

    public function testFailedToolIsErrorResult(): void
    {
        $result = self::server()->request('tools/call', ['name' => 'echo', 'arguments' => ['fail' => 'Broken']]);

        Assert::same($result, ['content' => [['type' => 'text', 'text' => 'Broken']], 'isError' => true]);
    }

    public function testWaitingToolFailsInBatch(): void
    {
        $server = self::server([WaitingToolsModule::class]);

        $waiting = $server->request('tools/call', ['name' => 'wait'], batch: true);
        $other = $server->request('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'hi']], batch: true);
        $single = $server->request('tools/call', ['name' => 'wait']);

        Assert::true($waiting['isError']);
        Assert::string($waiting['content'][0]['text'])->contains("`wait` can't run in a JSON-RPC batch");
        Assert::false($other['isError']);
        Assert::same($single['content'], [['type' => 'text', 'text' => 'done']]);
    }

    #[DataSet(['list_events', ['tpye' => 'monolog']], 'list_events')]
    #[DataSet(['wait_for_events', ['timeout' => 0, 'cout' => 1]], 'wait_for_events')]
    #[DataSet(['get_event', ['id' => 'abc', 'max_lenght' => 100]], 'get_event')]
    #[DataSet(['delete_event', ['idz' => ['abc']]], 'delete_event')]
    #[DataSet(['clear_events', ['sinse' => 'abc:1']], 'clear_events')]
    #[DataSet(['profile_summary', ['id' => 'abc', 'metric' => 'wt']], 'profile_summary')]
    #[DataSet(['profile_top', ['id' => 'abc', 'sort_by' => 'inclusive']], 'profile_top')]
    #[DataSet(['profile_calls', ['id' => 'abc', 'fn' => 'main()']], 'profile_calls')]
    #[DataSet(['profile_call_graph', ['id' => 'abc', 'functon' => 'main()']], 'profile_call_graph')]
    #[DataSet(['list_traces', ['error_only' => true]], 'list_traces')]
    #[DataSet(['get_trace', ['id' => 'abc', 'spans' => 10]], 'get_trace')]
    #[DataSet(['list_feedback', ['event' => 'abc']], 'list_feedback')]
    #[DataSet(['list_crons', ['slugs' => 'abc']], 'list_crons')]
    #[DataSet(['xdebug_trace_summary', ['id' => 'abc']], 'xdebug_trace_summary')]
    #[DataSet(['xdebug_trace_calls', ['uuid' => 'abc', 'q' => 'fib']], 'xdebug_trace_calls')]
    #[DataSet(['xdebug_trace_call', ['uuid' => 'abc', 'ofset' => 1]], 'xdebug_trace_call')]
    #[DataSet(['xdebug_sessions', ['all' => true]], 'xdebug_sessions')]
    #[DataSet(['xdebug_location', ['session' => '1']], 'xdebug_location')]
    #[DataSet(['xdebug_stack', ['depth' => 1]], 'xdebug_stack')]
    #[DataSet(['xdebug_variables', ['session_id' => '1', 'var' => '$a']], 'xdebug_variables')]
    #[DataSet(['xdebug_eval', ['expr' => '1']], 'xdebug_eval')]
    #[DataSet(['xdebug_continue', ['cmd' => 'run']], 'xdebug_continue')]
    #[DataSet(['xdebug_wait', ['seconds' => 1]], 'xdebug_wait')]
    #[DataSet(['xdebug_breakpoints', ['action' => 'set', 'path' => '/a.php']], 'xdebug_breakpoints')]
    public function testUndeclaredArgumentIsToolError(string $tool, array $arguments): void
    {
        $trap = self::filledTrap();
        $tools = $trap->get(Tools::class);
        $declared = \array_keys((array) $tools->get($tool)?->inputSchema()['properties']);
        $unknown = \array_values(\array_diff(\array_keys($arguments), $declared));

        $result = (new McpServer($tools))->request('tools/call', ['name' => $tool, 'arguments' => $arguments]);

        Assert::same($result, ['content' => [['type' => 'text', 'text' => \sprintf(
            'Unknown argument `%s`; `%s` takes: `%s`.',
            $unknown[0],
            $tool,
            \implode('`, `', $declared),
        )]], 'isError' => true]);
        // Nothing ran: `clear_events` deleted no events
        Assert::count($trap->store, 2);
    }

    public function testEveryToolHasUndeclaredArgumentDataSet(): void
    {
        $covered = [];
        $method = new \ReflectionMethod(self::class, 'testUndeclaredArgumentIsToolError');
        foreach ($method->getAttributes(DataSet::class) as $attribute) {
            $covered[] = $attribute->getArguments()[0][0];
        }
        $names = \array_map(static fn(Tool $tool): string => $tool->name(), (new Trap())->get(Tools::class)->all());
        \sort($covered);
        \sort($names);

        Assert::same($covered, $names);
    }

    public function testUndeclaredArgumentsAreAllNamed(): void
    {
        $server = new McpServer((new Trap())->get(Tools::class));

        $result = $server->request('tools/call', [
            'name' => 'get_trace',
            'arguments' => ['id' => 'abc', 'spans' => 10, '0' => 1, 'max_length' => 100],
        ]);

        Assert::same($result, ['content' => [[
            'type' => 'text',
            'text' => 'Unknown arguments `spans`, `0`; `get_trace` takes: `id`, `max_length`.',
        ]], 'isError' => true]);
    }

    public function testDeclaredArgumentsRun(): void
    {
        $trap = self::filledTrap();

        $result = (new McpServer($trap->get(Tools::class)))
            ->request('tools/call', ['name' => 'clear_events', 'arguments' => ['type' => 'monolog']]);

        Assert::false($result['isError']);
        Assert::count($trap->store, 1);
    }

    #[DataSet([['name' => 'missing']], 'unknown tool')]
    #[DataSet([[]], 'no name')]
    #[DataSet([['name' => 'echo', 'arguments' => 'text']], 'arguments not an object')]
    #[DataSet([['name' => 'echo', 'arguments' => [1, 2]]], 'arguments a list')]
    public function testInvalidToolCallParams(array $params): never
    {
        Expect::exception(RpcError::class)->withCode(RpcError::INVALID_PARAMS);

        self::server()->request('tools/call', $params);
    }

    public function testUnknownMethod(): never
    {
        Expect::exception(RpcError::class)->withCode(RpcError::METHOD_NOT_FOUND)->withMessage('Method not found: nope');

        self::server()->request('nope', []);
    }

    private static function filledTrap(): Trap
    {
        $trap = new Trap();
        $trap->add(Trap::dump('hi'));
        $trap->add(Trap::log('Payment declined'));

        return $trap;
    }
}
