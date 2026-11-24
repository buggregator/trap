<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Telemetry\Insight;

use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Insight\Finding;
use Buggregator\Trap\Server\Telemetry\Insight\RepeatedQuery;
use Buggregator\Trap\Server\Telemetry\Insight\TraceInsights;
use Buggregator\Trap\Server\Telemetry\Insight\TraceRule;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Buggregator\Trap\Server\Telemetry\Trace;
use Buggregator\Trap\Tests\Unit\Server\Telemetry\Insight\Stub\Traces;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TraceInsights::class)]
final class TraceInsightsTest
{
    public function testDefaultRulesFindRepeatedQueries(): void
    {
        Assert::array(TraceInsights::defaultRules())->hasCount(1);
        Assert::instanceOf(TraceInsights::defaultRules()[0], RepeatedQuery::class);
    }

    public function testHeaviestFirstThenMostSpansThenRuleOrder(): void
    {
        $insights = new TraceInsights([
            self::rule([self::finding('light', 5, 10.0), self::finding('heavy', 5, 90.0)]),
            self::rule([self::finding('fewer', 5, 50.0), self::finding('more', 9, 50.0), self::finding('tie', 5, 10.0)]),
        ]);

        $findings = $insights->of(new Trace(Traces::ID));

        Assert::same(\array_map(static fn(Finding $f): string => $f->summary, $findings), ['heavy', 'more', 'fewer', 'light', 'tie']);
    }

    public function testReusesFindingsUntilTraceChanges(): void
    {
        $store = new TelemetryStore();
        $store->add(self::batch('shop', 'a'));
        $trace = $store->trace(Traces::ID);
        $calls = 0;
        $insights = new TraceInsights([self::rule([], $calls)]);

        $insights->of($trace);
        $insights->of($trace);

        Assert::same($calls, 1);

        $store->add(self::batch('shop', 'b'));
        $insights->of($trace);

        Assert::same($calls, 2);

        $store->add(self::batch('other', 'c'));
        $insights->of($trace);
        $store->clear('other');
        $insights->of($trace);

        Assert::same($calls, 4);
    }

    public function testAnotherPipelineDoesNotTakeTheCache(): void
    {
        $trace = new Trace(Traces::ID);
        (new TraceInsights([self::rule([self::finding('x', 5, 1.0)])]))->of($trace);

        $findings = (new TraceInsights([self::rule([])]))->of($trace);

        Assert::same($findings, []);
    }

    public function testProjectsSeeOnlyTheirSpans(): void
    {
        $trace = Traces::trace([Traces::transaction('txn', 'r')]);
        for ($i = 0; $i < 7; ++$i) {
            Traces::add($trace, Traces::db("q$i", 'r', 'txn', 'SELECT * FROM t WHERE id = ?', 2 ** -10, project: $i < 5 ? 'shop' : 'billing'));
        }
        $insights = new TraceInsights();

        $all = $insights->of($trace);
        $shop = $insights->of($trace, ['shop']);

        Assert::same([$all[0]->count, $all[0]->totalMs], [7, 6.836]);
        Assert::same([$shop[0]->count, $shop[0]->totalMs, $shop[0]->spanIds], [5, 4.883, ['q0', 'q1', 'q2', 'q3', 'q4']]);
        Assert::same($insights->of($trace, ['billing']), []);
        Assert::same($insights->of($trace, ['none']), []);
    }

    public function testKeepsFindingsOfEachProjectSet(): void
    {
        $trace = new Trace(Traces::ID);
        $calls = 0;
        $insights = new TraceInsights([self::rule([], $calls)]);

        $insights->of($trace);
        $insights->of($trace, ['b', 'a']);
        $insights->of($trace, ['a', 'b', 'a']);
        $insights->of($trace);

        Assert::same($calls, 2);
    }

    public function testProjectSetsDoNotCollide(): void
    {
        $trace = new Trace(Traces::ID);
        Traces::add($trace, Traces::span('s1', null, null, 'internal', 'work', project: 'a'));
        Traces::add($trace, Traces::span('s2', null, null, 'internal', 'work', project: 'b'));
        $insights = new TraceInsights([self::projectsRule()]);

        $both = $insights->of($trace, ['a', 'b']);
        $joined = $insights->of($trace, ["a\0b"]);

        Assert::same($both[0]->summary, 'a,b');
        Assert::same($joined[0]->summary, 'none');
    }

    public function testKeepsAtMostSixteenProjectSetsOfDigitNames(): void
    {
        $trace = new Trace(Traces::ID);
        for ($p = 1; $p <= 20; ++$p) {
            Traces::add($trace, Traces::span("s$p", null, null, 'internal', 'work', project: (string) $p));
        }
        $insights = new TraceInsights([self::projectsRule()]);

        for ($p = 1; $p <= 20; ++$p) {
            $insights->of($trace, [(string) $p]);
        }

        Assert::count($trace->insights, TraceInsights::MAX_SCOPES);
        Assert::same($insights->of($trace, ['2'])[0]->summary, '2');
        Assert::same($insights->of($trace, ['20'])[0]->summary, '20');
        Assert::same($insights->of($trace, ['4'])[0]->summary, '4');
    }

    public function testDropsFindingsOfAnOlderState(): void
    {
        $store = new TelemetryStore();
        $store->add(self::batch('shop', 'a'));
        $trace = $store->trace(Traces::ID);
        $insights = new TraceInsights([self::rule([])]);
        $insights->of($trace, ['shop']);
        $insights->of($trace, ['billing']);

        $store->add(self::batch('shop', 'b'));
        $insights->of($trace);

        Assert::count($trace->insights, 1);
    }

    /**
     * A rule with one finding named after the projects of the spans it sees.
     */
    private static function projectsRule(): TraceRule
    {
        return new class implements TraceRule {
            public function check(Trace $trace): array
            {
                $projects = \array_unique(\array_map(static fn(Span $span): string => $span->project, \array_values($trace->spans)));

                return [new Finding('test', null, ['s'], \implode(',', $projects) ?: 'none', 1, 1.0)];
            }
        };
    }

    /**
     * @param list<Finding> $findings
     */
    private static function rule(array $findings, int &$calls = 0): TraceRule
    {
        return new class($findings, $calls) implements TraceRule {
            private int $calls;

            public function __construct(
                private readonly array $findings,
                int &$calls,
            ) {
                $this->calls = &$calls;
            }

            public function check(Trace $trace): array
            {
                ++$this->calls;
                return $this->findings;
            }
        };
    }

    /**
     * @param non-empty-string $summary
     * @param int<1, max> $count
     * @param non-empty-string $spanId
     */
    private static function finding(string $summary, int $count, float $totalMs, string $spanId = 's'): Finding
    {
        return new Finding('test', null, [$spanId], $summary, $count, $totalMs);
    }

    /**
     * @param non-empty-string $project
     * @param non-empty-string $spanId
     */
    private static function batch(string $project, string $spanId): Batch
    {
        $batch = new Batch($project);
        $batch->spans = [Traces::span($spanId, null, null, 'internal', 'work', project: $project)];

        return $batch;
    }
}
