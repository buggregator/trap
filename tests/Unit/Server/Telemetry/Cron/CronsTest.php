<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Telemetry\Cron;

use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Cron\CheckIn;
use Buggregator\Trap\Server\Telemetry\Cron\CheckInMessage;
use Buggregator\Trap\Server\Telemetry\Cron\Crons;
use Buggregator\Trap\Server\Telemetry\Cron\Monitor;
use Buggregator\Trap\Server\Telemetry\Cron\MonitorConfig;
use Buggregator\Trap\Server\Telemetry\Cron\Schedule;
use Buggregator\Trap\Server\Telemetry\TelemetryListener;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
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
        Assert::same($this->monitor()->nextExpectedAt, self::at('12:30'));
    }

    public function testOneUnknownRowForADowntimeWhateverTheChecksAfterIt(): void
    {
        $this->crons->record(self::checkIn('12:00:30', config: new MonitorConfig(Schedule::interval(1, 'minute'), margin: 5)));
        $this->crons->check((float) self::at('12:00:40'));

        for ($now = (float) self::at('13:00:00'), $end = $now + 299; $now <= $end; ++$now) {
            $this->crons->check($now);
        }

        $statuses = \array_map(static fn(CheckIn $run): string => $run->status, $this->monitor()->history());
        Assert::same($statuses, ['unknown', 'ok']);
        Assert::same($this->monitor()->history()[0]->expectedAt, (float) self::at('12:01'));
        Assert::same($this->monitor()->nextExpectedAt, self::at('13:00'));
    }

    public function testLongSleepOfManyMonitorsTakesOneStepEach(): void
    {
        for ($i = 0; $i < Crons::MAX_MONITORS; ++$i) {
            $this->crons->record(self::checkIn('12:00:30', slug: "job$i", config: new MonitorConfig(Schedule::crontab('* * * * *'))));
        }
        $this->crons->check((float) self::at('12:00:40'));

        $started = \microtime(true);
        $this->crons->check((float) self::at('12:00:40') + 14 * 86400);
        $took = \microtime(true) - $started;

        Assert::numeric($took)->lessThan(1.0);
        foreach ($this->crons->monitors() as $monitor) {
            Assert::same(\array_map(static fn(CheckIn $run): string => $run->status, $monitor->history()), ['unknown', 'ok']);
        }
    }

    public function testOlderRunEndingLateKeepsTheNextSlotOfTheNewerOne(): void
    {
        $config = self::every(5);
        $this->crons->record(self::checkIn('12:08:00', CheckInMessage::IN_PROGRESS, 'a', config: $config));
        $this->crons->record(self::checkIn('12:10:00', CheckInMessage::IN_PROGRESS, 'b'));
        $this->crons->record(self::checkIn('12:10:30', CheckInMessage::OK, 'b'));

        $this->crons->record(self::checkIn('12:10:40', CheckInMessage::IN_PROGRESS, 'a'));
        $this->crons->record(self::checkIn('12:11:00', CheckInMessage::OK, 'a'));
        $this->crons->check((float) self::at('12:14:30'));

        Assert::same($this->monitor()->nextExpectedAt, self::at('12:15'));
        Assert::same($this->monitor()->lastStatus(), 'ok');
    }

    public function testCheckInJustBeforeItsSlotIsTheRunOfThatSlot(): void
    {
        $config = new MonitorConfig(Schedule::crontab('0 * * * *'));

        $this->crons->record(self::checkIn('11:59:59', config: $config, time: self::at('11:59:59')));
        $this->crons->check((float) self::at('12:01:30'));

        Assert::same($this->monitor()->nextExpectedAt, self::at('13:00'));
        Assert::same($this->monitor()->lastStatus(), 'ok');
    }

    #[DataSet([59.999, '13:00'], 'just under a minute early')]
    #[DataSet([60.0, '12:00'], 'a minute early')]
    public function testEarlyCheckInIsTheRunOfTheSlotUnderAMinute(float $early, string $next): void
    {
        $config = new MonitorConfig(Schedule::crontab('0 * * * *'));

        $this->crons->record(new CheckInMessage('default', 'backup', '', 'ok', (float) self::at('12:00') - $early, config: $config));

        Assert::same($this->monitor()->nextExpectedAt, self::at($next));
    }

    #[DataSet(['11:59:50', '12:01'], 'under a quarter of the minute')]
    #[DataSet(['11:59:40', '12:00'], 'over a quarter of the minute')]
    public function testEarlyCheckInIsTheRunOfTheSlotUnderAQuarterOfThePeriod(string $at, string $next): void
    {
        $this->crons->record(self::checkIn($at, config: new MonitorConfig(Schedule::crontab('* * * * *'))));

        Assert::same($this->monitor()->nextExpectedAt, self::at($next));
    }

    public function testRunLateForASlotIsNotTheRunOfTheNextCloseOne(): void
    {
        // 40 seconds late for :00 is 20 seconds early for :01, but :01 comes only a minute after :00
        $this->crons->record(self::checkIn('12:00:40', config: new MonitorConfig(Schedule::crontab('0,1 * * * *'))));
        $next = $this->monitor()->nextExpectedAt;
        $this->crons->check((float) self::at('12:02:01'));

        Assert::same($next, self::at('12:01'));
        Assert::same($this->monitor()->lastStatus(), CheckIn::MISSED);
    }

    public function testEarlyRunWithTheSlotBeforeStillPendingIsTheRunOfThatSlot(): void
    {
        $config = new MonitorConfig(Schedule::crontab('* * * * *'));
        // The run of 11:59, then none for 12:00 until 12:00:50: late for 12:00, not early for 12:01
        $this->crons->record(self::checkIn('11:58:50', config: $config));
        $this->crons->record(self::checkIn('12:00:50'));
        $next = $this->monitor()->nextExpectedAt;
        $this->crons->check((float) self::at('12:02:01'));

        Assert::same($next, self::at('12:01'));
        Assert::same($this->monitor()->history()[0]->expectedAt, (float) self::at('12:01'));
        Assert::same($this->monitor()->lastStatus(), CheckIn::MISSED);
    }

    public function testScheduleThatFailsIsLoggedAndTheOtherMonitorsAreChecked(): void
    {
        $log = new BufferedOutput();
        $crons = new Crons(static function (): void {}, new Logger($log));
        $config = new MonitorConfig(Schedule::crontab('* * * * *'));
        $crons->record(self::checkIn('12:00:30', config: $config, slug: 'broken'));
        $crons->record(self::checkIn('12:00:30', config: $config, slug: 'fine'));
        $broken = $crons->monitor('default', 'broken');
        \assert($broken !== null);
        $broken->timezone = 'Nowhere/Never';

        $crons->check((float) self::at('12:02:30'));

        Assert::null($broken->nextExpectedAt);
        Assert::same($crons->monitor('default', 'fine')?->lastStatus(), CheckIn::MISSED);
        Assert::string($log->fetch())->contains('`broken` of the project `default` expects no slot more');
    }

    public function testScheduleThatFailsOnACheckInIsLogged(): void
    {
        $log = new BufferedOutput();
        $crons = new Crons(static function (): void {}, new Logger($log));
        $crons->record(self::checkIn('12:00:30', config: new MonitorConfig(Schedule::crontab('* * * * *'))));
        $monitor = $crons->monitor('default', 'backup');
        \assert($monitor !== null);
        $monitor->timezone = 'Nowhere/Never';

        $crons->record(self::checkIn('12:01:30'));

        Assert::null($monitor->nextExpectedAt);
        Assert::string($log->fetch())->contains('`backup` of the project `default` expects no slot more');
    }

    public function testEveryProjectIsToldWhateverTheChangeOfAnotherThrows(): void
    {
        $told = [];
        $log = new BufferedOutput();
        $crons = new Crons(static function (string $project) use (&$told): void {
            $told[] = $project;
            $project === 'a' and throw new \RuntimeException('closed socket');
        }, new Logger($log));
        $config = new MonitorConfig(maxRuntime: 1);
        $crons->record(self::checkIn('12:00:30', CheckInMessage::IN_PROGRESS, 'run1', config: $config, project: 'a'));
        $crons->record(self::checkIn('12:00:30', CheckInMessage::IN_PROGRESS, 'run2', config: $config, project: 'b'));

        $crons->check((float) self::at('12:02:00'));

        Assert::same($told, ['a', 'b']);
        Assert::same($crons->monitor('b', 'backup')?->lastStatus(), CheckIn::TIMEOUT);
        Assert::string($log->fetch())->contains('closed socket');
    }

    public function testDayIntervalKeepsItsWallTimeAfterASkippedOne(): void
    {
        $config = new MonitorConfig(Schedule::interval(1, 'day'), 'Europe/Berlin');
        // 02:30 CET on the day before clocks go forward: the next 02:30 does not exist
        $this->crons->record(new CheckInMessage('default', 'backup', '', 'ok', (float) self::time('2026-03-28T01:30:00Z'), config: $config));

        $this->crons->check((float) self::time('2026-03-29T01:31:01Z'));

        Assert::same($this->monitor()->history()[0]->expectedAt, (float) self::time('2026-03-29T01:30:00Z'));
        Assert::same($this->monitor()->nextExpectedAt, self::time('2026-03-30T00:30:00Z'));
    }

    public function testManyMissedSlotsAtOnceAreCappedAndTheNextComesAfterNow(): void
    {
        $this->crons->record(self::checkIn('12:00:30', config: new MonitorConfig(Schedule::crontab('* * * * *'))));
        $this->monitor()->nextExpectedAt = self::at('12:01') - 500 * 60;

        $this->crons->check((float) self::at('12:00:40'));

        Assert::count($this->monitor()->checkIns, Crons::MAX_CHECK_INS);
        Assert::same($this->monitor()->lastStatus(), 'ok');
        Assert::same($this->monitor()->nextExpectedAt, self::at('12:00'));
    }

    public function testScheduleWithoutAFurtherSlotIsExpectedNoMore(): void
    {
        $this->crons->record(self::checkIn('12:00', config: new MonitorConfig(Schedule::crontab('0 0 30 2 *'))));
        $this->monitor()->nextExpectedAt = self::at('11:00');

        $this->crons->check((float) self::at('12:00:30'));

        Assert::null($this->monitor()->nextExpectedAt);
        Assert::same(\array_map(static fn(CheckIn $run): string => $run->status, $this->monitor()->history()), ['ok', 'missed']);
    }

    public function testConfigUpsertMovesTheNextSlot(): void
    {
        $this->crons->record(self::checkIn('12:00:30', config: self::every(5)));

        $this->crons->record(self::checkIn('12:01:00', config: new MonitorConfig(Schedule::crontab('0 * * * *'))));

        Assert::same($this->monitor()->nextExpectedAt, self::at('13:00'));
    }

    public function testRunKeepsTheMaxRuntimeItStartedWith(): void
    {
        $this->crons->record(self::checkIn('12:00', CheckInMessage::IN_PROGRESS, 'run1', config: new MonitorConfig(maxRuntime: 10)));

        $this->crons->record(self::checkIn('12:05', CheckInMessage::IN_PROGRESS, 'run1', config: new MonitorConfig(maxRuntime: 60)));
        $this->crons->check((float) self::at('12:14:59'));
        $before = $this->monitor()->lastStatus();
        $this->crons->check((float) self::at('12:15:01'));

        Assert::same([$before, $this->monitor()->lastStatus(), $this->monitor()->maxRuntime], ['in_progress', 'timeout', 60]);
    }

    public function testOutputOfTheOldestRunsGoesAboveTheBudget(): void
    {
        $output = \str_repeat('x', CheckInMessage::MAX_OUTPUT);
        $runs = (int) \ceil(Crons::MAX_OUTPUT_BYTES / CheckInMessage::MAX_OUTPUT) + 5;
        for ($i = 0; $i < $runs; ++$i) {
            $this->crons->record(self::checkIn('12:00', slug: 'job' . \intdiv($i, 50), output: $output, time: self::at('12:00') + $i));
        }

        $bytes = 0;
        foreach ($this->crons->monitors() as $monitor) {
            foreach ($monitor->checkIns as $run) {
                $bytes += \strlen($run->output);
            }
        }
        Assert::int($bytes)->lessThanOrEqual(Crons::MAX_OUTPUT_BYTES);
        Assert::same($this->crons->monitor('default', 'job0')?->history()[49]->output, '');
        Assert::same(\strlen($this->crons->monitor('default', 'job16')?->history()[0]->output ?? ''), CheckInMessage::MAX_OUTPUT);
    }

    #[Covers(TelemetryStore::class)]
    public function testClockSetBackKeepsTheChecksGoing(): void
    {
        $now = (float) self::at('12:00:00');
        $store = new TelemetryStore(clock: static function () use (&$now): float {
            return $now;
        });
        $store->process();
        $now = (float) self::at('11:00:00');
        $batch = new Batch('default');
        $batch->checkIns[] = self::checkIn('11:00:00', CheckInMessage::IN_PROGRESS, 'run1', config: new MonitorConfig(maxRuntime: 1));
        $store->add($batch);

        for ($end = (float) self::at('11:01:05'); $now <= $end; $now += 0.25) {
            $store->process();
        }

        Assert::same($store->crons->monitor('default', 'backup')?->lastStatus(), 'timeout');
    }

    #[Covers(TelemetryStore::class)]
    public function testFailingListenerDoesNotStopTheMainLoop(): void
    {
        $now = (float) self::at('12:00:30');
        $store = new TelemetryStore(clock: static function () use (&$now): float {
            return $now;
        });
        $batch = new Batch('default');
        $batch->checkIns[] = self::checkIn('12:00:30', CheckInMessage::IN_PROGRESS, 'run1', config: new MonitorConfig(maxRuntime: 1));
        $store->add($batch);
        $store->listen(new class implements TelemetryListener {
            public function stored(string $project, array $kinds): void
            {
                throw new \RuntimeException('closed socket');
            }
        });

        $now = (float) self::at('12:02:00');
        $store->process();

        Assert::same($store->crons->monitor('default', 'backup')?->lastStatus(), 'timeout');
    }

    public function testFailingListenerIsLoggedAndTheNextOneIsTold(): void
    {
        $now = (float) self::at('12:00:30');
        $log = new BufferedOutput();
        $store = new TelemetryStore(clock: static function () use (&$now): float {
            return $now;
        }, logger: new Logger($log));
        $batch = new Batch('default');
        $batch->checkIns[] = self::checkIn('12:00:30', CheckInMessage::IN_PROGRESS, 'run1', config: new MonitorConfig(maxRuntime: 1));
        $store->add($batch);
        $store->listen(new class implements TelemetryListener {
            public function stored(string $project, array $kinds): void
            {
                throw new \RuntimeException('closed socket');
            }
        });
        $told = new \ArrayObject();
        $store->listen(new class($told) implements TelemetryListener {
            public function __construct(private readonly \ArrayObject $told) {}

            public function stored(string $project, array $kinds): void
            {
                $this->told->append([$project, $kinds]);
            }
        });

        $now = (float) self::at('12:02:00');
        $store->process();

        Assert::same($told->getArrayCopy(), [['default', ['cron']]]);
        Assert::string($log->fetch())->contains('Telemetry: a listener failed')->contains('closed socket');
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
        return self::time("2026-11-23T{$time}Z");
    }

    private static function time(string $iso): int
    {
        return (new \DateTimeImmutable($iso))->getTimestamp();
    }

    private function monitor(): Monitor
    {
        $monitor = $this->crons->monitor('default', 'backup');
        \assert($monitor !== null);

        return $monitor;
    }
}
