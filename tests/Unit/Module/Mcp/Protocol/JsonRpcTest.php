<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Protocol;

use Buggregator\Trap\Module\Mcp\Protocol\JsonRpc;
use Buggregator\Trap\Module\Mcp\Protocol\RpcError;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\BrokenToolsModule;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(JsonRpc::class)]
final class JsonRpcTest
{
    #[DataSet([7], 'int id')]
    #[DataSet(['req-1'], 'string id')]
    public function testRequestIsAnsweredWithItsId(int|string $id): void
    {
        $response = self::rpc()->process(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'ping']);

        Assert::equals($response, ['jsonrpc' => '2.0', 'id' => $id, 'result' => new \stdClass()]);
    }

    #[DataSet([['jsonrpc' => '2.0', 'method' => 'notifications/initialized']], 'notification')]
    #[DataSet([['jsonrpc' => '2.0', 'method' => 'no/such/notification']], 'unknown notification')]
    #[DataSet([['jsonrpc' => '2.0', 'id' => 1, 'result' => []]], 'client response')]
    #[DataSet([['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => 1, 'message' => 'x']]], 'client error')]
    public function testNothingIsAnsweredToNonRequests(array $message): void
    {
        Assert::null(self::rpc()->process($message));
    }

    public function testUnknownMethod(): void
    {
        $response = self::rpc()->process(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'nope']);

        Assert::same($response, JsonRpc::error(1, RpcError::METHOD_NOT_FOUND, 'Method not found: nope'));
    }

    public function testInvalidParams(): void
    {
        $response = self::rpc()->process([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'missing'],
        ]);

        Assert::same($response['error']['code'], RpcError::INVALID_PARAMS);
        Assert::same($response['id'], 1);
    }

    #[DataSet(['text', null], 'not an object')]
    #[DataSet([['id' => 1, 'method' => 'ping'], null], 'no version')]
    #[DataSet([['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'], null], 'wrong version')]
    #[DataSet([['jsonrpc' => '2.0', 'id' => 1], 1], 'no method')]
    #[DataSet([['jsonrpc' => '2.0', 'id' => 1.5, 'method' => 'ping'], null], 'float id')]
    #[DataSet([['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping'], null], 'null id')]
    #[DataSet([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping', 'params' => 'x'], 2], 'scalar params')]
    #[DataSet([[], null], 'empty batch')]
    public function testInvalidRequest(mixed $message, ?int $id): void
    {
        $response = self::rpc()->process($message);

        Assert::same($response['error']['code'], RpcError::INVALID_REQUEST);
        Assert::same($response['id'], $id);
    }

    /**
     * `{}` decodes to the same empty array as `[]`.
     */
    public function testEmptyObjectOrBatchIsInvalidRequest(): void
    {
        Assert::same(self::rpc()->process([]), JsonRpc::error(null, RpcError::INVALID_REQUEST, 'Invalid Request'));
    }

    public function testFailedMethodIsInternalErrorAndLogged(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $rpc = new JsonRpc(McpServerTest::server([BrokenToolsModule::class]), new Logger($output));

        $response = $rpc->process(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);

        Assert::same($response, JsonRpc::error(3, RpcError::INTERNAL_ERROR, 'No description.'));
        Assert::string($output->fetch())->contains('No description.');
    }

    public function testBatchAnswersRequestsInOrder(): void
    {
        $response = self::rpc()->process([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'nope'],
        ]);

        Assert::count($response, 2);
        Assert::same($response[0]['id'], 1);
        Assert::same($response[1]['error']['code'], RpcError::METHOD_NOT_FOUND);
    }

    public function testWaitInBatchFails(): void
    {
        $rpc = (new Trap())->get(JsonRpc::class);
        $wait = ['name' => 'wait_for_events', 'arguments' => ['timeout' => 0]];

        $batched = $rpc->process([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $wait],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
        ]);
        $single = $rpc->process(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => $wait]);

        Assert::true($batched[0]['result']['isError']);
        Assert::string($batched[0]['result']['content'][0]['text'])->contains("can't run in a JSON-RPC batch");
        Assert::same($batched[1]['id'], 2);
        Assert::false($single['result']['isError']);
    }

    public function testBatchOfNotificationsIsNotAnswered(): void
    {
        Assert::null(self::rpc()->process([['jsonrpc' => '2.0', 'method' => 'notifications/initialized']]));
    }

    private static function rpc(): JsonRpc
    {
        return new JsonRpc(McpServerTest::server());
    }
}
