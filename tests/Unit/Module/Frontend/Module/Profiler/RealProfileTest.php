<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Module\Profiler;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\Module\Profiler\Mapper;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder;
use Buggregator\Trap\Proto\Frame\Profiler\Payload;
use Buggregator\Trap\Proto\Frame\Profiler\Type;
use Buggregator\Trap\Support\Json;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * An anonymized XHProf profile of a real application: 2223 edges, recursion up to hundreds of levels.
 */
#[Covers(Mapper::class)]
#[Test]
final class RealProfileTest
{
    private const STUB = __DIR__ . '/../../../../../Stub/xhprof/app.xhprof';

    public function testExclusiveWallTimeSumsUpToRoot(): void
    {
        $top = self::json((new Mapper())->topFunctions(self::event(), 'excl_wt'));

        Assert::count($top['functions'], 582);
        Assert::same(\array_sum(\array_column($top['functions'], 'excl_wt')), 45310);
        Assert::same($top['overall_totals']['wt'], 45310);
    }

    public function testTopFunctionsAreSortedByMetric(): void
    {
        $top = self::json((new Mapper())->topFunctions(self::event(), 'excl_wt'));

        $values = \array_column($top['functions'], 'excl_wt');
        $sorted = $values;
        \rsort($sorted);
        Assert::same($values, $sorted);
    }

    public function testAllMeasuredMetricsAreOffered(): void
    {
        $mapper = new Mapper();
        $top = self::json($mapper->topFunctions(self::event(), 'wt'));
        $graph = self::json($mapper->callGraph(self::event(), metric: 'cpu'));

        Assert::same(
            \array_column($top['schema'], 'key'),
            ['function', 'ct', 'cpu', 'excl_cpu', 'wt', 'excl_wt', 'mu', 'excl_mu', 'pmu', 'excl_pmu'],
        );
        Assert::same(\array_column($graph['toolbar'], 'metric'), ['cpu', 'wt', 'mu', 'pmu']);
        Assert::same($graph['nodes'] !== [], true);
    }

    public function testCallGraphHasAllFunctionsWithoutThresholds(): void
    {
        $graph = self::json((new Mapper())->callGraph(self::event(), threshold: 0, percentage: 0));

        Assert::count($graph['nodes'], 1035);
        Assert::count($graph['edges'], 2222);
    }

    public function testDeepFlameChartIsEncoded(): void
    {
        $chart = (new Mapper())->flameChart(self::event());

        Assert::string(Json::encode($chart, depth: 10_000))->contains('"name":"main()"');

        Expect::exception(\JsonException::class);
        Json::encode($chart);
    }

    private static function event(): Event
    {
        $calls = \unserialize(\file_get_contents(self::STUB), ['allowed_classes' => false]);
        $profile = (new ProfileBuilder())->createProfile(calls: $calls);

        return new Event($profile->uuid, 'profiler', Payload::new(Type::XHProf, static fn() => $profile), 0.0);
    }

    private static function json(\JsonSerializable $message): array
    {
        return \json_decode(Json::encode($message, depth: 10_000), true, 10_000, \JSON_THROW_ON_ERROR);
    }
}
