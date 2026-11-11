<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler\Model;

use Buggregator\Trap\Client\Profiler\Internal\Model\CallGraph;
use Buggregator\Trap\Client\Profiler\Internal\Model\CallTree;
use Buggregator\Trap\Client\Profiler\Internal\Model\SpeedscopeReader;
use Buggregator\Trap\Client\Profiler\Internal\Model\XHProfWriter;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * The `NN-excimer-*` fixtures are modelled on Excimer's output format (`excimer_log.c`) and its tests
 * `tests/cpu.phpt` and `tests/maxDepth.phpt`; `upstream/` holds a sample file of speedscope (MIT).
 */
#[Test]
#[Covers(SpeedscopeReader::class)]
#[Covers(CallTree::class)]
#[Covers(CallGraph::class)]
#[Covers(XHProfWriter::class)]
final class ConversionTest
{
    private const FIXTURES = __DIR__ . '/../Stub/Speedscope/';

    #[DataSet(['01-excimer-cpu-phpt', 0.1, [
        'main()' => '30/3000000',
        'main()==>foo' => '30/3000000',
        'foo==>bar' => '30/3000000',
        'bar==>{closure:/data/cpu.php(5)}' => '8/800000',
        'bar==>{closure:/data/cpu.php(8)}' => '7/700000',
        'bar==>C::member' => '7/700000',
        'bar==>baz' => '8/800000',
    ]], 'closures, a method and a file-scope root')]
    #[DataSet(['02-excimer-maxdepth-phpt', 0.1, [
        'main()' => '1/100000',
        'main()==>excimer_truncated' => '1/100000',
        'excimer_truncated==>foo_6' => '1/100000',
        'foo_6==>foo_7' => '1/100000',
        'foo_7==>foo_8' => '1/100000',
        'foo_8==>foo_9' => '1/100000',
        'foo_9==>foo_10' => '1/100000',
    ]], 'a fully truncated profile')]
    #[DataSet(['03-excimer-truncated-mixed', 0.001, [
        'main()' => '5/5000',
        'main()==>/app/public/index.php' => '2/2000',
        '/app/public/index.php==>App\Kernel::handle' => '2/2000',
        'App\Kernel::handle==>App\Controller\TreeController::show' => '2/2000',
        'App\Controller\TreeController::show==>App\Tree\Node::render' => '4/4000',
        'App\Tree\Node::render==>App\Tree\Node::render@1' => '4/4000',
        'main()==>excimer_truncated' => '3/3000',
        'excimer_truncated==>App\Controller\TreeController::show' => '2/2000',
        'App\Tree\Node::render@1==>App\Tree\Node::render@2' => '3/3000',
        'App\Tree\Node::render@2==>App\Tree\Node::render@3' => '3/3000',
        'excimer_truncated==>App\Tree\Node::render' => '1/1000',
        'App\Tree\Node::render@3==>App\Tree\Node::render@4' => '1/1000',
    ]], 'truncated stacks as a second root')]
    #[DataSet(['04-excimer-overrun-event-count', 0.001, [
        'main()' => '255/255000',
        'main()==>App\Bootstrap::boot' => '1/1000',
        'main()==>App\Service\Weather::fetch' => '251/251000',
        'App\Service\Weather::fetch==>App\Http\Client::send' => '250/250000',
        'main()==>App\Cache::retry' => '3/3000',
    ]], 'event counts above 1')]
    #[DataSet(['07-excimer-recursion', 0.001, [
        'main()' => '6/6000',
        'main()==>fib' => '3/3000',
        'fib==>fib@1' => '3/3000',
        'fib@1==>fib@2' => '2/2000',
        'fib@2==>fib@3' => '1/1000',
        'main()==>isEven' => '3/3000',
        'isEven==>isOdd' => '2/2000',
        'isOdd==>isEven@1' => '2/2000',
        'isEven@1==>isOdd@1' => '1/1000',
    ]], 'direct and mutual recursion')]
    #[DataSet(['09-excimer-shutdown-roots', 0.001, [
        'main()' => '6/6000',
        'main()==>/app/public/index.php' => '3/3000',
        '/app/public/index.php==>App\Kernel::handle' => '3/3000',
        'main()==>{closure:/app/public/index.php(7)}' => '1/1000',
        'main()==>Monolog\Handler\BufferHandler::__destruct' => '2/2000',
        'Monolog\Handler\BufferHandler::__destruct==>Monolog\Handler\BufferHandler::close' => '2/2000',
    ]], 'several roots from shutdown code')]
    #[DataSet(['10-excimer-anonymous-class-root', 0.001, [
        'main()' => '2/2000',
        'main()==>class@anonymous_/app/bootstrap.php:10$0::flush' => '2/2000',
        'class@anonymous_/app/bootstrap.php:10$0::flush==>App\Metrics\Exporter::send' => '1/1000',
    ]], 'a single root that is a method with a path in its name')]
    #[DataSet(['11-excimer-command-line-root', 0.001, [
        'main()' => '3/3000',
        'main()==>work' => '2/2000',
    ]], 'php -r code as the root')]
    #[DataSet(['13-excimer-empty-stack', 0.001, [
        'main()' => '4/4000',
        'main()==>work' => '1/1000',
    ]], 'an empty stack counts in the total')]
    #[DataSet(['g3-sampled-zero-weight', 0.001, [
        'main()' => '1/1000',
        'main()==>a' => '1/1000',
    ]], 'zero weights are skipped')]
    #[DataSet(['upstream/two-sampled.speedscope', 1.0, [
        'main()==>a' => '14/14000000',
        'a==>b' => '14/14000000',
        'b==>c' => '5/5000000',
        'b==>d' => '4/4000000',
        'main()' => '14/14000000',
    ]], 'the active profile only')]
    public function fixtureBecomesXHProfPairs(string $fixture, float $period, array $expected): void
    {
        $document = \json_decode((string) \file_get_contents(self::FIXTURES . $fixture . '.json'), true);

        $xhprof = self::xhprof(SpeedscopeReader::read($document, $period, false)->toGraph());

        $actual = \array_map(static fn(array $entry): string => "{$entry['ct']}/{$entry['wt']}", $xhprof);
        \ksort($actual);
        \ksort($expected);
        Assert::same($actual, $expected);
    }

    public function realWorldNamesPassThrough(): void
    {
        $document = \json_decode((string) \file_get_contents(self::FIXTURES . '08-excimer-real-world-names.json'), true);

        $xhprof = self::xhprof(SpeedscopeReader::read($document, 0.001, false)->toGraph());

        Assert::same($xhprof['main()']['ct'], 9);
        Assert::array($xhprof)->hasKeys(
            "Symfony\\Component\\HttpKernel\\Kernel::handle==>/app/src/Rules.php(17)_:_eval()'d_code",
            "/app/src/Rules.php(17)_:_eval()'d_code==>{closure:/app/src/Rules.php(17)_:_eval()'d_code(3)}",
            'class@anonymous_/app/src/Listener.php:12$0::__invoke==>Psr\Log\AbstractLogger@anonymous_/app/src/Log.php:8$1::log',
            'Symfony\Component\HttpKernel\Kernel::handle==>App\Ünïcödé\Сервис::處理',
            'main()==>/app/config/bootstrap.php',
        );
        $long = \array_values(\array_filter(\array_keys($xhprof), static fn(string $key): bool => \strlen($key) > 280));
        Assert::count($long, 1);
    }

    public function xhprofEntriesHaveAllMetrics(): void
    {
        $document = self::document([['/app/run.php', '/app/run.php'], ['foo', '/app/run.php']], [[0, 1]], [20]);

        $xhprof = self::xhprof(SpeedscopeReader::read($document, 0.01, true)->toGraph());

        Assert::same($xhprof, [
            'main()' => ['ct' => 2, 'wt' => 20000, 'cpu' => 20000, 'mu' => 0, 'pmu' => 0],
            'main()==>foo' => ['ct' => 2, 'wt' => 20000, 'cpu' => 20000, 'mu' => 0, 'pmu' => 0],
        ]);
    }

    public function fileScopeIsTheFrameNamedByItsFile(): void
    {
        $document = self::document([['/app/my script.php', '/app/my script.php'], ['foo', null]], [[0, 1]], [1]);
        $notFileScope = self::document([['/app/run.php', '/app/other.php'], ['foo', null]], [[0, 1]], [1]);

        $mapped = self::xhprof(SpeedscopeReader::read(self::spaced($document), 0.001, false)->toGraph());
        $wrapped = self::xhprof(SpeedscopeReader::read($notFileScope, 0.001, false)->toGraph());

        Assert::same(\array_keys($mapped), ['main()', 'main()==>foo']);
        Assert::same(\array_keys($wrapped), ['main()', 'main()==>/app/run.php', '/app/run.php==>foo']);
    }

    public function unitlessWeightsAreEventCounts(): void
    {
        $document = self::document([['/app/run.php', '/app/run.php'], ['foo', null]], [[0, 1], [0]], [3, 1], 'none');

        $xhprof = self::xhprof(SpeedscopeReader::read($document, 0.002, false)->toGraph());

        Assert::same($xhprof['main()']['ct'], 4);
        Assert::same($xhprof['main()']['wt'], 8000);
        Assert::same($xhprof['main()==>foo']['ct'], 3);
    }

    public function timedWeightBelowThePeriodCountsOneSample(): void
    {
        $document = self::document([['/app/run.php', '/app/run.php']], [[0]], [0.1]);

        $xhprof = self::xhprof(SpeedscopeReader::read($document, 0.001, false)->toGraph());

        Assert::same($xhprof['main()']['ct'], 1);
    }

    public function invalidUtf8NamesAreReplaced(): void
    {
        $document = self::document([["/var/www/caf\xE9/index.php", "/var/www/caf\xE9/index.php"], ["r\xE9sum\xE9", null]], [[0, 1]], [1]);

        $json = XHProfWriter::json(SpeedscopeReader::read($document, 0.001, false)->toGraph());

        Assert::same(\json_decode($json, true), [
            'main()' => ['ct' => 1, 'wt' => 1000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            "main()==>r\u{FFFD}sum\u{FFFD}" => ['ct' => 1, 'wt' => 1000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]);
    }

    public function emptyDocumentGivesOnlyMain(): void
    {
        $xhprof = self::xhprof(SpeedscopeReader::read([], 0.001, false)->toGraph());

        Assert::same($xhprof, ['main()' => ['ct' => 0, 'wt' => 0, 'cpu' => 0, 'mu' => 0, 'pmu' => 0]]);
    }

    public function hugeFrameIndexesStayApart(): void
    {
        $document = self::document([['/app/run.php', '/app/run.php']], [[0, 1 << 24], [0, (1 << 24) + 1], [0, 1 << 24]], [1, 1, 1]);

        $tree = SpeedscopeReader::read($document, 0.001, false);

        // Both unknown frames are `(unknown)` and merge into one pair, but they are distinct tree nodes.
        Assert::same(self::xhprof($tree->toGraph())['main()==>(unknown)']['ct'], 3);
        Assert::same($tree->node(0, 1 << 24, '(unknown)'), 1);
        Assert::same($tree->node(0, (1 << 24) + 1, '(unknown)'), 2);
    }

    /**
     * @return array<string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}>
     */
    private static function xhprof(CallGraph $graph): array
    {
        return \json_decode(XHProfWriter::json($graph), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array{string, string|null}> $frames Names and files.
     * @param list<list<int>> $samples
     * @param list<int|float> $weights
     */
    private static function document(array $frames, array $samples, array $weights, string $unit = 'milliseconds'): array
    {

        return [
            '$schema' => 'https://www.speedscope.app/file-format-schema.json',
            'shared' => ['frames' => \array_map(
                static fn(array $frame): array => \array_filter(['name' => $frame[0], 'file' => $frame[1]]),
                $frames,
            )],
            'profiles' => [[
                'type' => 'sampled',
                'name' => '',
                'unit' => $unit,
                'startValue' => 0,
                'endValue' => \array_sum($weights),
                'samples' => $samples,
                'weights' => $weights,
            ]],
        ];
    }

    /**
     * Turns the spaces of the frame names into `_`, as Excimer does; the files keep them.
     */
    private static function spaced(array $document): array
    {
        foreach ($document['shared']['frames'] as $i => $frame) {
            $document['shared']['frames'][$i]['name'] = \str_replace(' ', '_', $frame['name']);
        }

        return $document;
    }
}
