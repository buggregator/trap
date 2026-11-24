<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Error;
use Buggregator\Trap\Module\Frontend\Message\Success;
use Buggregator\Trap\Module\Sentry\Frontend\ClearController;
use Buggregator\Trap\Module\Sentry\Frontend\CronController;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Cron\CheckInMessage;
use Buggregator\Trap\Server\Telemetry\Cron\MonitorConfig;
use Buggregator\Trap\Server\Telemetry\Cron\Schedule;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(CronController::class)]
#[Test]
final class CronControllerTest
{
    private TelemetryStore $telemetry;
    private CronController $api;

    #[BeforeTest]
    public function createStore(): void
    {
        $this->telemetry = new TelemetryStore();
        $this->api = new CronController($this->telemetry);
    }

    public function testListsMonitorsInTheShapeOfTheUi(): void
    {
        $this->fill();

        $list = $this->api->list('default');

        Assert::same($list, ['data' => [[
            'project' => 'default',
            'slug' => 'backup',
            'source' => 'sentry',
            'schedule' => ['type' => 'crontab', 'value' => '*/5 * * * *'],
            'timezone' => 'Europe/Berlin',
            'checkin_margin' => 2,
            'max_runtime' => 10,
            'last_status' => 'missed',
            'last_checkin_at' => '2026-11-23T12:01:00.000000Z',
            'next_expected_at' => '2026-11-23T12:10:00.000000Z',
            'created_at' => '2026-11-23T12:00:30.000000Z',
            'recent' => [
                ['status' => 'missed', 'started_at' => '2026-11-23T12:05:00.000000Z'],
                ['status' => 'ok', 'started_at' => '2026-11-23T12:00:30.000000Z'],
            ],
        ]]]);
    }

    public function testListsEveryProjectWithoutOne(): void
    {
        $this->fill();

        $slugs = \array_map(static fn(array $m): string => "{$m['project']}/{$m['slug']}", $this->api->list()['data']);

        Assert::same($slugs, ['billing/invoices', 'default/backup']);
        Assert::same($this->api->list('shop'), ['data' => []]);
    }

    public function testCheckInsNewestFirstByPage(): void
    {
        $this->fill();

        $first = $this->api->checkIns('backup', null, 1, 1);
        $second = $this->api->checkIns('backup', 'default', 2, 1);

        \assert(\is_array($first) && \is_array($second));
        Assert::same($first['meta'], ['total' => 2, 'page' => 1, 'limit' => 1]);
        Assert::same($first['data'][0], [
            'id' => $first['data'][0]['id'],
            'status' => 'missed',
            'started_at' => '2026-11-23T12:05:00.000000Z',
            'finished_at' => null,
            'duration_ms' => null,
            'environment' => '',
            'trace_id' => '',
            'source' => 'sentry',
            'body' => '',
            'server_made' => true,
            'expected_at' => '2026-11-23T12:05:00.000000Z',
        ]);
        Assert::same([$second['data'][0]['id'], $second['data'][0]['status'], $second['data'][0]['duration_ms']], ['run1', 'ok', 30000]);
        Assert::same([$second['data'][0]['finished_at'], $second['data'][0]['server_made']], ['2026-11-23T12:01:00.000000Z', false]);
    }

    public function testFindsTheSlugAsTheJobSentIt(): void
    {
        $this->fill();

        $runs = $this->api->checkIns('Back%20up', 'billing');
        $found = $this->api->checkIns('BACKUP');

        Assert::instanceOf($runs, Error::class);
        Assert::same(\is_array($found) ? $found['meta']['total'] : null, 2);
    }

    public function testUnknownMonitorIsNotFound(): void
    {
        $this->fill();

        Assert::instanceOf($this->api->checkIns('backup', 'billing'), Error::class);
        Assert::instanceOf($this->api->checkIns('nope'), Error::class);
        Assert::instanceOf($this->api->delete('nope'), Error::class);
    }

    public function testDeletesAMonitor(): void
    {
        $this->fill();

        Assert::instanceOf($this->api->delete('invoices', 'billing'), Success::class);

        Assert::same(\count($this->api->list()['data']), 1);
    }

    #[Covers(ClearController::class)]
    public function testClearingTheSentrySectionKeepsTheMonitorsOfOtherProjects(): void
    {
        $this->fill();
        $clear = new ClearController(new Logger(), new EventStore(), $this->telemetry);

        $clear->clear('default', '1');

        $list = $this->api->list()['data'];
        Assert::same([\count($list[0]['recent']), \count($list[1]['recent'])], [1, 0]);
        Assert::same([$list[1]['slug'], $list[1]['last_status'], $list[1]['next_expected_at']], ['backup', '', '2026-11-23T12:10:00.000000Z']);
    }

    private static function at(string $time): float
    {
        return (float) (new \DateTimeImmutable("2026-11-23T{$time}Z"))->getTimestamp();
    }

    private function fill(): void
    {
        $batch = new Batch('default');
        $config = new MonitorConfig(Schedule::crontab('*/5 * * * *'), 'Europe/Berlin', 2, 10);
        $batch->checkIns[] = new CheckInMessage('default', 'backup', 'run1', 'in_progress', self::at('12:00:30'), config: $config);
        $batch->checkIns[] = new CheckInMessage('default', 'backup', 'run1', 'ok', self::at('12:01:00'));
        $batch->checkIns[] = new CheckInMessage('billing', 'invoices', '', 'ok', self::at('12:02:00'));
        $this->telemetry->add($batch);
        $this->telemetry->crons->check(self::at('12:07:01'));
    }
}
