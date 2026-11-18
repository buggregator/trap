<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Http;

use Buggregator\Trap\Module\Mcp\Config\Mcp;
use Buggregator\Trap\Module\Mcp\Http\TokenGuard;
use Buggregator\Trap\Server\Logger;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
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
    #[DataSet([new ServerRequest('GET', '/x?token=s3cr3t%2bT0k%2fen')], 'lower-case encoded query')]
    #[DataSet([new ServerRequest('GET', '/x/s3cr3t%2BT0k%2Fen')], 'encoded path')]
    #[DataSet([new ServerRequest('GET', '/x/' . self::TOKEN . '/y')], 'raw path')]
    #[DataSet([new ServerRequest('GET', 'http://u:s3cr3t%2BT0k%2Fen@h/x')], 'user info of absolute form')]
    #[DataSet([new ServerRequest('GET', '/x#' . self::TOKEN)], 'fragment sent against the rules')]
    public function testRequestCarryingTokenIsNotPassedOn(ServerRequest $request): void
    {
        $passed = false;

        $response = self::handle($request, self::TOKEN, $passed);

        Assert::false($passed);
        Assert::same($response->getStatusCode(), 404);
        Assert::string((string) $response->getBody())->notContains(self::TOKEN);
    }

    public function testTokenInHeaderNameIsNotPassedOn(): void
    {
        $passed = false;
        $request = new ServerRequest('GET', '/api', ['X-S3cr3tHeaderT0ken' => '1']);

        $response = self::handle($request, 'S3cr3tHeaderT0ken', $passed);

        Assert::false($passed);
        Assert::same($response->getStatusCode(), 404);
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

    public function testShortTokenIsWarnedAboutAtConstruction(): void
    {
        $output = new BufferedOutput();

        self::guard('fifteen-chars-x', $output);

        Assert::string($output->fetch())
            ->contains('the token is shorter than 16 characters')
            ->contains('answered 404')
            ->notContains('fifteen-chars-x');
    }

    #[DataSet(['sixteen-chars-xx'], 'long enough')]
    #[DataSet([null], 'not set')]
    #[DataSet([''], 'empty')]
    public function testNoWarningAtConstructionOtherwise(?string $token): void
    {
        $output = new BufferedOutput();

        self::guard($token, $output);

        Assert::same($output->fetch(), '');
    }

    public function testFirstRejectionIsWarnedWithTokenMasked(): void
    {
        $output = new BufferedOutput();
        $guard = self::guard('sixteen-chars-xx', $output);

        self::pass($guard, new ServerRequest('POST', '/api/sixteen-chars-xx/store/?t=1'));
        self::pass($guard, new ServerRequest('POST', '/api/second/', ['X-Key' => 'sixteen-chars-xx']));

        Assert::string($output->fetch())
            ->contains('POST /api/***/store/ carries the MCP token, answered 404.')
            ->notContains('sixteen-chars-xx')
            ->notContains('/api/second/');
    }

    public function testNextRejectionsAreDebugLines(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $guard = self::guard('sixteen-chars-xx', $output);

        self::pass($guard, new ServerRequest('POST', '/api/first/', ['X-Key' => 'sixteen-chars-xx']));
        self::pass($guard, new ServerRequest('POST', '/api/second/', ['X-Key' => 'sixteen-chars-xx']));

        Assert::string($output->fetch())->contains('POST /api/second/ carries the MCP token, answered 404.');
    }

    public function testPathWithEncodedTokenIsWithheldFromLog(): void
    {
        $output = new BufferedOutput();
        $guard = self::guard(self::TOKEN, $output);
        $output->fetch();

        self::pass($guard, new ServerRequest('GET', '/x/s3cr3t%2BT0k%2Fen'));

        Assert::string($output->fetch())
            ->contains('GET (path withheld) carries the MCP token')
            ->notContains('s3cr3t');
    }

    #[DataSet(['a', '/api/1/store/', 'POST /***pi/1/store/ carries'], 'letter')]
    #[DataSet(['*', '/x*y/', 'POST /xy/ carries'], 'character of the mask')]
    public function testOneCharacterTokenIsMaskedInLog(string $token, string $path, string $logged): void
    {
        $output = new BufferedOutput();
        $guard = self::guard($token, $output);
        $output->fetch();

        self::pass($guard, new ServerRequest('POST', $path));

        Assert::string($output->fetch())->contains($logged);
    }

    /**
     * @param bool $passed Set to whether the request went down the pipeline, answered there with `299`.
     */
    private static function handle(ServerRequest $request, ?string $token, bool &$passed = false): ResponseInterface
    {
        return self::pass(self::guard($token), $request, $passed);
    }

    private static function pass(TokenGuard $guard, ServerRequest $request, bool &$passed = false): ResponseInterface
    {
        return $guard->handle($request, static function () use (&$passed): ResponseInterface {
            $passed = true;
            return new Response(299);
        });
    }

    private static function guard(?string $token, ?BufferedOutput $output = null): TokenGuard
    {
        $config = new Mcp();
        $config->token = $token;

        return new TokenGuard($config, new Logger($output));
    }
}
