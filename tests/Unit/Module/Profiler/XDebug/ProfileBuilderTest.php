<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\XDebug;

use Buggregator\Trap\Module\Profiler\Compaction\Nodes;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\Struct\Tree;
use Buggregator\Trap\Module\Profiler\XDebug\Call;
use Buggregator\Trap\Module\Profiler\XDebug\CallLinker;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(ProfileBuilder::class)]
#[Covers(CallLinker::class)]
#[Covers(Call::class)]
#[Covers(Nodes::class)]
#[Test]
final class ProfileBuilderTest
{
    private const STUB_DIR = __DIR__ . '/../../../../Stub/xdebug';

    public function testRestoresExactCallTree(): void
    {
        $profile = self::build('shared.cachegrind');

        Assert::same(self::shape($profile->tree()), [
            '{main}:31' => [
                'a:12' => ['x:10' => ['y:8' => ['php::str_repeat:2' => []]]],
                'b:6' => [
                    'x:2' => ['y:1' => ['php::str_repeat:0' => []]],
                    'x:1' => ['y:0' => ['php::str_repeat:0' => []]],
                    'x:1 ' => ['y:1' => ['php::str_repeat:0' => []]],
                ],
            ],
        ]);
    }

    public function testSumsTreeIntoGraph(): void
    {
        $calls = self::build('shared.cachegrind')->calls;

        Assert::same(\count($calls), 7);
        Assert::same(
            \array_map(static fn(Edge $e): array => [$e->caller, $e->cost->ct, $e->cost->wt], $calls->callers('x')),
            [['a', 1, 10], ['b', 3, 4]],
        );
        Assert::same($calls->callers('y')[0]->cost->ct, 4);
        Assert::same($calls->callers('y')[0]->cost->wt, 10);
    }

    public function testMarksRecursionLevels(): void
    {
        $profile = self::build('recursion.cachegrind');

        $nested = $profile->calls->callers('bar@1');
        Assert::count($nested, 1);
        Assert::same([$nested[0]->callerId, $nested[0]->callee], ['bar', 'bar']);
        Assert::same($profile->calls->callers('bar')[0]->cost->ct, 2);
        Assert::same(\count($profile->calls), 4);
    }

    public function testComputesPercentagesAndDeltas(): void
    {
        $profile = self::build('shared.cachegrind');

        Assert::same($profile->peaks->wt, 31);
        $a = $profile->tree()->all['n2']->item->cost;
        Assert::same([$a->p_wt, $a->d_wt], [38.71, -19]);
        $b = $profile->calls->callers('b')[0]->cost;
        Assert::same([$b->p_wt, $b->d_wt], [19.355, -25]);
    }

    public function testConvertsTimeToMicrosecondsAndKeepsMemory(): void
    {
        $profile = self::build('recursion.cachegrind');

        $main = $profile->calls->roots()[0];
        Assert::same([$main->callee, $main->cost->wt, $main->cost->mu], ['{main}', 29, 64]);
    }

    public function testKeepsSumOfSubMicrosecondCallsExact(): void
    {
        $blocks = \str_repeat("fn=(1) f\n2 50 0\n\n", 1000)
            . "fn=(2) {main}\n1 0 0\n" . \str_repeat("cfn=(1)\ncalls=1 0 0\n3 50 0\n", 1000);

        $profile = (new ProfileBuilder())->createProfile(self::profile('Time_(10ns) Memory_(bytes)', $blocks));

        $main = $profile->calls->roots()[0];
        $f = $profile->calls->callers('f')[0];
        Assert::same([$main->cost->wt, $f->cost->ct, $f->cost->wt], [500, 1000, 500]);
    }

    public function testSaturatesTimesPastIntegerRange(): void
    {
        $time = '500000000000000000000';
        $profile = (new ProfileBuilder())->createProfile(self::profile(
            'Time_(ns) Memory_(bytes)',
            "fn=(1) a\n2 $time 0\n\nfn=(2) b\n3 $time 0\n\n"
                . "fn=(3) {main}\n1 0 0\ncfn=(1)\ncalls=1 0 0\n2 $time 0\ncfn=(2)\ncalls=1 0 0\n3 $time 0\n",
        ));

        // `PHP_INT_MAX` ns in µs; the time of `{main}` saturates, so it takes the sum of its callees
        Assert::same(self::shape($profile->tree()), [
            '{main}:18446744073709552' => ['a:9223372036854776' => [], 'b:9223372036854776' => []],
        ]);
    }

    public function testSaturatesTimeOfPairPastIntegerRange(): void
    {
        $profile = (new ProfileBuilder())->createProfile(self::profile('Time Memory_(bytes)', self::twoHugeCalls()));

        // The calls of a pair are rounded so that they sum to its total, here saturated; the tree keeps them as floats
        Assert::same(self::shape($profile->tree()), [
            '{main}:9223372036854775807' => ['a:5000000000000000000' => [], 'a:4223372036854775808' => []],
        ]);
    }

    public function testSaturatesTimeOfCalleesPastIntegerRange(): void
    {
        $time = '5000000000000000000';
        $profile = (new ProfileBuilder())->createProfile(self::profile(
            'Time Memory_(bytes)',
            "fn=(1) a\n1 $time 0\n\nfn=(2) b\n1 $time 0\n\nfn=(3) {main}\n1 0 0\n" . self::calls(1, 2),
        ));

        Assert::same(self::shape($profile->tree()), [
            '{main}:9223372036854775807' => ['a:5000000000000000000' => [], 'b:5000000000000000000' => []],
        ]);
    }

    public function testSaturatesMergedCallsPastIntegerRange(): void
    {
        $profile = (new ProfileBuilder(maxNodes: 2))->createProfile(self::profile('Time Memory_(bytes)', self::twoHugeCalls()));

        $a = $profile->calls->callers('a')[0]->cost;
        Assert::same([$a->ct, $a->wt, $a->mu], [2, \PHP_INT_MAX, \PHP_INT_MAX]);
    }

    public function testCallNeverTakesLessThanItsChildren(): void
    {
        // 0.6 µs each: the children round up to 1 µs, while 1.2 µs of the parent would round down
        $profile = (new ProfileBuilder())->createProfile(self::profile(
            'Time_(10ns) Memory_(bytes)',
            "fn=(1) a\n2 60 0\n\nfn=(2) b\n3 60 0\n\n"
                . "fn=(3) {main}\n1 0 0\ncfn=(1)\ncalls=1 0 0\n2 60 0\ncfn=(2)\ncalls=1 0 0\n3 60 0\n",
        ));

        Assert::same(self::shape($profile->tree()), ['{main}:2' => ['a:1' => [], 'b:1' => []]]);
    }

    public function testReadsGzippedProfile(): void
    {
        $content = \file_get_contents(self::STUB_DIR . '/shared.cachegrind');

        $profile = (new ProfileBuilder())->createProfile(\gzencode($content));

        Assert::same(\count($profile->tree()), 15);
    }

    public function testReadsProfileOfMaxSize(): void
    {
        $content = \file_get_contents(self::STUB_DIR . '/shared.cachegrind');

        $profile = (new ProfileBuilder(maxSize: \strlen($content)))->createProfile(\gzencode($content));

        Assert::same(\count($profile->tree()), 15);
    }

    public function testRejectsGzipExpandingOverMaxSize(): void
    {
        $content = self::profile('Time_(10ns) Memory_(bytes)', \str_repeat("fn=(1) f\n2 50 0\n\n", 1000));
        $gzip = \gzencode($content);
        Assert::true(\strlen($gzip) < 1024);
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('larger than 1024 bytes');

        (new ProfileBuilder(maxSize: 1024))->createProfile($gzip);
    }

    public function testRejectsProfileOverMaxSize(): void
    {
        $content = \file_get_contents(self::STUB_DIR . '/shared.cachegrind');
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('larger than');

        (new ProfileBuilder(maxSize: \strlen($content) - 1))->createProfile($content);
    }

    public function testPassesDateMetadataAndTagsThrough(): void
    {
        $date = new \DateTimeImmutable('2025-01-02 03:04:05');

        $profile = (new ProfileBuilder())->createProfile(
            \file_get_contents(self::STUB_DIR . '/shared.cachegrind'),
            $date,
            ['app_name' => 'app'],
            ['env' => 'test'],
        );

        Assert::same($profile->date, $date);
        Assert::same($profile->metadata, ['app_name' => 'app']);
        Assert::same($profile->tags, ['env' => 'test']);
    }

    public function testRejectsOtherContent(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        (new ProfileBuilder())->createProfile('{"profile":{}}');
    }

    public function testRejectsUnknownTimeUnit(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Time_(ms)');

        (new ProfileBuilder())->createProfile(self::profile('Time_(ms) Memory_(bytes)', "fn=(1) {main}\n1 10 0\n"));
    }

    public function testRestoresCallTreeOfEachFiber(): void
    {
        $profile = self::build('fibers/fibers.cachegrind');

        // The fiber's function is called by no listed call, so it is a root
        Assert::same(self::shape($profile->tree()), [
            'task:29' => [
                'work:8' => ['php::str_repeat:1' => []],
                'php::Fiber::suspend:10' => [],
                'work:1' => ['php::str_repeat:0' => []],
                'php::Fiber::suspend:2' => [],
                'work:1 ' => ['php::str_repeat:0' => []],
            ],
            '{main}:79' => [
                'run:68' => [
                    'php::Fiber->__construct:2' => [],
                    'php::Fiber->start:26' => [],
                    'work:6' => ['php::str_repeat:1' => []],
                    'php::Fiber->resume:3' => [],
                    'work:0' => ['php::str_repeat:0' => []],
                    'php::Fiber->resume:16' => [],
                ],
            ],
        ]);
        Assert::false(isset($profile->metadata['unlinked']));
        Assert::same($profile->metadata['fibers'], ['roots' => 1, 'wt' => 29, 'mu' => 408]);
    }

    public function testKeepsCallsOfEachStackUnderTheirCaller(): void
    {
        // `work()` runs on both sides of every switch, `work:60` and `work:50` on both
        $profile = self::build('fibers/loop.cachegrind');

        $roots = \array_values($profile->tree()->root);
        Assert::same(self::callees($roots), ['task', '{main}']);
        Assert::same(self::callees($roots[0]->children), self::repeat(6, 'work', 'php::Fiber::suspend'));
        Assert::same(
            self::callees($roots[1]->children[0]->children),
            ['php::Fiber->__construct', 'php::Fiber->start', ...self::repeat(5, 'work', 'php::Fiber->resume')],
        );
        Assert::false(isset($profile->metadata['unlinked']));
        Assert::same($profile->metadata['fibers']['roots'], 1);
    }

    public function testPutsBackCallsEndedAfterFiberIsDestroyed(): void
    {
        // The fiber is destroyed while suspended: its calls end, and `run()` goes on with no switch between
        $profile = self::build('fibers/destroyed.cachegrind');

        Assert::same(self::shape($profile->tree()), [
            'task:22' => ['work:7' => ['php::str_repeat:1' => []], 'php::Fiber::suspend:7' => []],
            '{main}:73' => [
                'run:62' => [
                    'php::Fiber->__construct:2' => [],
                    'php::Fiber->start:23' => [],
                    'work:1' => ['php::str_repeat:1' => []],
                    'work:1 ' => ['php::str_repeat:0' => []],
                ],
            ],
        ]);
        Assert::false(isset($profile->metadata['unlinked']));
        Assert::same($profile->metadata['fibers']['roots'], 1);
    }

    public function testRestoresFiberStartedInFiber(): void
    {
        $profile = self::build('fibers/nested.cachegrind');

        Assert::same(self::shape($profile->tree()), [
            'inner:23' => [
                'work:7' => ['php::str_repeat:2' => []],
                'php::Fiber::suspend:9' => [],
                'work:1' => ['php::str_repeat:0' => []],
            ],
            'outer:47' => [
                'php::Fiber->__construct:0' => [],
                'php::Fiber->start:18' => [],
                'work:1' => ['php::str_repeat:0' => []],
                'php::Fiber::suspend:3' => [],
                'php::Fiber->resume:17' => [],
                'work:1 ' => ['php::str_repeat:0' => []],
            ],
            '{main}:97' => [
                'run:85' => [
                    'php::Fiber->__construct:3' => [],
                    'php::Fiber->start:35' => [],
                    'work:1' => ['php::str_repeat:0' => []],
                    'php::Fiber->resume:29' => [],
                    'work:0' => ['php::str_repeat:0' => []],
                ],
            ],
        ]);
        Assert::same($profile->metadata['fibers']['roots'], 2);
    }

    public function testJoinsStacksOfFibersRunInTurns(): void
    {
        // 50 fibers of 3 to 6 steps, resumed in turns: the stacks they suspend look alike
        $profile = self::build('fibers/scheduler.cachegrind.gz');

        $tasks = [];
        foreach ($profile->tree()->root as $root) {
            $root->item->callee === 'task' and $tasks[] = \count($root->children);
        }
        \sort($tasks);
        // A task of n steps makes n `work()` and `Fiber::suspend()` calls and the last `work()`
        $expected = [...\array_fill(0, 13, 7), ...\array_fill(0, 13, 9), ...\array_fill(0, 12, 11), ...\array_fill(0, 12, 13)];
        Assert::same($tasks, $expected);
        Assert::false(isset($profile->metadata['unlinked']));
        Assert::same($profile->metadata['fibers']['roots'], 50);
    }

    public function testKeepsCallWhoseCalleesDidNotEndBefore(): void
    {
        $profile = (new ProfileBuilder())->createProfile(self::profile(
            'Time Memory_(bytes)',
            "fn=(1) {main}\n1 10 0\ncfn=(2) foo\ncalls=1 0 0\n2 5 0\n",
        ));

        // The call takes the time of the callees it lists
        Assert::same(self::shape($profile->tree()), ['{main}:15' => []]);
        Assert::same($profile->metadata['unlinked'], 1);
    }

    public function testKeepsCallOfOtherFunctionAsRoot(): void
    {
        $profile = (new ProfileBuilder())->createProfile(self::profile(
            'Time Memory_(bytes)',
            "fn=(2) foo\n2 5 0\n\nfn=(1) {main}\n1 10 0\ncfn=(3) bar\ncalls=1 0 0\n2 5 0\n",
        ));

        Assert::same(self::shape($profile->tree()), ['foo:5' => [], '{main}:15' => []]);
        Assert::same($profile->metadata['unlinked'], 1);
    }

    public function testMergesCallsOfSameFunctionOnSamePathOverNodeLimit(): void
    {
        $blocks = "fn=(1) x\n1 1 0\n\nfn=(2) y\n1 2 4\n\nfn=(3) handle\n1 4 0\n" . self::calls(1, 2) . "\n"
            . "fn=(1)\n1 8 0\n\nfn=(2)\n1 16 4\n\nfn=(3)\n1 32 0\n" . self::calls(1, 2) . "\n"
            . "fn=(4) {main}\n1 0 0\n" . self::calls(3, 3);

        $profile = (new ProfileBuilder(maxNodes: 4))->createProfile(self::profile('Time Memory_(bytes)', $blocks));

        // 7 calls, 4 call paths
        Assert::same(self::shape($profile->tree()), ['{main}:63' => ['handle:63' => ['x:9' => [], 'y:18' => []]]]);
        Assert::same($profile->calls->callers('handle')[0]->cost->ct, 2);
        Assert::same($profile->calls->callers('y')[0]->cost->mu, 8);
        Assert::same($profile->metadata['compacted']['merged'], 3);
    }

    public function testPrunesColdCallsOverNodeLimit(): void
    {
        $blocks = "fn=(1) a\n1 1 0\n\nfn=(2) b\n1 2 0\n\nfn=(3) c\n1 4 0\n\nfn=(4) d\n1 8 0\n\n"
            . "fn=(5) {main}\n1 16 0\n" . self::calls(1, 2, 3, 4);

        $profile = (new ProfileBuilder(maxNodes: 3))->createProfile(self::profile('Time Memory_(bytes)', $blocks));

        Assert::same(self::shape($profile->tree()), ['{main}:31' => ['(other):7' => [], 'd:8' => []]]);
        Assert::same($profile->calls->callers('(other)')[0]->cost->ct, 3);
        Assert::same($profile->metadata['compacted']['pruned'], 3);
    }

    public function testKeepsRootOfProfileUnderAnyLimit(): void
    {
        $blocks = "fn=(1) a\n1 1 0\n\nfn=(2) b\n1 2 0\n\nfn=(3) {main}\n1 4 0\n" . self::calls(1, 2);

        $profile = (new ProfileBuilder(maxNodes: 1))->createProfile(self::profile('Time Memory_(bytes)', $blocks));

        Assert::same(self::shape($profile->tree()), ['{main}:7' => ['(other):3' => []]]);
    }

    #[DataSet(['Time_(ns)', 5000, 5], 'nanoseconds')]
    #[DataSet(['Time_(10ns)', 500, 5], 'tens of nanoseconds')]
    #[DataSet(['Time_(µs)', 5, 5], 'microseconds')]
    #[DataSet(['Time', 5, 5], 'unitless time in microseconds')]
    public function testConvertsTimeUnitToMicroseconds(string $unit, int $time, int $wt): void
    {
        $profile = (new ProfileBuilder())->createProfile(self::profile("$unit Memory_(bytes)", "fn=(1) {main}\n1 $time 0\n"));

        Assert::same($profile->calls->roots()[0]->cost->wt, $wt);
    }

    public function testReadsUncompressedNames(): void
    {
        $profile = (new ProfileBuilder())->createProfile(self::profile(
            'Time Memory_(bytes)',
            "fn=foo\n1 2 0\n\nfn={main}\n1 5 0\ncfn=foo\ncalls=1 0 0\n1 2 0\n",
        ));

        Assert::same(self::shape($profile->tree()), ['{main}:7' => ['foo:2' => []]]);
    }

    #[DataSet(["fn=\n1 5 0\n", 'Empty function name.'], 'empty name')]
    #[DataSet(["fn=(1) {main}\n1 5 0\ncfn=(7)\ncalls=1 0 0\n1 2 0\n", 'Unknown function name ID `7`.'], 'ID never defined')]
    public function testRejectsInvalidFunctionName(string $blocks, string $message): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage($message);

        (new ProfileBuilder())->createProfile(self::profile('Time Memory_(bytes)', $blocks));
    }

    #[DataSet(["fn=(1) a\n1 1 0\n"], 'no call ends before the end')]
    #[DataSet(["fn=(1) a\n1 1 0\n\nfn=(2) {main}\n1 2 0\ncfn=(1)\ncalls=1 0 0\n1 1 0\n"], 'a call ends before')]
    public function testRejectsProfileWithoutEvents(string $blocks): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('No `events` line in the profile.');

        (new ProfileBuilder())->createProfile(
            "version: 1\ncreator: xdebug 3.4.0 (PHP 8.4.0)\ncmd: /app/a.php\npart: 1\npositions: line\n\n$blocks",
        );
    }

    public function testReadsCrlfLineEndings(): void
    {
        $content = \str_replace("\r\n", "\n", \file_get_contents(self::STUB_DIR . '/shared.cachegrind'));

        $profile = (new ProfileBuilder())->createProfile(\str_replace("\n", "\r\n", $content));

        Assert::same(self::shape($profile->tree()), self::shape(self::build('shared.cachegrind')->tree()));
    }

    public function testReadsCallStraddlingChunkBoundary(): void
    {
        $count = 10_000;
        $content = self::profile(
            'Time Memory_(bytes)',
            \str_repeat("fn=(1) f\n2 3 0\n\n", $count)
                . "fn=(2) {main}\n1 7 0\n" . \str_repeat("cfn=(1)\ncalls=1 0 0\n2 3 0\n", $count),
        );
        // The first 256 KB chunk ends after the `calls=` line of a call, its cost line starts the next chunk
        $chunk = 256 * 1024;
        $start = \strrpos(\substr($content, 0, $chunk), "\n") + 1;
        Assert::same(\substr($content, $start, \strpos($content, "\n", $chunk) - $start), 'calls=1 0 0');

        $profile = (new ProfileBuilder())->createProfile($content);

        $f = $profile->calls->callers('f')[0];
        Assert::same([$f->caller, $f->cost->ct, $f->cost->wt], ['{main}', $count, 3 * $count]);
        Assert::same($profile->calls->roots()[0]->cost->wt, 3 * $count + 7);
        Assert::same(\count($profile->tree()), $count + 1);
    }

    public function testSumsCallsPastHardNodeLimitIntoTruncatedNode(): void
    {
        // The compaction keeps the roots, so past the hard limit the next roots and their callees are truncated
        $count = 41_000;
        $blocks = '';
        for ($i = 1; $i <= $count; ++$i) {
            $blocks .= "fn=($i) r$i\n1 1 0\n\n";
        }
        $leaf = $count + 1;
        $top = $count + 2;
        $blocks .= "fn=($leaf) leaf\n1 2 0\n\nfn=($top) top\n1 5 0\ncfn=($leaf)\ncalls=1 0 0\n1 2 0\n";

        $profile = (new ProfileBuilder(maxNodes: 1))->createProfile(self::profile('Time Memory_(bytes)', $blocks));

        $truncated = $profile->calls->callers(Profile::TRUNCATED);
        Assert::count($truncated, 1);
        Assert::null($truncated[0]->caller);
        Assert::same($profile->calls->callers('leaf'), []);
        Assert::same($profile->calls->callers('top'), []);
        Assert::true($profile->metadata['compacted']['truncated'] > 0);
    }

    private static function calls(int ...$ids): string
    {
        return \implode('', \array_map(static fn(int $id): string => "cfn=($id)\ncalls=1 0 0\n1 0 0\n", $ids));
    }

    /**
     * `{main}` calls `a` twice; each call takes 5e18 µs and bytes.
     */
    private static function twoHugeCalls(): string
    {
        $cost = '5000000000000000000 5000000000000000000';

        return "fn=(1) a\n1 $cost\n\nfn=(1)\n1 $cost\n\nfn=(2) {main}\n1 0 0\n" . self::calls(1, 1);
    }

    private static function build(string $stub): Profile
    {
        return (new ProfileBuilder())->createProfile(\file_get_contents(self::STUB_DIR . '/' . $stub));
    }

    private static function profile(string $events, string $blocks): string
    {
        return "version: 1\ncreator: xdebug 3.4.0 (PHP 8.4.0)\ncmd: /app/a.php\npart: 1\npositions: line\n\n"
            . "events: $events\n\n$blocks";
    }

    /**
     * @param list<Branch<Edge>> $branches
     *
     * @return list<string>
     */
    private static function callees(array $branches): array
    {
        return \array_map(static fn(Branch $branch): string => $branch->item->callee, $branches);
    }

    /**
     * @return list<string> The names repeated `$times` times.
     */
    private static function repeat(int $times, string ...$names): array
    {
        return \array_merge(...\array_fill(0, $times, $names));
    }

    /**
     * @param Tree<Edge> $tree
     *
     * @return array<string, array> Nested `callee:wt` keys; repeated siblings get trailing spaces.
     */
    private static function shape(Tree $tree): array
    {
        $map = static function (array $branches) use (&$map): array {
            $result = [];
            /** @var Branch<Edge> $branch */
            foreach ($branches as $branch) {
                $key = $branch->item->callee . ':' . $branch->item->cost->wt;
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
