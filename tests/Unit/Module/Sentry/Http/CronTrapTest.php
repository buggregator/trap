<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Http;

use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope;
use Buggregator\Trap\Module\Sentry\Http\CronTrap;
use Buggregator\Trap\Server\Telemetry\Cron\CheckInMessage;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(CronTrap::class)]
#[Test]
final class CronTrapTest
{
    #[DataSet(['/ping/backup', 'ok'], 'finished')]
    #[DataSet(['/ping/backup/', 'ok'], 'trailing slash')]
    #[DataSet(['/ping/backup/start', 'in_progress'], 'started')]
    #[DataSet(['/ping/backup/fail', 'error'], 'failed')]
    public function testPingIsACheckIn(string $path, string $status): void
    {
        [$response, $frames] = self::handle(new ServerRequest('GET', $path));

        Assert::same($response->getStatusCode(), 200);
        Assert::same((string) $response->getBody(), "OK\n");
        Assert::count($frames, 1);
        Assert::same($frames[0]->items[0]->headers, ['type' => 'check_in', 'source' => 'ping']);
        Assert::same($frames[0]->items[0]->payload['status'], $status);
        Assert::same($frames[0]->items[0]->payload['monitor_slug'], 'backup');
    }

    public function testStartGetsAnIdAndAFinishWithoutOneHasNone(): void
    {
        [$started, $frames] = self::handle(new ServerRequest('GET', '/ping/backup/start'));
        [$finished] = self::handle(new ServerRequest('GET', '/ping/backup'));
        [$given] = self::handle(new ServerRequest('GET', '/ping/backup?check_in_id=ABC-1'));

        Assert::string($started->getHeaderLine('X-Check-In-Id'))->matchesRegex('/^[0-9a-f]{32}$/');
        Assert::same($frames[0]->items[0]->payload['check_in_id'], $started->getHeaderLine('X-Check-In-Id'));
        Assert::false($finished->hasHeader('X-Check-In-Id'));
        Assert::same($given->getHeaderLine('X-Check-In-Id'), 'abc1');
    }

    public function testPingQueryConfiguresTheMonitorAndBodyIsTheOutput(): void
    {
        $query = 'project=shop&environment=prod&schedule=' . \rawurlencode('0 3 * * *') . '&tz=Europe/Berlin&margin=5&max_runtime=30';
        $request = new ServerRequest('POST', "/ping/Nightly%20Backup?$query", [], "line 1\nline 2\n");

        [$response, $frames] = self::handle($request->withQueryParams(self::query($query)));

        Assert::same($response->getStatusCode(), 200);
        Assert::same($frames[0]->project, 'shop');
        Assert::same($frames[0]->items[0]->payload, [
            'monitor_slug' => 'nightly-backup',
            'status' => 'ok',
            'output' => "line 1\nline 2\n",
            'environment' => 'prod',
            'monitor_config' => [
                'schedule' => ['type' => 'crontab', 'value' => '0 3 * * *'],
                'timezone' => 'Europe/Berlin',
                'checkin_margin' => '5',
                'max_runtime' => '30',
            ],
        ]);
    }

    public function testPingBodyOfAnySizeGivesItsTail(): void
    {
        $body = \str_repeat("line of a long backup log\n", 60000) . "backup: done\n";

        [$response, $frames] = self::handle(new ServerRequest('POST', '/ping/backup', [], $body));

        Assert::same($response->getStatusCode(), 200);
        $output = $frames[0]->items[0]->payload['output'];
        Assert::same(\strlen($output), CheckInMessage::MAX_OUTPUT);
        Assert::string($output)->endsWith("backup: done\n");
    }

    public function testSentryCronBodyOverOneMegabyteIsRejected(): void
    {
        $body = \json_encode(['status' => 'ok', 'pad' => \str_repeat('x', CronTrap::MAX_BODY_SIZE)]);

        [$response, $frames] = self::handle(new ServerRequest('POST', '/api/1/cron/reports/sentry/', [], $body));

        Assert::same($response->getStatusCode(), 413);
        Assert::same($frames, []);
    }

    public function testPingProjectFallsBackToTheHeader(): void
    {
        [, $frames] = self::handle(new ServerRequest('GET', '/ping/backup', ['X-Buggregator-Project' => 'billing']));

        Assert::same($frames[0]->project, 'billing');
    }

    #[DataSet(['schedule=' . '61%20*%20*%20*%20*', 'invalid crontab `61 * * * *`: `61` is out of range'], 'bad crontab')]
    #[DataSet(['tz=Mars/Olympus', 'time zone'], 'bad time zone')]
    #[DataSet(['margin=0', 'checkin_margin'], 'margin 0')]
    #[DataSet(['max_runtime=x', 'max_runtime'], 'not a number')]
    public function testInvalidPingConfigIsRejected(string $query, string $reason): void
    {
        [$response, $frames] = self::handle((new ServerRequest('GET', "/ping/backup?$query"))->withQueryParams(self::query($query)));

        Assert::same($response->getStatusCode(), 400);
        Assert::string((string) $response->getBody())->contains($reason);
        Assert::same($frames, []);
    }

    public function testSentryCronEndpointTakesQueryOrBody(): void
    {
        $byQuery = (new ServerRequest('GET', '/api/1/cron/reports/sentry/?status=in_progress&environment=dev'))
            ->withQueryParams(['status' => 'in_progress', 'environment' => 'dev']);
        $byBody = new ServerRequest('POST', '/api/1/cron/reports/sentry', ['X-Buggregator-Project' => 'shop'], \json_encode([
            'status' => 'ok',
            'check_in_id' => 'c0ffee',
            'duration' => 1.5,
            'monitor_config' => ['schedule' => ['type' => 'interval', 'value' => 1, 'unit' => 'hour']],
        ]));

        [$queried, $queryFrames] = self::handle($byQuery);
        [$posted, $bodyFrames] = self::handle($byBody);

        Assert::same($queried->getStatusCode(), 202);
        $id = \json_decode((string) $queried->getBody(), true)['id'];
        Assert::same($queryFrames[0]->items[0]->payload, ['status' => 'in_progress', 'environment' => 'dev', 'check_in_id' => $id, 'monitor_slug' => 'reports']);
        Assert::same(\json_decode((string) $posted->getBody(), true), ['id' => 'c0ffee']);
        Assert::same($bodyFrames[0]->project, 'shop');
        Assert::same($bodyFrames[0]->items[0]->headers, ['type' => 'check_in']);
        Assert::same($bodyFrames[0]->items[0]->payload['duration'], 1.5);
    }

    #[DataSet(['GET', '/api/1/cron/reports/sentry/', ''], 'no status')]
    #[DataSet(['POST', '/api/1/cron/reports/sentry/', '[1]'], 'a list')]
    #[DataSet(['POST', '/api/1/cron/reports/sentry/', '{"status": "ok", "monitor_config": {"checkin_margin": -1}}'], 'bad config')]
    #[DataSet(['POST', '/api/1/cron/reports/sentry/', '{"status": "ok", "monitor_config": "0 3 * * *"}'], 'config of a string')]
    #[DataSet(['POST', '/api/1/cron/reports/sentry/', '{"status": "ok", "monitor_config": [1]}'], 'config of a list')]
    #[DataSet(['GET', '/ping/%21%21', ''], 'no slug')]
    public function testRejectsInvalidCheckIn(string $method, string $path, string $body): void
    {
        [$response, $frames] = self::handle(new ServerRequest($method, $path, [], $body));

        Assert::same($response->getStatusCode(), 400);
        Assert::same($frames, []);
    }

    #[DataSet(['PUT', '/ping/backup', []], 'PUT')]
    #[DataSet(['GET', '/ping/backup/finish', []], 'unknown action')]
    #[DataSet(['GET', '/pingx/backup', []], 'other path')]
    #[DataSet(['GET', '/api/1/envelope/', []], 'envelope')]
    #[DataSet(['GET', '/ping/backup', ['X-Buggregator-Event' => 'http-dump']], 'marked for another module')]
    public function testLeavesOtherRequests(string $method, string $path, array $headers): void
    {
        [$response, $frames] = self::handle(new ServerRequest($method, $path, $headers));

        Assert::same($response->getStatusCode(), 404);
        Assert::same($frames, []);
    }

    private static function query(string $query): array
    {
        \parse_str($query, $result);
        return $result;
    }

    /**
     * @return array{ResponseInterface, list<SentryEnvelope>}
     */
    private static function handle(ServerRequestInterface $request): array
    {
        $trap = new CronTrap();
        $frames = [];
        $fiber = new \Fiber(static fn(): ResponseInterface => $trap->handle($request, static fn(): Response => new Response(404)));
        $got = $fiber->start();
        while (!$fiber->isTerminated()) {
            $got instanceof SentryEnvelope and $frames[] = $got;
            $got = $fiber->resume();
        }

        return [$fiber->getReturn(), $frames];
    }
}
