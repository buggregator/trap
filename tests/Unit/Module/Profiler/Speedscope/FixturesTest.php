<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Speedscope;

use Buggregator\Trap\Module\Profiler\Compaction\Nodes;
use Buggregator\Trap\Module\Profiler\Speedscope\Frames;
use Buggregator\Trap\Module\Profiler\Speedscope\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Documents shaped as Excimer and other exporters write them, see `tests/Stub/speedscope/README.md`.
 */
#[Covers(ProfileBuilder::class)]
#[Covers(Frames::class)]
#[Covers(Nodes::class)]
#[Test]
final class FixturesTest
{
    private const STUB_DIR = __DIR__ . '/../../../../Stub/speedscope';

    public function testExcimerCpuTestHasClosuresUnderFileScopeRoot(): void
    {
        // Excimer's tests/cpu.phpt: a 0.1 s period, the file-scope root is `main()` as in XHProf
        $profile = self::build('excimer-cpu.json', period: 0.1);

        Assert::same(self::shape($profile), [
            'main():3000000:30' => ['foo:3000000:30' => ['bar:3000000:30' => [
                '{closure:/data/cpu.php(5)}:800000:8' => [],
                '{closure:/data/cpu.php(8)}:700000:7' => [],
                'C::member:700000:7' => [],
                'baz:800000:8' => [],
            ]]],
        ]);
    }

    public function testExcimerMaxDepthTestKeepsTruncationMarkerAsRoot(): void
    {
        $profile = self::build('excimer-max-depth.json', period: 0.1);

        Assert::same(self::shape($profile), [
            'excimer_truncated:100000:1' => ['foo_6:100000:1' => ['foo_7:100000:1' => [
                'foo_8:100000:1' => ['foo_9:100000:1' => ['foo_10:100000:1' => []]],
            ]]],
        ]);
    }

    public function testTruncatedStacksMakeSecondRoot(): void
    {
        $profile = self::build('excimer-truncated-mixed.json', period: 0.001);

        $roots = $profile->tree()->root;
        Assert::count($roots, 1);
        $main = \reset($roots);
        Assert::same($main->item->callee, 'main()');
        Assert::same([$main->item->cost->ct, $main->item->cost->wt], [5, 5000]);
        Assert::same(\array_map(static fn(Branch $b): string => $b->item->callee, $main->children), [
            '/app/public/index.php',
            'excimer_truncated',
        ]);
        Assert::same($profile->calls->callers('excimer_truncated')[0]->cost->ct, 3);
        Assert::same($profile->calls->callers('App\Tree\Node::render@4')[0]->cost->ct, 1);
    }

    public function testCountsTimerEventsOfOverrunSamples(): void
    {
        $counted = self::build('excimer-overrun.json', period: 0.001);
        $sampled = self::build('excimer-overrun.json');

        Assert::same(self::shape($counted), [
            'main():255000:255' => [
                'App\Bootstrap::boot:1000:1' => [],
                'App\Service\Weather::fetch:251000:251' => ['App\Http\Client::send:250000:250' => []],
                'App\Cache::retry:3000:3' => [],
            ],
        ]);
        Assert::same($sampled->calls->callers('App\Http\Client::send')[0]->cost->ct, 1);
        Assert::same($sampled->calls->roots()[0]->cost->ct, 4);
    }

    public function testMarksDirectAndMutualRecursion(): void
    {
        $profile = self::build('excimer-recursion.json', period: 0.001);

        $ct = static fn(string $callerId, string $calleeId): ?int => \array_values(\array_filter(
            $profile->calls->callers($calleeId),
            static fn(Edge $edge): bool => $edge->callerId === $callerId,
        ))[0]->cost->ct ?? null;
        Assert::same(
            [$ct('fib', 'fib@1'), $ct('fib@1', 'fib@2'), $ct('fib@2', 'fib@3')],
            [3, 2, 1],
        );
        Assert::same(
            [$ct('isEven', 'isOdd'), $ct('isOdd', 'isEven@1'), $ct('isEven@1', 'isOdd@1')],
            [2, 2, 1],
        );
    }

    public function testPassesRealWorldNamesThrough(): void
    {
        $profile = self::build('excimer-real-world-names.json', period: 0.001);

        $functions = $profile->calls->functions();
        foreach ([
            "/app/src/Rules.php(17)_:_eval()'d_code",
            "{closure:/app/src/Rules.php(17)_:_eval()'d_code(3)}",
            'class@anonymous_/app/src/Listener.php:12$0::__invoke',
            'Psr\Log\AbstractLogger@anonymous_/app/src/Log.php:8$1::log',
            'App\Ünïcödé\Сервис::處理',
            '/app/config/bootstrap.php',
        ] as $name) {
            Assert::contains($functions, $name);
        }
        Assert::same(\count(\array_filter($functions, static fn(string $f): bool => \strlen($f) > 250)), 1);
        // An included file is a callee of the script, not a root
        Assert::same($profile->calls->callers('/app/config/bootstrap.php')[0]->callerId, 'main()');
    }

    public function testShutdownCodeMakesMoreRoots(): void
    {
        $profile = self::build('excimer-shutdown-roots.json', period: 0.001);

        Assert::same(self::shape($profile), [
            'main():6000:6' => [
                '/app/public/index.php:3000:3' => ['App\Kernel::handle:3000:3' => []],
                '{closure:/app/public/index.php(7)}:1000:1' => [],
                'Monolog\Handler\BufferHandler::__destruct:2000:2' => [
                    'Monolog\Handler\BufferHandler::close:2000:2' => [],
                ],
            ],
        ]);
    }

    public function testKeepsNameOfMethodRoot(): void
    {
        $profile = self::build('excimer-anonymous-class-root.json', period: 0.001);

        Assert::same(self::shape($profile), [
            'class@anonymous_/app/bootstrap.php:10$0::flush:2000:2' => ['App\Metrics\Exporter::send:1000:1' => []],
        ]);
    }

    public function testNamesCommandLineCodeRootMain(): void
    {
        $profile = self::build('excimer-command-line-root.json', period: 0.001);

        Assert::same(self::shape($profile), ['main():3000:3' => ['work:2000:2' => []]]);
    }

    public function testCountsEmptyStackInFileScopeRoot(): void
    {
        $profile = self::build('excimer-empty-stack.json', period: 0.001);

        // As speedscope, the sample with no frames counts in the total: 2 timer events
        Assert::same(self::shape($profile), ['main():4000:4' => ['work:1000:1' => []]]);
    }

    public function testClosesFramesLeftOpenAtEndOfEventedProfile(): void
    {
        $profile = self::build('evented-nested-unclosed.json');

        Assert::same(self::shape($profile), [
            'main():100:1' => ['a:30:1' => ['a@1:10:1' => []], 'b:10:1' => [], 'a:40:1' => []],
        ]);
    }

    public function testPutsBytesIntoMemory(): void
    {
        $profile = self::build('sampled-bytes.json');

        $root = $profile->calls->roots()[0]->cost;
        Assert::same([$root->ct, $root->wt, $root->cpu, $root->mu], [2, 0, 0, 3072]);
        Assert::same($profile->calls->callers('alloc')[0]->cost->mu, 2048);
    }

    public function testSkipsSamplesOfZeroWeight(): void
    {
        $profile = self::build('sampled-zero-weight.json', period: 0.001);

        Assert::same(self::shape($profile), ['main():1000:1' => ['a:1000:1' => []]]);
    }

    public function testTellsSameNameFramesOfDifferentFilesApart(): void
    {
        $profile = self::build('same-name-different-files.json');

        Assert::same(self::shape($profile), [
            '<module>:30000:3' => ['render (html.py):10000:1' => [], 'render (json.py):20000:2' => []],
        ]);
    }

    public function testTakesTimesOfEventsWithoutUnitAsMicroseconds(): void
    {
        // Speedscope's own sample: its weights are a 14, b 14, c 5, d 4
        $profile = self::build('upstream/simple.speedscope.json');

        Assert::same(self::shape($profile), [
            'a:14:1' => ['b:14:1' => ['c:2:1' => [], 'd:4:1' => [], 'c:3:1' => []]],
        ]);
        Assert::same($profile->calls->callers('c')[0]->cost->wt, 5);
    }

    private static function build(string $stub, ?float $period = null): Profile
    {
        $builder = new ProfileBuilder();

        return $builder->createProfile(
            $builder->decode(\file_get_contents(self::STUB_DIR . '/' . $stub)),
            period: $period,
        );
    }

    /**
     * @return array<string, array> Nested `calleeId:wt:ct` keys; repeated siblings get trailing spaces.
     */
    private static function shape(Profile $profile): array
    {
        $map = static function (array $branches) use (&$map): array {
            $result = [];
            /** @var Branch<Edge> $branch */
            foreach ($branches as $branch) {
                $cost = $branch->item->cost;
                $key = "{$branch->item->calleeId}:{$cost->wt}:{$cost->ct}";
                while (\array_key_exists($key, $result)) {
                    $key .= ' ';
                }
                $result[$key] = $map($branch->children);
            }
            return $result;
        };

        return $map(\array_values($profile->tree()->root));
    }
}
