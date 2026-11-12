<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Pprof;

use Buggregator\Trap\Module\Profiler\Compaction\StackTree;
use Buggregator\Trap\Module\Profiler\Pprof\Document;
use Buggregator\Trap\Module\Profiler\Pprof\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\Pprof\Wire;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\Struct\Tree;
use Buggregator\Trap\Support\TimeSlice;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Pprof\Stub\PprofProfile;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Pprof\Stub\Proto;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(ProfileBuilder::class)]
#[Covers(Document::class)]
#[Covers(Wire::class)]
#[Covers(StackTree::class)]
#[Test]
final class ProfileBuilderTest
{
    public function testMergesSamplesWithSameStack(): void
    {
        $pprof = new PprofProfile();
        [$main, $foo, $bar] = [$pprof->frame('main.main'), $pprof->frame('main.foo'), $pprof->frame('main.bar')];
        $pprof->sample([$foo, $main], [1, 10_000_000])
            ->sample([$bar, $foo, $main], [2, 20_000_000])
            ->sample([$main], [1, 5_000_000])
            ->sample([$foo, $main], [1, 1_500]);

        $profile = self::build($pprof);

        Assert::same(self::shape($profile->tree()), [
            'main.main:35002:5' => ['main.foo:30002:4' => ['main.bar:20000:2' => []]],
        ]);
        // The CPU time is the only time: `wt` repeats it
        Assert::same([$profile->peaks->cpu, $profile->peaks->mu], [35002, 0]);
        Assert::same($profile->metadata['clock'], 'cpu');
        Assert::same($profile->metadata['profile_type'], 'sampled');
        Assert::same($profile->metadata['sample_types'], 'samples/count, cpu/nanoseconds');
    }

    public function testReadsPackedAndUnpackedFieldsAlike(): void
    {
        $shapes = [];
        foreach ([true, false] as $packed) {
            $pprof = new PprofProfile();
            $pprof->packed = $packed;
            [$main, $foo] = [$pprof->frame('main'), $pprof->frame('foo')];
            $pprof->sample([$foo, $main], [3, 3000])->sample([$main], [1, 1000]);
            $shapes[] = self::shape(self::build($pprof)->tree());
        }

        Assert::same($shapes[0], ['main():4:4' => ['foo:3:3' => []]]);
        Assert::same($shapes[1], $shapes[0]);
    }

    public function testExpandsInlinedLinesIntoFrames(): void
    {
        $pprof = new PprofProfile();
        $main = $pprof->frame('runtime.main');
        // `inner` is inlined into `middle`, inlined into `outer`: the last line is the outermost
        $inlined = $pprof->location([
            $pprof->function('inner'),
            $pprof->function('middle'),
            $pprof->function('outer'),
        ]);
        $pprof->sample([$inlined, $main], [1, 1000]);

        Assert::same(self::shape(self::build($pprof)->tree()), [
            'runtime.main:1:1' => ['outer:1:1' => ['middle:1:1' => ['inner:1:1' => []]]],
        ]);
    }

    public function testMapsSampleTypesToCostsByUnits(): void
    {
        $pprof = new PprofProfile([
            ['wall', 'microseconds'],
            ['cpu-time', 'nanoseconds'],
            ['sample', 'count'],
            ['alloc-size', 'bytes'],
            ['widgets', 'widgets'],
        ]);
        $main = $pprof->frame('main');
        $pprof->sample([$main], [5000, 3_000_000, 2, 4096, 99]);

        $cost = self::build($pprof)->calls->roots()[0]->cost;

        Assert::same([$cost->ct, $cost->wt, $cost->cpu, $cost->mu, $cost->pmu], [2, 5000, 3000, 4096, 0]);
    }

    #[DataSet(['nanoseconds', 5000], 'nanoseconds')]
    #[DataSet(['microseconds', 5], 'microseconds')]
    #[DataSet(['milliseconds', 5], 'milliseconds')]
    #[DataSet(['seconds', 5], 'seconds')]
    #[DataSet(['ms', 5], 'ms')]
    public function testConvertsTimeToMicroseconds(string $unit, int $value): void
    {
        $pprof = new PprofProfile([['delay', $unit]]);
        $pprof->sample([$pprof->frame('main')], [$value]);

        $profile = self::build($pprof);

        $expected = match ($unit) {
            'nanoseconds' => 5,
            'microseconds' => 5,
            default => 5000 * ($unit === 'seconds' ? 1000 : 1),
        };
        Assert::same($profile->calls->roots()[0]->cost->wt, $expected);
        Assert::same($profile->metadata['clock'], 'wall');
    }

    public function testCountsSamplesWithoutCountType(): void
    {
        $pprof = new PprofProfile([['wall', 'nanoseconds']]);
        [$main, $foo] = [$pprof->frame('main'), $pprof->frame('foo')];
        $pprof->sample([$foo, $main], [7000])->sample([$main], [3000])->sample([$foo, $main], [1000]);

        Assert::same(self::shape(self::build($pprof)->tree()), ['main():11:3' => ['foo:8:2' => []]]);
    }

    public function testPrefersDefaultSampleTypeAndItsSiblings(): void
    {
        $types = [['alloc_objects', 'count'], ['alloc_space', 'bytes'], ['inuse_objects', 'count'], ['inuse_space', 'bytes']];
        $costs = [];
        foreach ([null, 'inuse_space'] as $default) {
            $pprof = new PprofProfile($types);
            $pprof->defaultSampleType = $default;
            $pprof->sample([$pprof->frame('main.alloc')], [10, 1024, 2, 256]);
            $cost = self::build($pprof)->calls->roots()[0]->cost;
            $costs[] = [$cost->ct, $cost->mu, $cost->wt];
        }

        Assert::same($costs, [[10, 1024, 0], [2, 256, 0]]);
    }

    public function testTakesDefaultSampleTypeOverFirstOfSameCost(): void
    {
        $pprof = new PprofProfile([['wall', 'nanoseconds'], ['delay', 'nanoseconds']]);
        $pprof->defaultSampleType = 'delay';
        $pprof->sample([$pprof->frame('main')], [9000, 2000]);

        Assert::same(self::build($pprof)->calls->roots()[0]->cost->wt, 2);
    }

    public function testSkipsSamplesWithNothingMeasured(): void
    {
        $pprof = new PprofProfile([['inuse_objects', 'count'], ['inuse_space', 'bytes']]);
        [$main, $foo, $freed] = [$pprof->frame('main'), $pprof->frame('foo'), $pprof->frame('freed')];
        $pprof->sample([$foo, $main], [1, 512])->sample([$freed, $main], [0, 0]);

        Assert::same(self::shape(self::build($pprof)->tree()), ['main():0:1' => ['foo:0:1' => []]]);
    }

    public function testAddsRootWhenStacksStartAtDifferentFrames(): void
    {
        $pprof = new PprofProfile();
        [$a, $b] = [$pprof->frame('worker'), $pprof->frame('gc')];
        $pprof->sample([$a], [1, 1000])->sample([$b], [3, 3000]);

        $profile = self::build($pprof);

        Assert::same(self::shape($profile->tree()), ['main():4:4' => ['worker:1:1' => [], 'gc:3:3' => []]]);
    }

    public function testPutsEmptyStacksUnderMain(): void
    {
        $pprof = new PprofProfile();
        $pprof->sample([$pprof->frame('foo')], [1, 1000])->sample([], [2, 2000]);

        Assert::same(self::shape(self::build($pprof)->tree()), ['main():3:3' => ['foo:1:1' => []]]);
    }

    #[DataSet(['<?php', '/app/index.php'], 'Datadog')]
    #[DataSet(['/app/index.php', '/app/index.php'], 'named by the file')]
    #[DataSet(['main', ''], 'main')]
    public function testNamesFileScopeRootMain(string $name, string $file): void
    {
        $pprof = new PprofProfile();
        $pprof->sample([$pprof->frame('App::run', '/app/App.php'), $pprof->frame($name, $file)], [1, 1000]);

        Assert::same(self::shape(self::build($pprof)->tree()), ['main():1:1' => ['App::run:1:1' => []]]);
    }

    public function testTellsFunctionsOfSameNameApartByFile(): void
    {
        $pprof = new PprofProfile();
        $main = $pprof->frame('main');
        $html = $pprof->frame('render', 'html.go');
        $json = $pprof->frame('render', 'json.go');
        $same = $pprof->frame('render', 'html.go');
        $pprof->sample([$html, $main], [1, 1000])->sample([$json, $main], [1, 1000])->sample([$same, $main], [1, 1000]);

        Assert::same(self::shape(self::build($pprof)->tree()), [
            'main():3:3' => ['render (html.go):2:2' => [], 'render (json.go):1:1' => []],
        ]);
    }

    public function testNamesFunctionBySystemNameOrUnknown(): void
    {
        $pprof = new PprofProfile();
        $main = $pprof->frame('main');
        $system = $pprof->location([$pprof->function('', systemName: '_ZN3foo3barEv')]);
        $nameless = $pprof->location([$pprof->function('')]);
        $pprof->sample([$system, $main], [1, 1000])->sample([$nameless, $main], [1, 1000]);

        Assert::same(self::shape(self::build($pprof)->tree()), [
            'main():2:2' => ['_ZN3foo3barEv:1:1' => [], '(unknown):1:1' => []],
        ]);
    }

    public function testNamesLocationsWithoutLinesByMappingAndAddress(): void
    {
        $pprof = new PprofProfile();
        $main = $pprof->frame('main');
        $libc = $pprof->location([], $pprof->mapping('/lib/libc.so.6'), 0x1A2B);
        $bare = $pprof->location([], 0, 0xFF);
        $pprof->sample([$libc, $main], [1, 1000])->sample([$bare, $main], [1, 1000]);

        Assert::same(self::shape(self::build($pprof)->tree()), [
            'main():2:2' => ['/lib/libc.so.6+0x1a2b:1:1' => [], '0xff:1:1' => []],
        ]);
    }

    public function testMarksRecursionLevels(): void
    {
        $pprof = new PprofProfile();
        [$main, $fib] = [$pprof->frame('main'), $pprof->frame('fib')];
        $pprof->sample([$fib, $fib, $fib, $main], [1, 1000])->sample([$fib, $main], [1, 1000]);

        $profile = self::build($pprof);

        Assert::same($profile->calls->callers('fib@2')[0]->callerId, 'fib@1');
        Assert::same($profile->calls->callers('fib')[0]->cost->ct, 2);
    }

    public function testSkipsUnknownFields(): void
    {
        $pprof = new PprofProfile();
        $main = $pprof->location([$pprof->function('main', extra: Proto::unknownFields())], extra: Proto::unknownFields());
        $pprof->sample([$main], [1, 1000], extra: Proto::unknownFields() . Proto::bytes(3, Proto::int(1, 1)));
        // Known fields not read: drop frames, a comment, the doc URL
        $pprof->extra = Proto::unknownFields() . Proto::int(7, 0) . Proto::repeated(13, [0, 0]) . Proto::int(15, 0);

        Assert::same(self::shape(self::build($pprof)->tree()), ['main():1:1' => []]);
    }

    public function testPassesMetadataFromProfileAndCaller(): void
    {
        $pprof = new PprofProfile();
        $pprof->period = 10_000_000;
        $pprof->periodType = ['cpu', 'nanoseconds'];
        $pprof->timeNanos = 1_700_000_000_000_000_000;
        $pprof->durationNanos = 2_000_000_000;
        $pprof->sample([$pprof->frame('main.main')], [1, 10_000_000]);

        $profile = (new ProfileBuilder())->createProfile(
            (new ProfileBuilder())->decode($pprof->encode()),
            metadata: ['app_name' => 'shop'],
            tags: ['env' => 'dev'],
            name: 'checkout',
        );

        Assert::same($profile->metadata, [
            'app_name' => 'shop',
            'profile_type' => 'sampled',
            'sample_types' => 'samples/count, cpu/nanoseconds',
            'clock' => 'cpu',
            'period' => 10_000_000,
            'period_type' => 'cpu/nanoseconds',
            'time_nanos' => 1_700_000_000_000_000_000,
            'duration_nanos' => 2_000_000_000,
        ]);
        Assert::same($profile->tags, ['env' => 'dev', 'name' => 'checkout']);
    }

    public function testDecodesGzip(): void
    {
        $pprof = new PprofProfile();
        $pprof->sample([$pprof->frame('main')], [1, 1000]);
        $builder = new ProfileBuilder();

        $profile = $builder->createProfile($builder->decode(\gzencode($pprof->encode())));

        Assert::same(self::shape($profile->tree()), ['main():1:1' => []]);
    }

    public function testRejectsInvalidGzip(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Invalid gzip data.');

        (new ProfileBuilder())->decode("\x1F\x8B\x08\x00broken");
    }

    public function testRejectsGzipInflatingPastLimit(): never
    {
        $pprof = new PprofProfile();
        $pprof->sample([$pprof->frame(\str_repeat('a', 100_000))], [1, 1000]);

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('decompresses to more than 1000 bytes');

        (new ProfileBuilder(maxInflatedSize: 1000))->decode(\gzencode($pprof->encode()));
    }

    public function testRejectsProfileOverMaxSize(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('larger than 16 bytes');

        (new ProfileBuilder(maxSize: 16))->decode(\str_repeat("\x00", 17));
    }

    public function testRejectsTablesTakingTooMuchMemoryDecoded(): never
    {
        // 2 bytes of protobuf and about 64 bytes decoded per string
        $content = \str_repeat(Proto::bytes(6, ''), 600_000);

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('MB decoded');

        (new ProfileBuilder())->decode($content);
    }

    public function testRejectsSampleDeeperThanLimit(): never
    {
        $pprof = new PprofProfile();
        $pprof->sample(\array_fill(0, Document::MAX_STACK_DEPTH + 1, $pprof->frame('deep')), [1, 1000]);

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('More than 16384 values');

        self::build($pprof);
    }

    public function testRejectsInlinedFramesDeeperThanLimit(): never
    {
        $pprof = new PprofProfile();
        $location = $pprof->location(\array_fill(0, 3, $pprof->function('inlined')));
        $pprof->sample(\array_fill(0, 6000, $location), [1, 1000]);

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('deeper than 16384 frames');

        self::build($pprof);
    }

    public function testRejectsProfileWithoutSamples(): never
    {
        $pprof = new PprofProfile();
        $pprof->frame('main');

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('no samples');

        self::build($pprof);
    }

    #[DataSet(["\x08"], 'truncated varint')]
    #[DataSet(["\x08\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x02"], 'varint over 64 bits')]
    #[DataSet(["\x0B"], 'group start')]
    #[DataSet(["\x0C"], 'group end')]
    #[DataSet(["\x0F"], 'wire type 7')]
    #[DataSet(["\x00\x01"], 'field 0')]
    #[DataSet(["\x32\x05ab"], 'string past the end')]
    #[DataSet(["\x09\x01\x02"], 'truncated fixed64')]
    #[DataSet(["\x0A\x01\x08"], 'truncated nested varint')]
    #[DataSet(["\x48\x01\x4A\x00"], 'time as bytes')]
    #[DataSet(["\x22\x00\x22\x02\x10\x05"], 'location of unknown mapping')]
    #[DataSet(["\x22\x04\x08\x01\x22\x02\x08\x07"], 'line of unknown function')]
    #[DataSet(["\x2A\x02\x10\x09"], 'unknown string index')]
    public function testRejectsMalformedData(string $content): never
    {
        Expect::exception(\InvalidArgumentException::class);

        (new ProfileBuilder())->decode($content);
    }

    #[DataSet([[9], [1, 1000]], 'unknown location')]
    #[DataSet([[1], [1]], 'too few values')]
    #[DataSet([[1], [1, -5]], 'negative value')]
    public function testRejectsInvalidSamples(array $locations, array $values): never
    {
        $pprof = new PprofProfile();
        $pprof->frame('main');
        $pprof->sample($locations, $values);

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Sample 0');

        self::build($pprof);
    }

    public function testRejectsValuesOverflowingIntegers(): never
    {
        $pprof = new PprofProfile([['samples', 'count']]);
        $main = $pprof->frame('main');
        $pprof->sample([$main], [2 ** 53])->sample([$main], [2 ** 53]);

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('too large');

        self::build($pprof);
    }

    public function testPrunesColdSamplesOverNodeLimit(): void
    {
        $pprof = new PprofProfile();
        [$main, $hot] = [$pprof->frame('main'), $pprof->frame('hot')];
        $pprof->sample([$hot, $main], [100, 100_000_000]);
        foreach (\range(0, 19) as $i) {
            $pprof->sample([$pprof->frame("cold$i"), $main], [1, 1_000_000]);
        }
        $builder = new ProfileBuilder(maxNodes: 3);

        $profile = $builder->createProfile($builder->decode($pprof->encode()));

        Assert::same(self::shape($profile->tree()), ['main():120000:120' => ['hot:100000:100' => [], '(other):20000:20' => []]]);
        Assert::same($profile->metadata['compacted']['limit'], 3);
    }

    public function testPrunesHeapProfileByMemory(): void
    {
        $pprof = new PprofProfile([['alloc_objects', 'count'], ['alloc_space', 'bytes']]);
        [$main, $big, $many] = [$pprof->frame('main'), $pprof->frame('big'), $pprof->frame('many')];
        $pprof->sample([$big, $main], [1, 1_000_000])->sample([$many, $main], [1000, 1000]);
        foreach (\range(0, 9) as $i) {
            $pprof->sample([$pprof->frame("small$i"), $main], [500, 10]);
        }
        $builder = new ProfileBuilder(maxNodes: 4);

        $profile = $builder->createProfile($builder->decode($pprof->encode()));

        $names = \array_map(static fn(Branch $b): string => $b->item->callee, \array_values($profile->tree()->root)[0]->children);
        Assert::same($names, ['big', 'many', '(other)']);
    }

    public function testLetsOtherFibersRunWhileBuilding(): void
    {
        $pprof = new PprofProfile();
        [$main, $foo] = [$pprof->frame('main'), $pprof->frame('foo')];
        for ($i = 0; $i < 1000; ++$i) {
            $pprof->sample([$foo, $main], [1, 1000]);
        }
        $builder = new ProfileBuilder();
        $document = $builder->decode($pprof->encode());
        $fiber = new \Fiber(static fn(): Profile => $builder->createProfile($document, timeSlice: new TimeSlice(0.0)));

        $suspended = [];
        $value = $fiber->start();
        while (!$fiber->isTerminated()) {
            $suspended[] = $value;
            $value = $fiber->resume();
        }

        Assert::notBlank($suspended);
        Assert::same(\array_unique($suspended), [null]);
        Assert::same(self::shape($fiber->getReturn()->tree()), ['main():1000:1000' => ['foo:1000:1000' => []]]);
    }

    public function testReadsVarintsOf64Bits(): void
    {
        foreach ([0, 1, 127, 128, 300, \PHP_INT_MAX, -1, \PHP_INT_MIN] as $value) {
            $bytes = Proto::varint($value);
            $offset = 0;

            Assert::same(Wire::varint($bytes, $offset, \strlen($bytes)), $value);
            Assert::same($offset, \strlen($bytes));
        }
    }

    private static function build(PprofProfile $pprof): Profile
    {
        $builder = new ProfileBuilder();

        return $builder->createProfile($builder->decode($pprof->encode()));
    }

    /**
     * @param Tree<Edge> $tree
     *
     * @return array<string, array> Nested `callee:wt:ct` keys; repeated siblings get trailing spaces.
     */
    private static function shape(Tree $tree): array
    {
        $map = static function (array $branches) use (&$map): array {
            $result = [];
            /** @var Branch<Edge> $branch */
            foreach ($branches as $branch) {
                $cost = $branch->item->cost;
                $key = "{$branch->item->callee}:{$cost->wt}:{$cost->ct}";
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
