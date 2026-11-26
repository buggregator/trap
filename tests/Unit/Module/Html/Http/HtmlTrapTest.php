<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Html\Http;

use Buggregator\Trap\Module\Html\Frame;
use Buggregator\Trap\Module\Html\Http\HtmlTrap;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Tests\Unit\Module\Html\PageTest;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(HtmlTrap::class)]
#[Test]
final class HtmlTrapTest
{
    #[DataSet(['/html', []], 'to /html')]
    #[DataSet(['/html/shop', []], 'to /html/project')]
    #[DataSet(['/', ['X-Buggregator-Event' => 'HTML']], 'marked by header')]
    #[DataSet(['/any/path', ['Authorization' => 'Basic aHRtbDo=']], 'Basic user html')]
    #[DataSet(['/', ['X-Buggregator-Event' => 'html', 'Authorization' => 'Basic dXNlcjpzM2NyM3Q=']], 'header over a Basic user')]
    public function testTakesPage(string $path, array $headers): void
    {
        $time = new \DateTimeImmutable('2026-01-02 03:04:05');
        $request = (new ServerRequest('POST', $path, ['Content-Type' => 'text/html'] + $headers, PageTest::PAGE))
            ->withAttribute('begin_at', $time);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 204);
        Assert::same((string) $response->getBody(), '');
        Assert::count($frames, 1);
        Assert::same($frames[0]->title, 'Nightly & report');
        Assert::same($frames[0]->html, PageTest::PAGE);
        Assert::same($frames[0]->time, $time);
    }

    #[DataSet(['GET', '/html', []], 'GET of the UI page')]
    #[DataSet(['GET', '/html/0198c2d4-7a54-7b81-9b8e-3cf7c6c3a6b1', []], 'GET of an event page')]
    #[DataSet(['POST', '/', []], 'unmarked elsewhere')]
    #[DataSet(['POST', '/htmlx', []], 'a path that only starts with html')]
    #[DataSet(['POST', '/html', ['X-Buggregator-Event' => 'http-dump']], 'marked for HTTP dumps')]
    #[DataSet(['POST', '/html', ['Authorization' => 'Basic c2VudHJ5Og==']], 'Basic user sentry')]
    #[DataSet(['PUT', '/', ['X-Buggregator-Event' => 'html']], 'not a POST')]
    public function testLeavesOtherRequests(string $method, string $path, array $headers): void
    {
        [$response, $frames] = self::handle(new ServerRequest($method, $path, ['Content-Type' => 'text/html'] + $headers, PageTest::PAGE));

        Assert::same($response->getStatusCode(), 404);
        Assert::same($frames, []);
    }

    #[DataSet(['/html', [], null], 'none')]
    #[DataSet(['/html', ['X-Buggregator-Project' => ' shop '], 'shop'], 'header')]
    #[DataSet(['/', ['X-Buggregator-Event' => 'html', 'X-Buggregator-Project' => 'shop'], 'shop'], 'header on any path')]
    #[DataSet(['/html/billing', ['X-Buggregator-Project' => 'shop'], 'billing'], 'path over header')]
    #[DataSet(['/html/my%20app/', [], 'my app'], 'decoded path')]
    #[DataSet(['/', ['Authorization' => 'Basic aHRtbDpiaWxsaW5n', 'X-Buggregator-Project' => 'other'], 'billing'], 'Basic password over header')]
    #[DataSet(['/html/shop', ['Authorization' => 'Basic aHRtbDpiaWxsaW5n'], 'shop'], 'path over Basic password')]
    public function testProject(string $path, array $headers, ?string $project): void
    {
        [, $frames] = self::handle(new ServerRequest('POST', $path, ['Content-Type' => 'text/html'] + $headers, PageTest::PAGE));

        Assert::count($frames, 1);
        Assert::same($frames[0]->project, $project);
    }

    #[DataSet(['application/json', '{"title":"x"}', 'The JSON body must be'], 'JSON without markup')]
    #[DataSet(['text/html', " \n ", 'The markup is empty.'], 'empty markup')]
    public function testRejectsMalformedPage(string $contentType, string $body, string $reason): void
    {
        $output = new BufferedOutput();

        [$response, $frames] = self::handle(new ServerRequest('POST', '/html', ['Content-Type' => $contentType], $body), new Logger($output));

        Assert::same($response->getStatusCode(), 400);
        Assert::string((string) $response->getBody())->startsWith($reason);
        Assert::same($frames, []);
        Assert::string($output->fetch())->contains('HTML: page rejected with 400');
    }

    public function testRejectsLargeBody(): void
    {
        $body = '<p>' . \str_repeat('a', HtmlTrap::MAX_BODY_SIZE) . '</p>';

        [$response, $frames] = self::handle(new ServerRequest('POST', '/html', ['Content-Type' => 'text/html'], $body));

        Assert::same($response->getStatusCode(), 413);
        Assert::same($frames, []);
    }

    /**
     * Runs the middleware the way the HTTP pipeline does: in a fiber that suspends with frames.
     *
     * @return array{ResponseInterface, list<Frame>}
     */
    private static function handle(ServerRequestInterface $request, ?Logger $logger = null): array
    {
        $trap = new HtmlTrap($logger);
        $frames = [];
        $fiber = new \Fiber(static fn(): ResponseInterface => $trap->handle($request, static fn(): Response => new Response(404)));
        $got = $fiber->start();
        while (!$fiber->isTerminated()) {
            $got instanceof Frame and $frames[] = $got;
            $got = $fiber->resume();
        }

        return [$fiber->getReturn(), $frames];
    }
}
