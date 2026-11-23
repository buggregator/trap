<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Csp\Http;

use Buggregator\Trap\Module\Csp\Frame;
use Buggregator\Trap\Module\Csp\Http\CspTrap;
use Buggregator\Trap\Module\Csp\Report;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Tests\Unit\Module\Csp\ReportTest;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(CspTrap::class)]
#[Test]
final class CspTrapTest
{
    #[DataSet(['/some/report', 'application/csp-report', ReportTest::CSP_REPORT, Report::FORMAT_CSP], 'csp-report on any path')]
    #[DataSet(['/', 'application/reports+json', ReportTest::REPORTS, Report::FORMAT_REPORTS], 'reports+json on any path')]
    #[DataSet(['/csp', 'application/json', ReportTest::CSP_REPORT, Report::FORMAT_CSP], 'any type to /csp')]
    #[DataSet(['/csp/shop', '', ReportTest::REPORTS, Report::FORMAT_REPORTS], 'no type to /csp/project')]
    public function testTakesReport(string $path, string $contentType, string $body, string $format): void
    {
        $time = new \DateTimeImmutable('2026-01-02 03:04:05');
        $request = (new ServerRequest('POST', $path, ['Content-Type' => $contentType], $body))->withAttribute('begin_at', $time);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 204);
        Assert::same((string) $response->getBody(), '');
        Assert::count($frames, 1);
        Assert::same($frames[0]->format, $format);
        Assert::same($frames[0]->time, $time);
    }

    #[DataSet(['GET', '/csp', 'application/csp-report', []], 'GET')]
    #[DataSet(['POST', '/api/x', 'application/json', []], 'other JSON elsewhere')]
    #[DataSet(['POST', '/cspx', 'application/json', []], 'a path that only starts with csp')]
    #[DataSet(['POST', '/csp', 'application/csp-report', ['X-Buggregator-Event' => 'http-dump']], 'marked for HTTP dumps')]
    #[DataSet(['POST', '/csp', 'application/csp-report', ['X-Buggregator-Event' => 'sentry']], 'marked for Sentry')]
    #[DataSet(['POST', '/csp', 'application/csp-report', ['Authorization' => 'Basic aHR0cC1kdW1wOg==']], 'Basic user http-dump')]
    public function testLeavesOtherRequests(string $method, string $path, string $contentType, array $headers): void
    {
        [$response, $frames] = self::handle(new ServerRequest($method, $path, ['Content-Type' => $contentType] + $headers, ReportTest::CSP_REPORT));

        Assert::same($response->getStatusCode(), 404);
        Assert::same($frames, []);
    }

    #[DataSet([['X-Buggregator-Event' => 'CSP'], 'application/csp-report'], 'header')]
    #[DataSet([['Authorization' => 'Basic Y3NwOg=='], 'application/csp-report'], 'Basic user')]
    #[DataSet([['X-Buggregator-Event' => 'csp'], 'application/json'], 'header, another type')]
    #[DataSet([['Authorization' => 'Basic Y3NwOnNob3A='], ''], 'Basic user, no type')]
    #[DataSet([['X-Buggregator-Event' => 'csp', 'Authorization' => 'Basic dXNlcjpzM2NyM3Q='], 'text/plain'], 'header over a Basic user')]
    public function testTakesRequestMarkedForCspOnAnyPath(array $headers, string $contentType): void
    {
        $request = new ServerRequest('POST', '/other', ['Content-Type' => $contentType] + $headers, ReportTest::CSP_REPORT);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 204);
        Assert::count($frames, 1);
    }

    #[DataSet(['/csp', [], null], 'none')]
    #[DataSet(['/csp', ['X-Buggregator-Project' => ' shop '], 'shop'], 'header')]
    #[DataSet(['/some/report', ['X-Buggregator-Project' => 'shop'], 'shop'], 'header on any path')]
    #[DataSet(['/csp/billing', ['X-Buggregator-Project' => 'shop'], 'billing'], 'path over header')]
    #[DataSet(['/csp/my%20app/', [], 'my app'], 'decoded path')]
    #[DataSet(['/csp', ['Authorization' => 'Basic Y3NwOnNob3A=', 'X-Buggregator-Project' => 'other'], 'shop'], 'Basic password over header')]
    #[DataSet(['/csp/billing', ['Authorization' => 'Basic Y3NwOnNob3A='], 'billing'], 'path over Basic password')]
    public function testProject(string $path, array $headers, ?string $project): void
    {
        [, $frames] = self::handle(new ServerRequest('POST', $path, ['Content-Type' => 'application/csp-report'] + $headers, ReportTest::CSP_REPORT));

        Assert::count($frames, 1);
        Assert::same($frames[0]->project, $project);
    }

    #[DataSet(['{"x":1}'], 'no report')]
    #[DataSet(['[]'], 'empty list')]
    #[DataSet(['not json'], 'not JSON')]
    public function testRejectsMalformedReport(string $body): void
    {
        $output = new BufferedOutput();

        [$response, $frames] = self::handle(new ServerRequest('POST', '/csp', [], $body), new Logger($output));

        Assert::same($response->getStatusCode(), 400);
        Assert::same($frames, []);
        Assert::string($output->fetch())->contains('CSP: report rejected with 400');
    }

    public function testRejectsLargeBody(): void
    {
        $body = '{"csp-report":{"script-sample":"' . \str_repeat('a', CspTrap::MAX_BODY_SIZE) . '"}}';

        [$response, $frames] = self::handle(new ServerRequest('POST', '/csp', ['Content-Type' => 'application/csp-report'], $body));

        Assert::same($response->getStatusCode(), 413);
        Assert::same($frames, []);
    }

    /**
     * Chrome sends Reporting API reports to another origin only after a preflight.
     */
    public function testAnswersPreflightOfReportPaths(): void
    {
        $request = new ServerRequest('OPTIONS', '/csp/shop', [
            'Origin' => 'https://localhost:8443',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ]);

        [$response, $frames] = self::handle($request);
        [$other] = self::handle(new ServerRequest('OPTIONS', '/api/x'));

        Assert::same($response->getStatusCode(), 204);
        Assert::same($response->getHeaderLine('Access-Control-Allow-Origin'), '*');
        Assert::same($response->getHeaderLine('Access-Control-Allow-Methods'), 'POST');
        Assert::same($response->getHeaderLine('Access-Control-Allow-Headers'), 'Content-Type');
        Assert::same($frames, []);
        Assert::same($other->getStatusCode(), 404);
    }

    /**
     * Runs the middleware the way the HTTP pipeline does: in a fiber that suspends with frames.
     *
     * @return array{ResponseInterface, list<Frame>}
     */
    private static function handle(ServerRequestInterface $request, ?Logger $logger = null): array
    {
        $trap = new CspTrap($logger);
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
