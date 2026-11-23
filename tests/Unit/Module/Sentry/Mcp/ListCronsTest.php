<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Mcp;

use Buggregator\Trap\Module\Sentry\Mcp\ListCrons;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Cron\CheckInMessage;
use Buggregator\Trap\Server\Telemetry\Cron\MonitorConfig;
use Buggregator\Trap\Server\Telemetry\Cron\Schedule;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Covers(ListCrons::class)]
#[Test]
final class ListCronsTest
{
    public function testListsMonitorsWithTheirNewestRuns(): void
    {
        $tool = new ListCrons(self::store());

        $result = $tool->call([]);

        Assert::same($result['total'], 2);
        Assert::same(\array_column($result['monitors'], 'slug'), ['invoices', 'backup']);
        $backup = $result['monitors'][1];
        Assert::same([$backup['schedule'], $backup['timezone'], $backup['checkin_margin'], $backup['max_runtime']], ['every 5 minutes', 'UTC', 1, 30]);
        Assert::same([$backup['last_status'], $backup['total_check_ins'], $backup['next_expected']], ['missed', 3, '1970-01-01T00:25:00.000+00:00']);
        Assert::same(\array_column($backup['check_ins'], 'status'), ['missed', 'error', 'ok']);
        Assert::same($backup['check_ins'][1]['output'], 'disk full');
        Assert::same($backup['check_ins'][0]['expected'], '1970-01-01T00:20:00.000+00:00');
        Assert::null($backup['check_ins'][1]['expected']);
    }

    public function testNarrowsByProjectSlugAndAttention(): void
    {
        $tool = new ListCrons(self::store());

        Assert::same(\array_column($tool->call(['project' => 'billing'])['monitors'], 'slug'), ['invoices']);
        Assert::same(\array_column($tool->call(['slug' => 'backup'])['monitors'], 'slug'), ['backup']);
        Assert::same(\array_column($tool->call(['attention' => true])['monitors'], 'slug'), ['backup']);
        Assert::count($tool->call(['check_ins' => 1])['monitors'][1]['check_ins'], 1);
        Assert::same($tool->call(['check_ins' => 0])['monitors'][1]['check_ins'], []);
    }

    public function testRejectsAListForAString(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('`slug` must be a string');

        (new ListCrons(new TelemetryStore()))->call(['slug' => ['a']]);
    }

    private static function store(): TelemetryStore
    {
        $store = new TelemetryStore();
        $batch = new Batch('default');
        $every = new MonitorConfig(Schedule::interval(5, 'minute'));
        $batch->checkIns[] = new CheckInMessage('default', 'backup', '', 'ok', 600.0, config: $every);
        $batch->checkIns[] = new CheckInMessage('default', 'backup', '', 'error', 900.0, source: 'ping', output: 'disk full');
        $batch->checkIns[] = new CheckInMessage('billing', 'invoices', '', 'ok', 900.0);
        $store->add($batch);
        $store->crons->check(1300.0);

        return $store;
    }
}
