<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Protocol;

use Buggregator\Trap\Module\Mcp\Protocol\McpServer;
use Buggregator\Trap\Module\Mcp\Protocol\RpcError;
use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Tests\Unit\Module\Mcp\ToolsTest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(McpServer::class)]
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
        Assert::string($result['instructions'])->contains('Trap');
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
}
