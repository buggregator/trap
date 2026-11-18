<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Http;

use Buggregator\Trap\Module\Mcp\Config\Mcp;
use Buggregator\Trap\Module\Mcp\Http\TokenGuard;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(TokenGuard::class)]
final class TokenGuardTest
{
    private const TOKEN = 's3cr3t+T0k/en';

    #[DataSet([new ServerRequest('POST', '/mcp', ['Authorization' => 'Bearer ' . self::TOKEN])], 'MCP path')]
    #[DataSet([new ServerRequest('POST', '/api', ['Authorization' => 'Bearer ' . self::TOKEN])], 'Bearer header')]
    #[DataSet([new ServerRequest('POST', '/api', ['Authorization' => "Bearer\t" . self::TOKEN])], 'tab after scheme')]
    #[DataSet([new ServerRequest('POST', '/api', ['Authorization' => self::TOKEN])], 'no scheme')]
    #[DataSet([new ServerRequest('POST', '/api', ['Authorization' => ['Basic eA==', 'Bearer ' . self::TOKEN]])], 'second Authorization')]
    #[DataSet([new ServerRequest('GET', '/api', ['X-Api-Key' => self::TOKEN])], 'other header')]
    #[DataSet([new ServerRequest('GET', '/api', ['Cookie' => 'a=1; t=' . self::TOKEN])], 'cookie')]
    #[DataSet([new ServerRequest('GET', '/x?token=' . self::TOKEN)], 'raw query')]
    #[DataSet([new ServerRequest('GET', '/x?token=s3cr3t%2BT0k%2Fen')], 'encoded query')]
    #[DataSet([new ServerRequest('GET', '/x/s3cr3t%2BT0k%2Fen')], 'encoded path')]
    #[DataSet([new ServerRequest('GET', '/x/' . self::TOKEN . '/y')], 'raw path')]
    #[DataSet([new ServerRequest('GET', 'http://u:s3cr3t%2BT0k%2Fen@h/x')], 'user info of absolute form')]
    public function testRequestCarryingTokenIsNotPassedOn(ServerRequest $request): void
    {
        $passed = false;

        $response = self::handle($request, self::TOKEN, $passed);

        Assert::false($passed);
        Assert::same($response->getStatusCode(), 404);
        Assert::string((string) $response->getBody())->notContains(self::TOKEN);
    }

    public function testTokenOnlyInBodyOrInPartsGoesOn(): void
    {
        $request = new ServerRequest(
            'POST',
            '/api/events?token=s3cr3t',
            ['Authorization' => 'Bearer s3cr3t', 'X-Api-Key' => 'T0k/en'],
            self::TOKEN,
        );

        $response = self::handle($request, self::TOKEN);

        Assert::same($response->getStatusCode(), 299);
    }

    #[DataSet([null], 'not set')]
    #[DataSet([''], 'empty')]
    public function testEveryRequestGoesOnWithoutTokenConfigured(?string $token): void
    {
        $request = new ServerRequest('GET', '/x?token=s3cr3t', ['Authorization' => 'Bearer s3cr3t']);

        $response = self::handle($request, $token);

        Assert::same($response->getStatusCode(), 299);
    }

    /**
     * @param bool $passed Set to whether the request went down the pipeline, answered there with `299`.
     */
    private static function handle(ServerRequest $request, ?string $token, bool &$passed = false): ResponseInterface
    {
        $config = new Mcp();
        $config->token = $token;

        return (new TokenGuard($config))->handle($request, static function () use (&$passed): ResponseInterface {
            $passed = true;
            return new Response(299);
        });
    }
}
