<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Telemetry\Cron;

use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Cron\CheckIn;
use Buggregator\Trap\Server\Telemetry\Cron\CheckInMessage;
use Buggregator\Trap\Server\Telemetry\Cron\Crons;
use Buggregator\Trap\Server\Telemetry\Cron\Monitor;
use Buggregator\Trap\Server\Telemetry\Cron\MonitorConfig;
use Buggregator\Trap\Server\Telemetry\Cron\Schedule;
use Buggregator\Trap\Server\Telemetry\TelemetryListener;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(Crons::class)]
#[Covers(Monitor::class)]
#[Covers(MonitorConfig::class)]
#[Covers(CheckInMessage::class)]
#[Test]
final class CronsTest
{
    private Crons $crons;

    /** @var list<string> */
    private array $changed = [];

    #[BeforeTest]
    public function createStore(): void
    {
        $this->changed = [];
        $this->crons = new Crons(function (string $project): void {
            $this->changed[] = $project;
        });
    }

    public function testFirstCheckInMakesTheMonitorAndItsEndClosesTheRun(): void
    {
        $config = new MonitorConfig(Schedule::crontab('*/5 * * * *'), 'Europe/Berlin', 2, 10);

        $id = $this->crons->record(self::checkIn('12:00:10', CheckInMessage::IN_PROGRESS, 'run1', config: $config));
        $monitor = $this->crons->monitor('default', 'backup');
        $running = $monitor?->history()[0];
        $this->crons->record(self::checkIn('12:01:10', CheckInMessage::OK, 'run1'));

        Assert::same($id, 'run1');
        \assert($monitor !== null && $running !== null);
        Assert::same([$monitor->timezone, $monitor->margin, $monitor->maxRuntime], ['Europe/Berlin', 2, 10]);
        Assert::same($monitor->nextExpectedAt, self::at('12:05:00'));
        Assert::same($running->status, CheckInMessage::OK);
        Assert::same($running->durationMs, 60000);
        Assert::same($running->finishedAt, (float) self::at('12:01:10'));
        Assert::same($monitor->lastStatus(), 'ok');
        Assert::count($monitor->checkIns, 1);
    }

    public function testConfigLeftOutKeepsTheValueAndNullResetsIt(): void
    {
        $this->crons->record(self::checkIn('12:00', config: MonitorConfig::fromArray([
            'schedule' => ['type' => 'interval', 'value' => 10, 'unit' => 'minute'],
            'checkin_margin' => 5,
            'timezone' => 'Asia/Tokyo',
        ])));
        $this->crons->record(self::checkIn('12:10', config: MonitorConfig::fromArray(['max_runtime' => 60])));
        $kept = $this->monitor();
        $kept = [$kept->schedule?->describe(), $kept->margin, $kept->timezone, $kept->maxRuntime];
        $this->crons->record(self::checkIn('12:20', config: MonitorConfig::fromArray(['checkin_margin' => null, 'timezone' => null])));

        Assert::same($kept, ['every 10 minutes', 5, 'Asia/Tokyo', 60]);
        Assert::same([$this->monitor()->margin, $this->monitor()->timezone], [1, 'UTC']);
    }

    public function testCheckInOfItsOwnWithADurationStartedThatMuchEarlier(): void
    {
        $this->crons->record(self::checkIn('12:00:10', CheckInMessage::ERROR, duration: 4.5));

        $run = $this->monitor()->history()[0];
        Assert::same([$run->status, $run->durationMs, $run->startedAt], ['error', 4500, self::at('12:00:10') - 4.5]);
    }

    public function testSlotWithoutACheckInWithinTheMarginIsMissed(): void
    {
        $this->crons->record(self::checkIn('12:00:30', config: self::every(5)));

        $this->crons->check((float) self::at('12:06:00'));
        $before = \count($this->monitor()->checkIns);
        $this->crons->check((float) self::at('12:06:01'));

        $missed = $this->monitor()->history()[0];
        Assert::same($before, 1);
        Assert::same([$missed->status, $missed->expectedAt, $missed->serverMade()], ['missed', (float) self::at('12:05'), true]);
        Assert::same($this->monitor()->nextExpectedAt, self::at('12:10'));
        Assert::same($this->changed, ['default']);
    }

    public function testCheckInAfterAMissedSlotExpectsTheSlotAfterIt(): void
    {
        $this->crons->record(self::checkIn('12:00:30', config: self::every(5)));
        $this->crons->check((float) self::at('12:06:30'));

        $this->crons->record(self::checkIn('12:07:00'));

        Assert::same($this->monitor()->nextExpectedAt, self::at('12:12'));
        Assert::same(\array_map(static fn(CheckIn $run): string => $run->status, $this->monitor()->history()), ['ok', 'missed', 'ok']);
    }

    public function testRunInProgressIsNotMissedButTimesOut(): void
    {
        $this->crons->record(self::checkIn('12:00:30', CheckInMessage::IN_PROGRESS, 'run1', config: new MonitorConfig(Schedule::interval(5, 'minute'), maxRuntime: 10)));

        $this->crons->check((float) self::at('12:09:00'));
        $running = $this->monitor()->lastStatus();
        $this->crons->check((float) self::at('12:10:31'));
        $timedOut = $this->monitor()->lastStatus();
        $next = $this->monitor()->nextExpectedAt;
        $this->crons->record(self::checkIn('12:12:00', CheckInMessage::OK, 'run1'));

        Assert::same([$running, $timedOut], ['in_progress', 'timeout']);
        Assert::same($next, self::at('12:15'));
        $run = $this->monitor()->history()[0];
        Assert::same([$run->status, $run->durationMs], ['timeout', 690000]);
        Assert::count($this->monitor()->checkIns, 1);
    }

    public function testInProgressAgainExtendsTheMaxRuntime(): void
    {
        $config = new MonitorConfig(maxRuntime: 10);
        $this->crons->record(self::checkIn('12:00', CheckInMessage::IN_PROGRESS, 'run1', config: $config));

        $this->crons->record(self::checkIn('12:09', CheckInMessage::IN_PROGRESS, 'run1'));
        $this->crons->check((float) self::at('12:11'));
        $extended = $this->monitor()->lastStatus();
        $this->crons->check((float) self::at('12:19:01'));

        Assert::same([$extended, $this->monitor()->lastStatus()], ['in_progress', 'timeout']);
    }

    public function testFinishedRunIsFinal(): void
    {
        $this->crons->record(self::checkIn('12:00', CheckInMessage::OK, 'run1', duration: 1.0));

        $this->crons->record(self::checkIn('12:01', CheckInMessage::IN_PROGRESS, 'run1'));
        $this->crons->record(self::checkIn('12:02', CheckInMessage::ERROR, 'run1'));

        $run = $this->monitor()->history()[0];
        Assert::same([$run->status, $run->durationMs], ['ok', 1000]);
        Assert::count($this->monitor()->checkIns, 1);
    }

    public function testSlotsWhileTrapWasNotRunningAreOneUnknownRow(): void
    {
        $this->crons->record(self::checkIn('12:00:30', config: self::every(1)));
        $this->crons->check((float) self::at('12:00:40'));

        $this->crons->check((float) self::at('12:30:00'));

        $statuses = \array_map(static fn(CheckIn $run): string => $run->status, $this->monitor()->history());
        Assert::same($statuses, ['unknown', 'ok']);
        Assert::same($this->monitor()->nextExpectedAt, self::at('12:29'));
    }

    public function testMonitorWithoutAScheduleIsNeverMissed(): void
    {
        $this->crons->record(self::checkIn('12:00'));

        $this->crons->check((float) self::at('23:00'));

        Assert::null($this->monitor()->nextExpectedAt);
        Assert::count($this->monitor()->checkIns, 1);
        Assert::same($this->changed, []);
    }

    public function testPingWithoutAnIdEndsTheNewestStartedPingRun(): void
    {
        $this->crons->record(self::checkIn('12:00', CheckInMessage::IN_PROGRESS, 'a', source: CheckInMessage::SOURCE_PING));
        $this->crons->record(self::checkIn('12:01', CheckInMessage::IN_PROGRESS, 'b', source: CheckInMessage::SOURCE_PING));

        $id = $this->crons->record(self::checkIn('12:02', CheckInMessage::ERROR, source: CheckInMessage::SOURCE_PING, output: 'disk full'));

        $statuses = \array_map(static fn(CheckIn $run): string => "$run->id $run->status $run->output", $this->monitor()->history());
        Assert::same($id, 'b');
        Assert::same($statuses, ['b error disk full', 'a in_progress ']);
    }

    public function testKeepsTheNewestCheckIns(): void
    {
        for ($i = 0; $i < Crons::MAX_CHECK_INS + 5; ++$i) {
            $this->crons->record(self::checkIn('12:00', id: "run$i", time: self::at('12:00') + $i));
        }

        $history = $this->monitor()->history();
        Assert::count($history, Crons::MAX_CHECK_INS);
        Assert::same([$history[0]->id, \end($history)->id], ['run104', 'run5']);
    }

    public function testNewMonitorPushesOutTheLeastRecentlyActiveOne(): void
    {
        for ($i = 0; $i < Crons::MAX_MONITORS; ++$i) {
            $this->crons->record(self::checkIn('12:00', slug: "job$i", time: self::at('12:00') + $i));
        }
        $this->crons->record(self::checkIn('13:00', slug: 'job0'));

        $this->crons->record(self::checkIn('13:01', slug: 'new'));

        Assert::count($this->crons->monitors(), Crons::MAX_MONITORS);
        Assert::null($this->crons->monitor('default', 'job1'));
        Assert::notBlank($this->crons->monitor('default', 'job0'));
    }

    public function testClearDeletesTheRunsOfTheProjectAndKeepsTheMonitors(): void
    {
        $this->crons->record(self::checkIn('12:00', config: self::every(5)));
        $this->crons->record(self::checkIn('12:00', slug: 'billing-job', project: 'billing'));

        $this->crons->clear('default');

        Assert::same($this->monitor()->checkIns, []);
        Assert::same([$this->monitor()->lastStatus(), $this->monitor()->nextExpectedAt], ['', self::at('12:05')]);
        Assert::count($this->crons->monitor('billing', 'billing-job')?->checkIns ?? [], 1);
    }

    public function testDeletesAMonitorOfAProject(): void
    {
        $this->crons->record(self::checkIn('12:00', project: 'billing'));

        $other = $this->crons->delete('default', 'backup');
        $deleted = $this->crons->delete('billing', 'backup');

        Assert::same([$other, $deleted], [false, true]);
        Assert::same($this->crons->monitors(), []);
        Assert::same($this->changed, ['billing']);
    }

    public function testListsByProjectThenSlug(): void
    {
        foreach ([['b', 'zeta'], ['a', 'beta'], ['b', 'alpha']] as [$project, $slug]) {
            $this->crons->record(self::checkIn('12:00', slug: $slug, project: $project));
        }

        $list = \array_map(static fn(Monitor $m): string => "$m->project/$m->slug", $this->crons->monitors());

        Assert::same($list, ['a/beta', 'b/alpha', 'b/zeta']);
        Assert::count($this->crons->monitors('b'), 2);
    }

    #[Covers(TelemetryStore::class)]
    public function testStoreTellsTheCronChangesAndChecksAtMostOnceASecond(): void
    {
        $now = (float) self::at('12:00:30');
        $store = new TelemetryStore(clock: static function () use (&$now): float {
            return $now;
        });
        $told = [];
        $store->listen(new class($told) implements TelemetryListener {
            public function __construct(private array &$told) {}

            public function stored(string $project, array $kinds): void
            {
                $this->told[] = $project . ':' . \implode(',', $kinds);
            }
        });
        $batch = new Batch('shop');
        $batch->checkIns[] = self::checkIn('12:00:30', project: 'shop', config: self::every(1));

        $store->add($batch);
        for ($end = (float) self::at('12:02:01'); $now <= $end; $now += 0.25) {
            $store->process();
        }

        Assert::same($told, ['shop:cron', 'shop:cron']);
        Assert::same($store->crons->monitor('shop', 'backup')?->lastStatus(), 'missed');
    }

    public function testMakesSlugsAsSentryDoes(): void
    {
        Assert::same(CheckInMessage::slug('  Reports Send!  '), 'reports-send');
        Assert::same(CheckInMessage::slug('app:sync_users'), 'appsync_users');
        Assert::same(CheckInMessage::slug(\str_repeat('a', 60)), \str_repeat('a', 50));
        Assert::null(CheckInMessage::slug('!!!'));
        Assert::same(CheckInMessage::checkInId('5A9D2C1E-0000-4000-8000-00000000ABCD'), '5a9d2c1e00004000800000000000abcd');
    }

    public function testOutputKeepsTheValidTail(): void
    {
        $output = CheckInMessage::output('é' . \str_repeat('x', CheckInMessage::MAX_OUTPUT - 6) . "\xFFdone");

        Assert::same(\strlen($output), CheckInMessage::MAX_OUTPUT - 2);
        Assert::string($output)->endsWith('xxdone');
    }

    private static function checkIn(
        string $at,
        string $status = CheckInMessage::OK,
        string $id = '',
        ?float $duration = null,
        string $source = CheckInMessage::SOURCE_SENTRY,
        string $output = '',
        ?MonitorConfig $config = null,
        string $slug = 'backup',
        string $project = 'default',
        ?int $time = null,
    ): CheckInMessage {
        return new CheckInMessage(
            project: $project,
            slug: $slug,
            checkInId: $id,
            status: $status,
            time: (float) ($time ?? self::at($at)),
            duration: $duration,
            source: $source,
            output: $output,
            config: $config,
        );
    }

    private static function every(int $minutes): MonitorConfig
    {
        return new MonitorConfig(Schedule::interval($minutes, 'minute'));
    }

    private static function at(string $time): int
    {
        return (new \DateTimeImmutable("2026-11-23T{$time}Z"))->getTimestamp();
    }

    private function monitor(): Monitor
    {
        $monitor = $this->crons->monitor('default', 'backup');
        \assert($monitor !== null);

        return $monitor;
    }
}
