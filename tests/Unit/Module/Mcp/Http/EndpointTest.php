<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Http;

use Buggregator\Trap\Module\Mcp\Caller;
use Buggregator\Trap\Module\Mcp\Config\Mcp;
use Buggregator\Trap\Module\Mcp\Http\Endpoint;
use Buggregator\Trap\Module\Mcp\Http\TokenGuard;
use Buggregator\Trap\Module\Mcp\Protocol\JsonRpc;
use Buggregator\Trap\Module\Mcp\Protocol\RpcError;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Protocol\McpServerTest;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
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

    #[DataSet([null], 'unset')]
    #[DataSet([''], 'empty')]
    public function testWithoutTokenNoAuthorizationIsAsked(?string $token): void
    {
        $response = self::handle(self::post(self::INITIALIZE), $token);

        Assert::same($response->getStatusCode(), 200);
    }

    #[DataSet(['Bearer s3cr3t'], 'exact')]
    #[DataSet(['bearer s3cr3t'], 'lower case scheme')]
    #[DataSet(['BEARER s3cr3t'], 'upper case scheme')]
    #[DataSet(['Bearer   s3cr3t'], 'several spaces after the scheme')]
    public function testRightTokenIsAuthorized(string $authorization): void
    {
        $response = self::handle(self::post(self::INITIALIZE, ['Authorization' => $authorization]), 's3cr3t');

        Assert::same($response->getStatusCode(), 200);
    }

    #[DataSet([null, 'Bearer'], 'no header')]
    #[DataSet(['', 'Bearer'], 'empty header')]
    #[DataSet(['Bearer', 'Bearer'], 'scheme alone')]
    #[DataSet(['Bearer ', 'Bearer'], 'scheme and space')]
    #[DataSet(['Bearers3cr3t', 'Bearer'], 'no space')]
    #[DataSet(["Bearer\ts3cr3t", 'Bearer'], 'tab after the scheme')]
    #[DataSet(['s3cr3t', 'Bearer'], 'token without a scheme')]
    #[DataSet(['Basic s3cr3t', 'Bearer'], 'other scheme')]
    #[DataSet(['Token s3cr3t', 'Bearer'], 'made-up scheme')]
    #[DataSet(['Bearer wrong', 'Bearer error="invalid_token"'], 'wrong token')]
    #[DataSet(['Bearer s3cr3', 'Bearer error="invalid_token"'], 'prefix of the token')]
    #[DataSet(['Bearer s3cr3tt', 'Bearer error="invalid_token"'], 'token with a suffix')]
    #[DataSet(['Bearer S3CR3T', 'Bearer error="invalid_token"'], 'token in another case')]
    #[DataSet(['Bearer s3cr3t x', 'Bearer error="invalid_token"'], 'extra word')]
    public function testMissingOrWrongTokenIsUnauthorized(?string $authorization, string $challenge): void
    {
        $headers = $authorization === null ? [] : ['Authorization' => $authorization];

        $response = self::handle(self::post(self::INITIALIZE, $headers), 's3cr3t');

        Assert::same($response->getStatusCode(), 401);
        Assert::same($response->getHeaderLine('WWW-Authenticate'), $challenge);
        Assert::string((string) $response->getBody())->notContains('s3cr3t')->notContains('jsonrpc');
    }

    #[DataSet([new ServerRequest('GET', '/mcp')], 'GET')]
    #[DataSet([new ServerRequest('POST', '/mcp', ['Origin' => 'https://evil.example'], self::INITIALIZE)], 'foreign origin')]
    #[DataSet([new ServerRequest('POST', '/mcp', ['MCP-Protocol-Version' => '2024-11-05'], self::INITIALIZE)], 'unsupported version')]
    #[DataSet([new ServerRequest('POST', '/mcp', [], '{"jsonrpc":')], 'broken JSON')]
    public function testUnauthorizedGoesBeforeOtherErrors(ServerRequest $request): void
    {
        $response = self::handle($request, 's3cr3t');

        Assert::same($response->getStatusCode(), 401);
    }

    public function testOriginIsCheckedWithRightToken(): void
    {
        $request = self::post(self::INITIALIZE, [
            'Authorization' => 'Bearer s3cr3t',
            'Origin' => 'https://evil.example',
        ]);

        $response = self::handle($request, 's3cr3t');

        Assert::same($response->getStatusCode(), 403);
    }

    public function testOnlyPostIsAllowedWithRightToken(): void
    {
        $request = new ServerRequest('GET', '/mcp', ['Authorization' => 'Bearer s3cr3t']);

        $response = self::handle($request, 's3cr3t');

        Assert::same($response->getStatusCode(), 405);
    }

    #[DataSet([[]], 'no header')]
    #[DataSet([['Authorization' => 'Bearer wrong']], 'wrong token')]
    #[DataSet([['Authorization' => 'Basic d3Jvbmc=']], 'other scheme')]
    public function testOtherPathsWithoutTokenGoDownThePipeline(array $headers): void
    {
        $response = self::handle(new ServerRequest('POST', '/api/events', $headers), 's3cr3t');

        Assert::same($response->getStatusCode(), 299);
    }

    #[DataSet(['//mcp'], 'repeated slashes')]
    #[DataSet(['/MCP'], 'upper case')]
    #[DataSet(['/mcp//'], 'repeated trailing slashes')]
    #[DataSet(['//Mcp/'], 'all at once')]
    public function testMcpPathIsNormalized(string $path): void
    {
        $unauthorized = self::handle(new ServerRequest('POST', "http://127.0.0.1:8000$path", [], self::INITIALIZE), 's3cr3t');
        $authorized = self::handle(
            new ServerRequest('POST', "http://127.0.0.1:8000$path", ['Authorization' => 'Bearer s3cr3t'], self::INITIALIZE),
            's3cr3t',
        );

        Assert::same($unauthorized->getStatusCode(), 401);
        Assert::same($authorized->getStatusCode(), 200);
    }

    #[DataSet(['/'], 'root')]
    #[DataSet(['/api/events'], 'UI API')]
    #[DataSet(['/mcp/tools'], 'nested path')]
    #[DataSet(['/%6Dcp'], 'encoded path')]
    public function testOtherPathWithTokenIsNotPassedOn(string $path): void
    {
        $passed = false;
        $request = new ServerRequest('POST', $path, ['Authorization' => 'bearer  s3cr3t'], self::INITIALIZE);

        $response = self::handle($request, 's3cr3t', $passed);

        Assert::false($passed);
        Assert::same($response->getStatusCode(), 404);
        Assert::string((string) $response->getBody())->notContains('s3cr3t');
    }

    public function testOtherPathWithAnyBearerGoesDownThePipelineWithoutTokenConfigured(): void
    {
        $request = new ServerRequest('POST', '/api/events', ['Authorization' => 'Bearer s3cr3t']);

        $response = self::handle($request);

        Assert::same($response->getStatusCode(), 299);
    }

    public function testWaitingToolStopsWhenTheConnectionCloses(): void
    {
        $trap = new Trap();
        $gone = false;
        $request = self::post('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wait_for_events"}}')
            ->withAttribute(Middleware::DISCONNECTED, static function () use (&$gone): bool {
                return $gone;
            });
        $endpoint = $trap->get(Endpoint::class);
        $fiber = new \Fiber(static fn(): ResponseInterface => $endpoint->handle(
            $request,
            static fn(): ResponseInterface => new Response(299),
        ));

        $fiber->start();
        $waiting = $fiber->isSuspended();
        $gone = true;
        $fiber->resume();

        Assert::true($waiting);
        $response = $fiber->getReturn();
        Assert::same($response->getStatusCode(), 200);
        Assert::true(self::body($response)['result']['structuredContent']['timed_out']);
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

    /**
     * @param bool $passed Set to whether the request went down the pipeline, answered there with `299`.
     */
    private static function handle(ServerRequest $request, ?string $token = null, bool &$passed = false): ResponseInterface
    {
        $config = new Mcp();
        $config->token = $token;
        $endpoint = new Endpoint(new JsonRpc(McpServerTest::server()), new Caller(), $config, new TokenGuard($config, new Logger()));

        return $endpoint->handle($request, static function () use (&$passed): ResponseInterface {
            $passed = true;
            return new Response(299);
        });
    }
}
