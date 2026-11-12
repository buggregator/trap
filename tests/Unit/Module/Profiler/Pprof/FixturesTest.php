<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Pprof;

use Buggregator\Trap\Module\Profiler\Pprof\Document;
use Buggregator\Trap\Module\Profiler\Pprof\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\Pprof\Wire;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Profiles of Go's `runtime/pprof`, see `tests/Stub/pprof/README.md`.
 */
#[Covers(ProfileBuilder::class)]
#[Covers(Document::class)]
#[Covers(Wire::class)]
#[Test]
final class FixturesTest
{
    private const STUB_DIR = __DIR__ . '/../../../../Stub/pprof';

    public function testGoCpuProfile(): void
    {
        $profile = self::build('upstream/fib.pb.gz');

        Assert::same($profile->peaks->jsonSerialize(), ['ct' => 2974, 'wt' => 29_740_000, 'cpu' => 29_740_000, 'mu' => 0, 'pmu' => 0]);
        Assert::same($profile->metadata, [
            'profile_type' => 'sampled',
            'sample_types' => 'samples/count, cpu/nanoseconds',
            'count_type' => 'samples',
            'clock' => 'cpu',
            'period' => 10_000_000,
            'period_type' => 'cpu/nanoseconds',
            'time_nanos' => 1_626_441_030_776_293_402,
            'duration_nanos' => 30_156_835_758,
        ]);
        $main = self::root($profile);
        Assert::same($main->item->callee, 'main()');
        // A goroutine stack starts at its own function: several roots
        Assert::same(self::children($main)['main.calculateFib'], [2950, 29_500_000]);
        Assert::same(\array_sum(\array_column(self::children($main), 0)), 2974);
    }

    public function testGoHeapProfileTakesDefaultSampleType(): void
    {
        $profile = self::build('upstream/alloc_objects.pb.gz');

        // `alloc_space` is the default: `alloc_objects` goes with it
        Assert::same([$profile->peaks->ct, $profile->peaks->mu, $profile->peaks->wt], [310_797_348, 116_044_559_690, 0]);
        Assert::false(isset($profile->metadata['clock']));
        Assert::same($profile->metadata['period_type'], 'space/bytes');
        Assert::same(self::children(self::root($profile))['net/http.(*conn).serve'], [215_015_532, 0]);
    }

    private static function build(string $file): Profile
    {
        $builder = new ProfileBuilder();

        return $builder->createProfile($builder->decode((string) \file_get_contents(self::STUB_DIR . '/' . $file)));
    }

    /**
     * @return Branch<Edge>
     */
    private static function root(Profile $profile): Branch
    {
        $roots = $profile->tree()->root;
        Assert::count($roots, 1);

        return \reset($roots);
    }

    /**
     * @param Branch<Edge> $branch
     *
     * @return array<string, array{int, int}> Counts and wall times by callees.
     */
    private static function children(Branch $branch): array
    {
        $children = [];
        foreach ($branch->children as $child) {
            $children[$child->item->callee] = [$child->item->cost->ct, $child->item->cost->wt];
        }

        return $children;
    }
}
