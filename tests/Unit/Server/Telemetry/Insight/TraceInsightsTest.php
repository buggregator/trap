<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Telemetry\Insight;

use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Insight\Finding;
use Buggregator\Trap\Server\Telemetry\Insight\RepeatedQuery;
use Buggregator\Trap\Server\Telemetry\Insight\TraceInsights;
use Buggregator\Trap\Server\Telemetry\Insight\TraceRule;
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
        $insights->of($trace, ['shop']);

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

    public function testFiltersFindingsByProjectOfTheirSpans(): void
    {
        $store = new TelemetryStore();
        $shop = self::batch('shop', 'a');
        $billing = self::batch('billing', 'b');
        $store->add($shop);
        $store->add($billing);
        $insights = new TraceInsights([self::rule([self::finding('shop', 5, 1.0, 'a'), self::finding('billing', 5, 1.0, 'b')])]);
        $trace = $store->trace(Traces::ID);

        Assert::count($insights->of($trace), 2);
        Assert::same(\array_map(static fn(Finding $f): string => $f->summary, $insights->of($trace, ['billing'])), ['billing']);
        Assert::same($insights->of($trace, ['none']), []);
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
