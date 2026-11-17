<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Http;

use Buggregator\Trap\Module\Mcp\Caller;
use Buggregator\Trap\Module\Mcp\Http\Endpoint;
use Buggregator\Trap\Module\Mcp\Protocol\JsonRpc;
use Buggregator\Trap\Module\Mcp\Protocol\RpcError;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Protocol\McpServerTest;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Endpoint::class)]
final class EndpointTest
{
    private const INITIALIZE = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}';

    #[DataSet(['/'], 'root')]
    #[DataSet(['/mcp/tools'], 'nested path')]
    #[DataSet(['/api/mcp'], 'other prefix')]
    public function testOtherPathsGoDownThePipeline(string $path): void
    {
        $response = self::handle(new ServerRequest('POST', $path, [], self::INITIALIZE));

        Assert::same($response->getStatusCode(), 299);
    }

    public function testRequestIsAnsweredWithJson(): void
    {
        $response = self::handle(self::post(self::INITIALIZE, ['MCP-Protocol-Version' => '2025-06-18']));

        Assert::same($response->getStatusCode(), 200);
        Assert::same($response->getHeaderLine('Content-Type'), 'application/json');
        $body = self::body($response);
        Assert::same($body['id'], 1);
        Assert::same($body['result']['protocolVersion'], '2025-06-18');
        Assert::same($body['result']['serverInfo']['name'], 'Buggregator Trap');
    }

    public function testTrailingSlashIsTheSamePath(): void
    {
        $response = self::handle(new ServerRequest('POST', '/mcp/', [], self::INITIALIZE));

        Assert::same($response->getStatusCode(), 200);
    }

    public function testEmptyResultIsJsonObject(): void
    {
        $response = self::handle(self::post('{"jsonrpc":"2.0","id":"a","method":"ping"}'));

        Assert::same((string) $response->getBody(), '{"jsonrpc":"2.0","id":"a","result":{}}');
    }

    #[DataSet(['{"jsonrpc":"2.0","method":"notifications/initialized"}'], 'notification')]
    #[DataSet(['{"jsonrpc":"2.0","id":1,"result":{}}'], 'response')]
    #[DataSet(['[{"jsonrpc":"2.0","method":"notifications/initialized"}]'], 'batch of notifications')]
    public function testNonRequestIsAccepted(string $body): void
    {
        $response = self::handle(self::post($body));

        Assert::same($response->getStatusCode(), 202);
        Assert::same((string) $response->getBody(), '');
    }

    public function testBatchIsAnsweredWithList(): void
    {
        $response = self::handle(self::post('[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"x"}]'));

        Assert::same($response->getStatusCode(), 200);
        $body = self::body($response);
        Assert::same($body[0]['id'], 1);
        Assert::same($body[1]['error']['code'], RpcError::METHOD_NOT_FOUND);
    }

    public function testMethodErrorIsAnsweredWithOk(): void
    {
        $response = self::handle(self::post('{"jsonrpc":"2.0","id":5,"method":"nope"}'));

        Assert::same($response->getStatusCode(), 200);
        Assert::same(self::body($response)['error']['code'], RpcError::METHOD_NOT_FOUND);
    }

    #[DataSet(['{"jsonrpc":', RpcError::PARSE_ERROR], 'broken JSON')]
    #[DataSet(['', RpcError::PARSE_ERROR], 'empty body')]
    #[DataSet(['{"id":1,"method":"ping"}', RpcError::INVALID_REQUEST], 'not JSON-RPC')]
    #[DataSet(['[]', RpcError::INVALID_REQUEST], 'empty batch')]
    #[DataSet(['{}', RpcError::INVALID_REQUEST], 'empty object')]
    public function testInvalidMessageIsBadRequest(string $body, int $code): void
    {
        $response = self::handle(self::post($body));

        Assert::same($response->getStatusCode(), 400);
        $body = self::body($response);
        Assert::null($body['id']);
        Assert::same($body['error']['code'], $code);
    }

    #[DataSet(['GET'])]
    #[DataSet(['PUT'])]
    #[DataSet(['DELETE'])]
    public function testOnlyPostIsAllowed(string $method): void
    {
        $response = self::handle(new ServerRequest($method, '/mcp'));

        Assert::same($response->getStatusCode(), 405);
        Assert::same($response->getHeaderLine('Allow'), 'POST');
    }

    #[DataSet(['http://localhost:6274'], 'localhost')]
    #[DataSet(['http://127.0.0.1'], 'IPv4 loopback')]
    #[DataSet(['https://[::1]:8000'], 'IPv6 loopback')]
    #[DataSet(['http://LOCALHOST'], 'upper case')]
    public function testLocalOriginIsAllowed(string $origin): void
    {
        $response = self::handle(self::post(self::INITIALIZE, ['Origin' => $origin]));

        Assert::same($response->getStatusCode(), 200);
    }

    #[DataSet(['https://evil.example'], 'other site')]
    #[DataSet(['http://localhost.evil.example'], 'localhost prefix')]
    #[DataSet(['http://127.0.0.2'], 'other loopback')]
    #[DataSet(['null'], 'opaque origin')]
    public function testForeignOriginIsForbidden(string $origin): void
    {
        $response = self::handle(self::post(self::INITIALIZE, ['Origin' => $origin]));

        Assert::same($response->getStatusCode(), 403);
    }

    public function testUnsupportedProtocolVersionIsBadRequest(): void
    {
        $response = self::handle(self::post(self::INITIALIZE, ['MCP-Protocol-Version' => '2024-11-05']));

        Assert::same($response->getStatusCode(), 400);
    }

    /**
     * @param array<non-empty-string, string> $headers
     */
    private static function post(string $body, array $headers = []): ServerRequest
    {
        return new ServerRequest('POST', 'http://127.0.0.1:8000/mcp', $headers, $body);
    }

    private static function body(ResponseInterface $response): array
    {
        return \json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function handle(ServerRequest $request): ResponseInterface
    {
        $endpoint = new Endpoint(new JsonRpc(McpServerTest::server()), new Caller());

        return $endpoint->handle($request, static fn(): ResponseInterface => new Response(299));
    }
}
