<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\SPX;

use Buggregator\Trap\Module\Profiler\SPX\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Tests\Unit\Support\Spx\RealReportTest as Real;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

/**
 * Profiles of the reports of `tests/Stub/spx/real`, recorded with ext-spx.
 */
#[Covers(ProfileBuilder::class)]
#[Test]
final class RealReportTest
{
    public function buildsCallTreeOfScript(): void
    {
        // The file of the script is the single root: `main()`
        Assert::same(self::calls(self::build('default')), [
            'main():1' => [
                '/work/lib.php:1' => [],
                'run:1' => [
                    'fib:1' => [
                        'fib:1' => [
                            'fib:1' => [
                                'fib:1' => ['fib:1' => [], 'fib:1 ' => []],
                                'fib:1 ' => [],
                            ],
                            'fib:1 ' => ['fib:1' => [], 'fib:1 ' => []],
                        ],
                        'fib:1 ' => [
                            'fib:1' => ['fib:1' => [], 'fib:1 ' => []],
                            'fib:1 ' => [],
                        ],
                    ],
                    '{closure}:1' => [],
                    'lib:1' => [],
                    'Greeter::greet:1' => [],
                ],
            ],
        ]);
    }

    public function keepsCompilationAndShutdownBesideTheScript(): void
    {
        $tree = self::calls(self::build('metrics'));

        Assert::same(\array_keys($tree['main():1']), ['::zend_compile_file:1', '/work/calls.php:1', '::php_request_shutdown:1']);
        Assert::same($tree['main():1']['/work/calls.php:1']['run:1']['Greeter::greet:1'], ['strtoupper:1' => []]);
    }

    public function keepsShutdownFunctionsUnderRequestShutdown(): void
    {
        Assert::same(self::calls(self::build('shutdown')), [
            'main():1' => [
                '::zend_compile_file:1' => [],
                '/work/shutdown.php:1' => ['register_shutdown_function:1' => [], 'work:1' => []],
                '::php_request_shutdown:1' => ['{closure}:1' => ['cleanup:1' => ['work:1' => []]]],
            ],
        ]);
    }

    public function readsTraceCutAtMaxDepth(): void
    {
        Assert::same(self::calls(self::build('depth')), [
            'main():1' => ['/work/lib.php:1' => [], 'run:1' => []],
        ]);
    }

    public function readsSpanStartedFromCode(): void
    {
        // SPX opens the calls the span starts in: the file of the script, and `spx_profiler_start()` itself
        Assert::same(self::calls(self::build('span')), [
            'main():1' => [
                'spx_profiler_start:1' => [],
                'inside:1' => ['range:1' => [], 'array_sum:1' => []],
                'inside:1 ' => ['range:1' => [], 'array_sum:1' => []],
                'spx_profiler_stop:1' => [],
            ],
        ]);
    }

    #[DataSet(['default', 0])]
    #[DataSet(['metrics', 1])]
    #[DataSet(['depth', 0])]
    #[DataSet(['span', 0])]
    #[DataSet(['shutdown', 1])]
    #[DataSet(['sampling', 0])]
    #[DataSet(['http', 0])]
    public function countsEveryCallAndTheWholeTime(string $report, int $addedRoots): void
    {
        $metadata = Real::metadata($report);
        $profile = self::build($report);
        $root = \array_values($profile->tree()->root)[0]->item;

        // Several roots go under a `main()` of their own
        Assert::same(self::count($profile->tree()->root), (int) $metadata->int('call_count') + $addedRoots);
        Assert::same(\count($profile->tree()->root), 1);
        Assert::same($root->callee, 'main()');
        Assert::true(\abs($root->cost->wt - (int) $metadata->int('wall_time_ms')) <= 10);
        Assert::same($profile->peaks->wt, $root->cost->wt);
        Assert::true($root->cost->mu <= (int) $metadata->int('peak_memory_usage'));
    }

    public function takesCpuTimeWithinWallTime(): void
    {
        $profile = self::build('metrics');

        $check = static function (array $branches) use (&$check): void {
            /** @var Branch<Edge> $branch */
            foreach ($branches as $branch) {
                Assert::true($branch->item->cost->cpu <= $branch->item->cost->wt + 1);
                $check($branch->children);
            }
        };
        $check($profile->tree()->root);
        Assert::true($profile->peaks->cpu > 0);
    }

    public function sumsOtherMetricsIntoDetails(): void
    {
        $details = self::build('metrics')->metadata['details'];

        // The bytes the script read and wrote, its own file included
        Assert::string($details['I/O'])->matchesRegex('/^[1-9]\d{2}\.00 B$/');
        unset($details['I/O']);
        Assert::same($details, [
            'Command' => 'calls.php',
            'PID' => (string) Real::metadata('metrics')->int('process_pid'),
            'Metrics' => 'wt, ct, zm, zgr, zif, io',
            'GC runs' => '0',
            'Included files' => '2',
        ]);
    }

    public function tellsRequestOfWebReport(): void
    {
        $profile = self::build('http');

        Assert::same($profile->tags, ['request' => 'POST /checkout?step=2']);
        Assert::same($profile->metadata['details']['Request'], 'POST shop.test/checkout?step=2');
        Assert::same($profile->metadata['details']['Custom metadata'], "release=1.2.3\tname=Café\x01");
        Assert::same($profile->metadata['hostname'], Real::metadata('http')->string('host_name'));
        Assert::same($profile->date->getTimestamp(), Real::metadata('http')->int('exec_ts'));
    }

    public function keepsMemoryOfSampledTraceAboveZero(): void
    {
        // The sampled trace ends with less memory than it starts with
        $profile = self::build('sampling', samplingPeriod: 500);

        Assert::same($profile->metadata['sampling_period'], 500);
        Assert::same($profile->metadata['details']['Sampling period'], '500 µs');
        Assert::same($profile->peaks->mu, 0);
        Assert::same(self::calls($profile), [
            'main():1' => ['outer:1' => [
                'spin:1' => ['tick:1' => []],
                'inner:1' => ['spin:1' => ['tick:1' => []]],
            ]],
        ]);
    }

    public function rejectsTraceCutMidStream(): void
    {
        Expect::exception(new \InvalidArgumentException('The trace is truncated: the gzip stream does not end.'));

        self::build('truncated');
    }

    private static function build(string $report, ?int $samplingPeriod = null): Profile
    {
        return (new ProfileBuilder())->createProfile(
            Real::metadata($report),
            \str_split((string) \file_get_contents(Real::DIR . "/$report.txt.gz"), 100),
            samplingPeriod: $samplingPeriod,
        );
    }

    /**
     * @return array<string, array> The call tree as `callee:calls` keys, a space added to repeated ones.
     */
    private static function calls(Profile $profile): array
    {
        $map = static function (array $branches) use (&$map): array {
            $result = [];
            /** @var Branch<Edge> $branch */
            foreach ($branches as $branch) {
                $key = "{$branch->item->callee}:{$branch->item->cost->ct}";
                while (\array_key_exists($key, $result)) {
                    $key .= ' ';
                }
                $result[$key] = $map($branch->children);
            }
            return $result;
        };

        return $map(\array_values($profile->tree()->root));
    }

    private static function count(array $branches): int
    {
        $count = 0;
        /** @var Branch<Edge> $branch */
        foreach ($branches as $branch) {
            $count += $branch->item->cost->ct + self::count($branch->children);
        }

        return $count;
    }
}
