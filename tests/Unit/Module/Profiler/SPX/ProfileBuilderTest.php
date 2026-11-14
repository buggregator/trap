<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\SPX;

use Buggregator\Trap\Module\Profiler\SPX\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\Struct\Tree;
use Buggregator\Trap\Support\Spx\Metadata;
use Buggregator\Trap\Support\TimeSlice;
use Buggregator\Trap\Tests\Unit\Module\Profiler\SPX\Stub\SpxTrace;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(ProfileBuilder::class)]
#[Test]
final class ProfileBuilderTest
{
    private const STUB = __DIR__ . '/../../../../Stub/spx/handwritten';

    public function testBuildsCallTreeFromTrace(): void
    {
        $profile = self::build();

        // `mu` is the memory a call leaves allocated, but a caller never takes less than its callees:
        // `handle` frees most of what `fib` allocated, and `main()` frees nearly all
        Assert::same(self::shape($profile->tree()), [
            'main():12:10:1300:1' => [
                'App\Kernel::handle:8:7:1200:1' => [
                    'App\fib:4:3:1200:1' => ['App\fib:1:1:1000:1' => []],
                    'strlen:1:1:0:1' => [],
                ],
                'App\Kernel::handle:1:1:100:1' => [],
            ],
        ]);
        Assert::same($profile->calls->callers('App\fib@1')[0]->cost->ct, 1);
        Assert::same($profile->peaks->pmu, 0);
    }

    public function testPassesReportMetadataAlong(): void
    {
        $profile = self::build(metadata: ['app_name' => 'shop'], tags: ['env' => 'test']);

        Assert::same($profile->date->getTimestamp(), 1747569600);
        Assert::same($profile->metadata, [
            'app_name' => 'shop',
            'details' => [
                'Request' => 'POST shop.test/checkout?step=2',
                'Custom metadata' => 'release=1.2.3',
                'PID' => '42',
                'Metrics' => 'wt, ct, zm, zgr, io',
                'GC runs' => '2',
                'I/O' => '8.00 KiB',
            ],
            'hostname' => 'app',
        ]);
        Assert::same($profile->tags, ['env' => 'test', 'request' => 'POST /checkout?step=2']);
    }

    public function testTellsCommandOfCliScript(): void
    {
        $json = \str_replace(['"cli": 0', '"cli_command_line": "n/a"'], ['"cli": 1', '"cli_command_line": "bin/console app:sync"'], self::json());

        $profile = self::build($json);

        Assert::same($profile->metadata['details']['Command'], 'bin/console app:sync');
        Assert::same(\array_key_exists('Request', $profile->metadata['details']), false);
        Assert::same($profile->tags, ['command' => 'bin/console app:sync']);
    }

    public function testMarksSampledProfile(): void
    {
        $profile = self::build(samplingPeriod: 100);

        Assert::same($profile->metadata['sampling_period'], 100);
        Assert::same($profile->metadata['details']['Sampling period'], '100 µs');
    }

    public function testMapsMetricColumnsByName(): void
    {
        // The same calls with the columns in another order and an extra metric
        $json = \str_replace('"wt"', '"it", "wt"', self::json());
        $trace = (string) \preg_replace_callback(
            '/^(\d+ [01]) (\S+) (\S+) (\S+) (\S+) (\S+)$/m',
            static fn(array $m): string => "$m[1] 7 $m[2] $m[3] $m[4] $m[5] $m[6]",
            self::trace(),
        );

        Assert::same(self::shape(self::build($json, $trace)->tree()), self::shape(self::build()->tree()));
    }

    public function testTakesTimeAndMemoryWithoutCpu(): void
    {
        $json = \str_replace(['"ct"', '"zgr"', '"io"'], ['"zif"', '"zgc"', '"mor"'], self::json());

        $profile = self::build($json);

        Assert::same($profile->peaks->cpu, 0);
        Assert::same($profile->peaks->wt, 12);
        Assert::same($profile->metadata['details']['Included files'], '10000');
    }

    public function testKeepsSeveralRootsUnderMain(): void
    {
        $json = \str_replace(['"called_function_count": 4', '"call_count": 6', '"recorded_call_count": 6'], ['"called_function_count": 2', '"call_count": 2', '"recorded_call_count": 2'], self::json());
        $trace = "[events]\n0 1 0 0 0 0 0\n0 0 2000 0 0 0 0\n1 1 3000 0 0 0 0\n1 0 7000 0 0 0 0\n[functions]\nfoo\nbar\n";

        Assert::same(self::shape(self::build($json, $trace)->tree()), [
            'main():6:0:0:1' => ['foo:2:0:0:1' => [], 'bar:4:0:0:1' => []],
        ]);
    }

    public function testKeepsShutdownFunctionsBesideTheScript(): void
    {
        // PHP runs the shutdown functions after the script ends: they are roots of their own
        $json = \str_replace(['"called_function_count": 4', '"recorded_call_count": 6'], ['"called_function_count": 3', '"recorded_call_count": 3'], self::json());
        $trace = "[events]\n0 1 0 0 0 0 0\n1 1 1000 0 0 0 0\n1 0 3000 0 0 0 0\n0 0 5000 0 0 1 0\n"
            . "2 1 6000 0 0 1 0\n2 0 9000 0 0 2 100\n[functions]\n/app/index.php\nApp\\Kernel::handle\n"
            . "App\\Kernel::terminate\n";

        $profile = self::build($json, $trace);

        Assert::same(self::shape($profile->tree()), [
            'main():8:0:0:1' => [
                '/app/index.php:5:0:0:1' => ['App\Kernel::handle:2:0:0:1' => []],
                'App\Kernel::terminate:3:0:0:1' => [],
            ],
        ]);
        // The totals of the other metrics sum the roots
        Assert::same($profile->metadata['details']['GC runs'], '2');
        Assert::same($profile->metadata['details']['I/O'], '100.00 B');
    }

    public function testReadsTraceCutAtMaxDepth(): void
    {
        // With `SPX_DEPTH=2` the deeper calls are not traced: their time stays in the deepest traced call
        $json = \str_replace(['"called_function_count": 4', '"recorded_call_count": 6'], ['"called_function_count": 2', '"recorded_call_count": 2'], self::json());
        $trace = "[events]\n0 1 0 0 0 0 0\n1 1 1000 0 0 0 0\n1 0 9000 0 0 0 0\n0 0 10000 0 0 0 0\n"
            . "[functions]\n/app/index.php\nApp\\Kernel::handle\n";

        Assert::same(self::shape(self::build($json, $trace)->tree()), [
            'main():10:0:0:1' => ['App\Kernel::handle:8:0:0:1' => []],
        ]);
    }

    public function testMergesCallsOfSameFunctionWhenTheyAreOverNodeLimit(): void
    {
        $events = '';
        for ($i = 0; $i < 6; ++$i) {
            $events .= \sprintf("1 1 %d 0 0 0 0\n1 0 %d 0 0 0 0\n", (1 + 2 * $i) * 1000, (2 + 2 * $i) * 1000);
        }
        $json = \str_replace(['"called_function_count": 4', '"recorded_call_count": 6'], ['"called_function_count": 2', '"recorded_call_count": 7'], self::json());
        $trace = "[events]\n0 1 0 0 0 0 0\n{$events}0 0 20000 0 0 0 0\n[functions]\nrun\nFoo::bar\n";

        $profile = self::build($json, $trace, new ProfileBuilder(maxNodes: 4));

        Assert::same(self::shape($profile->tree()), ['run:20:0:0:1' => ['Foo::bar:6:0:0:6' => []]]);
        Assert::same($profile->metadata['compacted']['merged'], 5);
    }

    public function testLetsOtherFibersRunWhileBuilding(): void
    {
        $json = \str_replace(['"called_function_count": 4', '"recorded_call_count": 6'], ['"called_function_count": 2', '"recorded_call_count": 1001'], self::json());
        $trace = "[events]\n0 1 0 0 0 0 0\n"
            . \str_repeat("1 1 1000 0 0 0 0\n1 0 2000 0 0 0 0\n", 1000)
            . "0 0 3000 0 0 0 0\n[functions]\nrun\nfoo\n";
        $fiber = new \Fiber(static fn(): Profile => (new ProfileBuilder())->createProfile(
            Metadata::fromJson($json),
            [(string) \gzencode($trace)],
            timeSlice: new TimeSlice(0.0),
        ));

        $suspended = [];
        $value = $fiber->start();
        while (!$fiber->isTerminated()) {
            $suspended[] = $value;
            $value = $fiber->resume();
        }

        Assert::notBlank($suspended);
        Assert::same(\array_unique($suspended), [null]);
        Assert::same(self::shape($fiber->getReturn()->tree()), self::shape(self::build($json, $trace)->tree()));
    }

    public function testKeepsMemoryBoundedOnLargeTrace(): void
    {
        $calls = 150_000;
        \gc_collect_cycles();
        $before = \memory_get_usage();
        \memory_reset_peak_usage();

        $profile = (new ProfileBuilder(maxNodes: 20_000))->createProfile(
            Metadata::fromJson(SpxTrace::metadata($calls)),
            SpxTrace::chunks($calls),
        );

        $peak = \memory_get_peak_usage() - $before;
        Assert::same($profile->tree()->all['n1']->item->callee, 'main()');
        Assert::true(\count($profile->tree()) <= 20_001);
        Assert::same($profile->calls->roots()[0]->cost->ct, 1);
        // The 4.5 MB trace inflates to 10 MB, and is streamed; the tree of 150k calls is compacted to the node limit
        Assert::true($peak < 48 * 1024 * 1024, \sprintf('Peak memory grew by %.1f MB.', $peak / 1048576));
    }

    #[DataSet(["[events]\n[functions]\na\nb\nc\nd\n", 'The trace has no calls.'], 'no calls')]
    #[DataSet(["[events]\n0 1 0 0\n", 'Line 2 is not an event of 5 metric values.'], 'malformed')]
    public function testRejectsInvalidTrace(string $trace, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage($message);

        self::build(trace: $trace);
    }

    public function testRejectsTraceOverSizeLimit(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The trace is larger than 100 bytes decompressed.');

        self::build(builder: new ProfileBuilder(maxTraceSize: 100));
    }

    #[DataSet([4, ['run:20:20:600:1' => ['Foo::bar:6:6:600:6' => []]]], 'merged')]
    #[DataSet([7, [
        'run:20:20:600:1' => [
            'Foo::bar:1:1:100:1' => [],
            'Foo::bar:1:1:100:1 ' => [],
            'Foo::bar:1:1:100:1  ' => [],
            'Foo::bar:1:1:100:1   ' => [],
            'Foo::bar:1:1:100:1    ' => [],
            'Foo::bar:1:1:100:1     ' => [],
        ],
    ]], 'as many calls as nodes')]
    public function testSumsCostsOfMergedCalls(int $maxNodes, array $shape): void
    {
        $events = '';
        for ($i = 0; $i < 6; ++$i) {
            $start = (1 + 2 * $i) * 1000;
            $events .= "1 1 $start $start 0 0 0\n1 0 " . ($start + 1000) . ' ' . ($start + 1000) . " 100 0 0\n";
        }
        $json = '{"enabled_metrics": ["wt", "ct", "zm", "zgr", "io"], "called_function_count": 2, "recorded_call_count": 7}';
        $trace = "[events]\n0 1 0 0 0 0 0\n{$events}0 0 20000 20000 0 0 0\n[functions]\nrun\nFoo::bar\n";

        $profile = self::build($json, $trace, new ProfileBuilder(maxNodes: $maxNodes));

        Assert::same(self::shape($profile->tree()), $shape);
    }

    #[DataSet([1400, '1 µs'], 'rounded down')]
    #[DataSet([1600, '2 µs'], 'rounded up')]
    public function testShowsTotalsOfOtherMetrics(int $idle, string $idleShown): void
    {
        $json = '{"enabled_metrics": ["wt", "it", "zmab", "mor", "zgr", "xyz"], "called_function_count": 1, "recorded_call_count": 1, "cli": 1}';
        $trace = "[events]\n0 1 0 0 0 0 0 0\n0 0 1000 $idle 0 -2048 1.125 7\n[functions]\nmain\n";

        $profile = self::build($json, $trace);

        Assert::same($profile->metadata['details'], [
            'Metrics' => 'wt, it, zmab, mor, zgr, xyz',
            'Idle time' => $idleShown,
            'Allocated memory' => '0 B',
            'Own RSS change' => '-2.00 KiB',
            'GC runs' => '1.13',
            'xyz' => '7',
        ]);
        Assert::same($profile->tags, []);
    }

    #[DataSet([999, '999 µs'], 'microseconds')]
    #[DataSet([1000, '1.00 ms'], 'a millisecond')]
    #[DataSet([2500, '2.50 ms'], 'milliseconds')]
    #[DataSet([2_000_000, '2.00 s'], 'seconds')]
    public function testShowsSamplingPeriod(int $period, string $shown): void
    {
        $profile = self::build(samplingPeriod: $period);

        Assert::same($profile->metadata['details']['Sampling period'], $shown);
    }

    public function testNamesRequestWithoutMethod(): void
    {
        $json = \str_replace('"http_method": "POST",', '', self::json());

        $profile = self::build($json);

        Assert::same($profile->metadata['details']['Request'], 'shop.test/checkout?step=2');
        Assert::same($profile->tags, ['request' => '/checkout?step=2']);
    }

    public function testShortensLongRequestTag(): void
    {
        $uri = '/' . \str_repeat('a', 199);
        $json = \str_replace('"/checkout?step=2"', "\"$uri\"", self::json());

        $profile = self::build($json);

        Assert::same($profile->tags, ['request' => 'POST ' . \substr($uri, 0, 117) . '...']);
    }

    public function testKeepsGivenDate(): void
    {
        $date = new \DateTimeImmutable('@1000000');

        $profile = (new ProfileBuilder())->createProfile(Metadata::fromJson(self::json()), [(string) \gzencode(self::trace())], $date);

        Assert::same($profile->date->getTimestamp(), 1000000);
    }

    private static function json(): string
    {
        return (string) \file_get_contents(self::STUB . '.json');
    }

    private static function trace(): string
    {
        return (string) \file_get_contents(self::STUB . '.txt');
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<non-empty-string, non-empty-string> $tags
     */
    private static function build(
        ?string $json = null,
        ?string $trace = null,
        ProfileBuilder $builder = new ProfileBuilder(),
        array $metadata = [],
        array $tags = [],
        ?int $samplingPeriod = null,
    ): Profile {
        return $builder->createProfile(
            Metadata::fromJson($json ?? self::json()),
            \str_split((string) \gzencode($trace ?? self::trace(), 1), 16),
            metadata: $metadata,
            tags: $tags,
            samplingPeriod: $samplingPeriod,
        );
    }

    /**
     * @param Tree<Edge> $tree
     *
     * @return array<string, array> Nested `callee:wt:cpu:mu:ct` keys; repeated siblings get trailing spaces.
     */
    private static function shape(Tree $tree): array
    {
        $map = static function (array $branches) use (&$map): array {
            $result = [];
            /** @var Branch<Edge> $branch */
            foreach ($branches as $branch) {
                $cost = $branch->item->cost;
                $key = "{$branch->item->callee}:{$cost->wt}:{$cost->cpu}:{$cost->mu}:{$cost->ct}";
                while (\array_key_exists($key, $result)) {
                    $key .= ' ';
                }
                $result[$key] = $map($branch->children);
            }
            return $result;
        };

        return $map(\array_values($tree->root));
    }
}
