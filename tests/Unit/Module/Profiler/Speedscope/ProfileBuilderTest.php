<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Speedscope;

use Buggregator\Trap\Module\Profiler\Compaction\Nodes;
use Buggregator\Trap\Module\Profiler\Speedscope\Frames;
use Buggregator\Trap\Module\Profiler\Speedscope\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\Struct\Tree;
use Buggregator\Trap\Support\TimeSlice;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(ProfileBuilder::class)]
#[Covers(Frames::class)]
#[Covers(Nodes::class)]
#[Test]
final class ProfileBuilderTest
{
    private const FRAMES = [
        ['name' => 'Foo::bar', 'file' => '/app/src/Foo.php'],
        ['name' => '{closure:/app/x.php(12)}', 'file' => '/app/x.php'],
        ['name' => 'run', 'file' => '/app/src/run.php'],
    ];

    public function testMergesSamplesWithSameStack(): void
    {
        $profile = self::build(self::sampled(
            [[2, 0], [2, 0, 1], [2], [2, 0]],
            [1000000, 2000000, 500000, 1500],
        ));

        Assert::same(self::shape($profile->tree()), [
            'run:3502:4' => ['Foo::bar:3002:3' => ['{closure:/app/x.php(12)}:2000:1' => []]],
        ]);
        Assert::same($profile->peaks->wt, 3502);
        Assert::same($profile->peaks->ct, 4);
        Assert::same([$profile->peaks->cpu, $profile->peaks->mu, $profile->peaks->pmu], [0, 0, 0]);
    }

    public function testFillsCpuOnCpuClock(): void
    {
        $profile = (new ProfileBuilder())->createProfile(self::sampled([[2, 0]], [3000]), cpuClock: true);

        $root = $profile->calls->roots()[0]->cost;
        Assert::same([$root->wt, $root->cpu], [3, 3]);
        Assert::same($profile->metadata['clock'], 'cpu');
    }

    public function testConvertsUnitsToMicroseconds(): void
    {
        $wt = [];
        foreach (['nanoseconds' => 5000, 'microseconds' => 5, 'milliseconds' => 0.005, 'seconds' => 0.000005] as $unit => $weight) {
            $profile = self::build(self::sampled([[2]], [$weight], $unit));
            $wt[$unit] = $profile->calls->roots()[0]->cost->wt;
        }

        Assert::same($wt, ['nanoseconds' => 5, 'microseconds' => 5, 'milliseconds' => 5, 'seconds' => 5]);
    }

    public function testCountsWeightsWithoutUnit(): void
    {
        $profile = self::build(self::sampled([[2, 0], [2]], [3, 2], 'none'));

        Assert::same(self::shape($profile->tree()), ['run:0:5' => ['Foo::bar:0:3' => []]]);
    }

    public function testKeepsSumOfSubMicrosecondSamplesExact(): void
    {
        // 0.6 µs each: rounded one by one, the children would take more than their parent
        $profile = self::build(self::sampled([[2, 0], [2, 1]], [600, 600]));

        Assert::same(self::shape($profile->tree()), [
            'run:2:2' => ['Foo::bar:1:1' => [], '{closure:/app/x.php(12)}:1:1' => []],
        ]);
    }

    public function testMarksRecursionLevels(): void
    {
        $profile = self::build(self::sampled([[2, 0, 0, 0], [2, 0]], [1000, 1000]));

        Assert::same($profile->calls->callers('Foo::bar@2')[0]->callerId, 'Foo::bar@1');
        Assert::same($profile->calls->callers('Foo::bar')[0]->cost->ct, 2);
        Assert::same(\count($profile->calls), 4);
    }

    public function testAddsRootWhenStacksStartAtDifferentFrames(): void
    {
        $profile = self::build(self::sampled([[2], [0, 1]], [1000, 3000]));

        Assert::same(self::shape($profile->tree()), [
            'main():4:2' => ['run:1:1' => [], 'Foo::bar:3:1' => ['{closure:/app/x.php(12)}:3:1' => []]],
        ]);
        Assert::same($profile->tree()->all['n4']->item->cost->p_wt, 75.0);
    }

    public function testRestoresCallsOfEventedProfile(): void
    {
        $profile = self::build(self::document([[
            'type' => 'evented',
            'name' => 'request',
            'unit' => 'milliseconds',
            'startValue' => 0,
            'endValue' => 10,
            'events' => [
                ['type' => 'O', 'at' => 0, 'frame' => 2],
                ['type' => 'O', 'at' => 1, 'frame' => 0],
                ['type' => 'C', 'at' => 3, 'frame' => 0],
                ['type' => 'O', 'at' => 4, 'frame' => 0],
                ['type' => 'O', 'at' => 4.5, 'frame' => 0],
                ['type' => 'C', 'at' => 5, 'frame' => 0],
                ['type' => 'C', 'at' => 6, 'frame' => 0],
            ],
        ]]));

        Assert::same(self::shape($profile->tree()), [
            'run:10000:1' => ['Foo::bar:2000:1' => [], 'Foo::bar:2000:1 ' => ['Foo::bar:500:1' => []]],
        ]);
        Assert::same($profile->calls->callers('Foo::bar')[0]->cost->ct, 2);
        Assert::same($profile->metadata['profile_type'], 'evented');
        Assert::same($profile->tags, ['name' => 'request']);
    }

    public function testTakesActiveProfile(): void
    {
        $document = self::document([
            self::sampled([[2]], [1000])['profiles'][0],
            self::sampled([[0]], [1000])['profiles'][0],
        ]);

        $first = self::build($document);
        $active = self::build(['activeProfileIndex' => 1] + $document);

        Assert::same($first->calls->roots()[0]->callee, 'run');
        Assert::same($active->calls->roots()[0]->callee, 'Foo::bar');
    }

    public function testPassesMetadataTagsAndName(): void
    {
        $date = new \DateTimeImmutable('2025-01-02 03:04:05');

        $profile = (new ProfileBuilder())->createProfile(
            self::sampled([[2]], [1000]),
            $date,
            ['app_name' => 'app'],
            ['env' => 'test'],
            'checkout',
        );

        Assert::same($profile->date, $date);
        Assert::same($profile->metadata, [
            'app_name' => 'app',
            'exporter' => 'Excimer',
            'clock' => 'wall',
            'profile_type' => 'sampled',
        ]);
        Assert::same($profile->tags, ['env' => 'test', 'name' => 'checkout']);
    }

    public function testDecodesDocument(): void
    {
        $document = self::sampled([[2]], [1000]);

        Assert::same((new ProfileBuilder())->decode(\json_encode($document)), $document);
    }

    public function testRejectsInvalidJson(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Invalid JSON');

        (new ProfileBuilder())->decode('{"profiles":');
    }

    public function testRejectsDocumentOverMaxSize(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('larger than 8 bytes');

        (new ProfileBuilder(maxSize: 8))->decode('{"profiles":[]}');
    }

    public function testRejectsDocumentWithoutFrames(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('shared.frames');

        self::build(['profiles' => []]);
    }

    public function testRejectsUnknownFrameIndex(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Unknown frame index');

        self::build(self::sampled([[2, 3]], [1000]));
    }

    public function testRejectsMismatchedWeights(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('samples and weights');

        self::build(self::sampled([[2], [2]], [1000]));
    }

    public function testRejectsSampleThatIsNotList(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Sample 0 is not a list');

        self::build(self::sampled([['x' => 2]], [1000]));
    }

    public function testRejectsWeightsOverflowingMicroseconds(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('too large');

        self::build(self::sampled([[2]], [1.0e30]));
    }

    public function testRejectsCountsOverflowingIntegers(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('too large');

        self::build(self::sampled([[2], [2]], [\PHP_INT_MAX, \PHP_INT_MAX], 'none'));
    }

    public function testRejectsUnknownUnit(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Unsupported unit: `furlongs`.');

        self::build(self::sampled([[2]], [1000], 'furlongs'));
    }

    public function testRejectsUnknownProfileType(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Unsupported profile type: 42.');

        self::build(self::document([['type' => 42, 'unit' => 'none']]));
    }

    public function testCountsTimerEventsOfSampleByPeriod(): void
    {
        $profile = (new ProfileBuilder())->createProfile(
            self::sampled([[2, 0], [2], [2]], [3_000_000, 1_400_000, 100_000]),
            period: 0.001,
        );

        // 3 periods, 1.4 rounded down, and a sample less than a period still counts
        Assert::same(self::shape($profile->tree()), ['run:4500:5' => ['Foo::bar:3000:3' => []]]);
    }

    public function testRejectsNonPositivePeriod(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('period must be positive');

        (new ProfileBuilder())->createProfile(self::sampled([[2]], [1000]), period: 0.0);
    }

    public function testCountsWeightsOfSamplesUnitLikeNone(): void
    {
        $profile = self::build(self::sampled([[2, 0], [2]], [3, 2], 'samples'));

        Assert::same(self::shape($profile->tree()), ['run:0:5' => ['Foo::bar:0:3' => []]]);
    }

    public function testNamesFrameWithoutNameUnknown(): void
    {
        $document = self::sampled([[2, 3], [2, 4]], [1000, 1000]);
        $document['shared']['frames'][] = ['name' => '', 'file' => '/app/a.php'];
        $document['shared']['frames'][] = ['file' => '/app/b.php'];

        $profile = self::build($document);

        Assert::same(self::shape($profile->tree()), [
            'run:2:2' => ['(unknown) (/app/a.php):1:1' => [], '(unknown) (/app/b.php):1:1' => []],
        ]);
    }

    public function testMergesFramesOfSameNameAndFile(): void
    {
        $document = self::sampled([[2, 0], [2, 3]], [1000, 1000]);
        // Speedscope exporters may tell the lines of a function apart
        $document['shared']['frames'][] = ['name' => 'Foo::bar', 'file' => '/app/src/Foo.php', 'line' => 12];

        $profile = self::build($document);

        Assert::same(self::shape($profile->tree()), ['run:2:2' => ['Foo::bar:2:2' => []]]);
    }

    #[DataSet(['/app/index.php', '/app/index.php'])]
    #[DataSet(['/app/my_app/index.php', '/app/my app/index.php'])]
    #[DataSet(['{main}', null])]
    #[DataSet(['main', null])]
    #[DataSet(['Command_line_code', null])]
    public function testNamesFileScopeRootMain(string $name, ?string $file): void
    {
        $document = self::sampled([[3, 0]], [1000]);
        $document['shared']['frames'][] = ['name' => $name, 'file' => $file];

        $profile = self::build($document);

        Assert::same(self::shape($profile->tree()), ['main():1:1' => ['Foo::bar:1:1' => []]]);
    }

    public function testPutsEmptyStacksUnderMainBesideFunctionRoot(): void
    {
        $profile = self::build(self::sampled([[0], []], [1000, 2000]));

        Assert::same(self::shape($profile->tree()), ['main():3:2' => ['Foo::bar:1:1' => []]]);
    }

    public function testPrunesColdSamplesOverNodeLimit(): void
    {
        // A hot path and 20 functions sampled once
        $document = self::sampled(
            [[2, 0], ...\array_map(static fn(int $i): array => [2, 3 + $i], \range(0, 19))],
            [100_000, ...\array_fill(0, 20, 1000)],
        );
        foreach (\range(0, 19) as $i) {
            $document['shared']['frames'][] = ['name' => "cold$i", 'file' => '/app/cold.php'];
        }

        $profile = (new ProfileBuilder(maxNodes: 3))->createProfile($document);

        Assert::same(self::shape($profile->tree()), ['run:120:21' => ['Foo::bar:100:1' => [], '(other):20:20' => []]]);
        // Counted over the compactions during the build too
        Assert::int($profile->metadata['compacted']['pruned'])->greaterThanOrEqual(20);
        Assert::same($profile->metadata['compacted']['folded'], 16.7);
        Assert::same($profile->metadata['compacted']['limit'], 3);
    }

    public function testMergesCallsOfSameFunctionWhenTheyAreOverNodeLimit(): void
    {
        $events = [['type' => 'O', 'at' => 0, 'frame' => 2]];
        for ($i = 0; $i < 6; ++$i) {
            $events[] = ['type' => 'O', 'at' => 1 + 2 * $i, 'frame' => 0];
            $events[] = ['type' => 'C', 'at' => 2 + 2 * $i, 'frame' => 0];
        }
        $events[] = ['type' => 'C', 'at' => 20, 'frame' => 2];

        $profile = (new ProfileBuilder(maxNodes: 4))->createProfile(self::document([[
            'type' => 'evented',
            'unit' => 'microseconds',
            'events' => $events,
        ]]));

        Assert::same(self::shape($profile->tree()), ['run:20:1' => ['Foo::bar:6:6' => []]]);
        Assert::same($profile->metadata['compacted']['merged'], 5);
    }

    public function testCompactsAgainOnceDeepRecursionEnds(): void
    {
        // 10,300 nested calls are open, so pinned, at the first compaction; the later calls must still get nodes
        $frames = [['name' => 'main'], ['name' => 'rec']];
        $events = [['type' => 'O', 'at' => 0, 'frame' => 0]];
        $at = 0;
        for ($i = 0; $i < 10_300; ++$i) {
            $events[] = ['type' => 'O', 'at' => ++$at, 'frame' => 1];
        }
        for ($i = 0; $i < 10_300; ++$i) {
            $events[] = ['type' => 'C', 'at' => ++$at, 'frame' => 1];
        }
        for ($i = 0; $i < 1200; ++$i) {
            $frame = 2 + $i % 1000;
            $frames[$frame] = ['name' => "f$frame"];
            $events[] = ['type' => 'O', 'at' => ++$at, 'frame' => $frame];
            $events[] = ['type' => 'C', 'at' => ++$at, 'frame' => $frame];
        }
        $events[] = ['type' => 'C', 'at' => ++$at, 'frame' => 0];

        $profile = (new ProfileBuilder(maxNodes: 100))->createProfile([
            'shared' => ['frames' => $frames],
            'profiles' => [['type' => 'evented', 'unit' => 'microseconds', 'events' => $events]],
        ]);

        Assert::same($profile->metadata['compacted']['truncated'], 0);
        Assert::same(self::totalCount($profile), 1 + 10_300 + 1200);
        Assert::same($profile->calls->roots()[0]->cost->wt, $at);
    }

    public function testCountsCallsMadeUnderFoldedCall(): void
    {
        // 10,100 functions calling `g` fill the raw tree: a compaction prunes some into `(other)`, and the later
        // calls of those functions go there, with the calls of `g` they make
        $frames = [['name' => 'main'], ['name' => 'g']];
        $events = [['type' => 'O', 'at' => 0, 'frame' => 0]];
        $at = 0;
        foreach ([\range(0, 10_099), \range(0, 99)] as $round) {
            foreach ($round as $i) {
                $frames[2 + $i] = ['name' => "f$i"];
                $events[] = ['type' => 'O', 'at' => ++$at, 'frame' => 2 + $i];
                $events[] = ['type' => 'O', 'at' => ++$at, 'frame' => 1];
                $events[] = ['type' => 'C', 'at' => ++$at, 'frame' => 1];
                $events[] = ['type' => 'C', 'at' => ++$at, 'frame' => 2 + $i];
            }
        }
        $events[] = ['type' => 'C', 'at' => ++$at, 'frame' => 0];

        $profile = (new ProfileBuilder(maxNodes: 10))->createProfile([
            'shared' => ['frames' => $frames],
            'profiles' => [['type' => 'evented', 'unit' => 'microseconds', 'events' => $events]],
        ]);

        Assert::same(self::totalCount($profile), 1 + 2 * 10_200);
        Assert::same($profile->calls->roots()[0]->cost->wt, $at);
    }

    public function testLetsOtherFibersRunWhileBuilding(): void
    {
        $document = self::sampled(\array_fill(0, 1000, [2, 0, 1]), \array_fill(0, 1000, 1000));
        $fiber = new \Fiber(static fn(): Profile => (new ProfileBuilder())->createProfile(
            $document,
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
        Assert::same(self::shape($fiber->getReturn()->tree()), self::shape(self::build($document)->tree()));
    }

    public function testRejectsDocumentTakingTooMuchMemoryDecoded(): never
    {
        // 4 bytes of JSON and about 200 bytes decoded per sample
        $content = '{"samples":[' . \str_repeat('[0],', 400_000) . '[0]]}';

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('MB decoded');

        (new ProfileBuilder())->decode($content);
    }

    public function testRejectsProfileWithoutSamples(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('no samples');

        self::build(self::sampled([], []));
    }

    public function testRejectsEventClosingOtherFrame(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('closes `Foo::bar`, but `run` is open');

        self::build(self::document([[
            'type' => 'evented',
            'unit' => 'nanoseconds',
            'events' => [['type' => 'O', 'at' => 0, 'frame' => 2], ['type' => 'C', 'at' => 1, 'frame' => 0]],
        ]]));
    }

    public function testPutsSeveralEventedRootsUnderMainSpanningThem(): void
    {
        $profile = self::build(self::document([[
            'type' => 'evented',
            'unit' => 'microseconds',
            'events' => [
                ['type' => 'O', 'at' => 0, 'frame' => 2],
                ['type' => 'C', 'at' => 3, 'frame' => 2],
                ['type' => 'O', 'at' => 5, 'frame' => 0],
                ['type' => 'C', 'at' => 9, 'frame' => 0],
            ],
        ]]));

        Assert::same(self::shape($profile->tree()), ['main():7:1' => ['run:3:1' => [], 'Foo::bar:4:1' => []]]);
    }

    #[DataSet([['profiles' => []]])]
    #[DataSet([['profiles' => ['type' => 'sampled']]])]
    #[DataSet([[]])]
    public function testRejectsDocumentWithoutProfiles(array $document): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('No `profiles` in the document.');

        self::build(['shared' => ['frames' => self::FRAMES]] + $document);
    }

    #[DataSet([['weights' => [1000]], 'needs `samples` and `weights` lists'])]
    #[DataSet([['samples' => [[2]], 'weights' => null], 'needs `samples` and `weights` lists'])]
    #[DataSet([['samples' => [[2]], 'weights' => [-1]], 'Weight 0 is not a non-negative number'])]
    #[DataSet([['samples' => [[2]], 'weights' => ['5']], 'Weight 0 is not a non-negative number'])]
    public function testRejectsInvalidSamples(array $profile, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($message);

        self::build(self::document([$profile + ['type' => 'sampled', 'unit' => 'nanoseconds']]));
    }

    #[DataSet([null, 'needs an `events` list'])]
    #[DataSet([['first' => ['type' => 'O', 'at' => 0, 'frame' => 2]], 'needs an `events` list'])]
    #[DataSet([[['type' => 'C', 'at' => 0, 'frame' => 0]], 'Event 0 closes `Foo::bar`, but no frame is open'])]
    #[DataSet([[['type' => 'X', 'at' => 0, 'frame' => 0]], 'Event 0 is neither `O` nor `C`'])]
    #[DataSet([[['type' => 'O', 'at' => 0, 'frame' => 2], ['type' => 'C', 'at' => 1.0e16, 'frame' => 2]], 'event times are too large'])]
    public function testRejectsInvalidEvents(mixed $events, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($message);

        self::build(self::document([['type' => 'evented', 'unit' => 'microseconds', 'events' => $events]]));
    }

    /**
     * Calls of a per-call profile: the counts of all the tree nodes.
     */
    private static function totalCount(Profile $profile): int
    {
        $count = 0;
        foreach ($profile->tree()->all as $branch) {
            $count += $branch->item->cost->ct;
        }

        return $count;
    }

    private static function build(array $document): Profile
    {
        return (new ProfileBuilder())->createProfile($document);
    }

    private static function sampled(array $samples, array $weights, string $unit = 'nanoseconds'): array
    {
        return self::document([[
            'type' => 'sampled',
            'name' => '',
            'unit' => $unit,
            'startValue' => 0,
            'endValue' => 0,
            'samples' => $samples,
            'weights' => $weights,
        ]]);
    }

    private static function document(array $profiles): array
    {
        return [
            '$schema' => ProfileBuilder::SCHEMA,
            'exporter' => 'Excimer',
            'shared' => ['frames' => self::FRAMES],
            'profiles' => $profiles,
        ];
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
