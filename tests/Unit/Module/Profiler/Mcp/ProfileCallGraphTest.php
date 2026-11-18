<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Profiler\Frame\Type;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileAnalyses;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileCallGraph;
use Buggregator\Trap\Support\TimeSlice;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp\Stub\Profiles;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ProfileCallGraph::class)]
final class ProfileCallGraphTest
{
    public function testPrunesCallsBelowThreshold(): void
    {
        [$trap, $id] = self::shop();

        $text = $trap->get(ProfileCallGraph::class)->call(['id' => $id, 'threshold' => 7]);

        Assert::same($text, <<<TEXT
            XHProf profile $id: the call tree by wall time, the calls of 7% or more; 100% is 1.00 ms, calls in parentheses. The profiler sums the calls by caller and callee, so the tree is unfolded from them: a function called from several places has its callees split by their shares.
            100.0%      1.00 ms  main() (1)
             80.0%       800 µs    App\Controller::index (1)
             50.0%       500 µs      App\Repo::find (3)
             33.3%       333 µs        PDO::query (3)
              5.0%        50 µs        … 1 more call below 7%
              5.0%        50 µs      … 1 more call below 7%
             10.0%       100 µs    App\Repo::find (1)
              7.7%        77 µs      … 2 more calls below 7%

            TEXT);
    }

    public function testProfileWithoutMainIsOfItsRoot(): void
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof([
            'a==>x' => ['ct' => 1, 'wt' => 600, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'a==>y' => ['ct' => 2, 'wt' => 500, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]));

        $text = $trap->get(ProfileCallGraph::class)->call(['id' => $id]);

        Assert::string($text)->contains('100% is 1.10 ms,')->endsWith(<<<'TEXT'
            100.0%      1.10 ms  a
             54.5%       600 µs    x (1)
             45.5%       500 µs    y (2)

            TEXT);
    }

    public function testRootWithoutCallsShowsItsCallees(): void
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof([
            'a==>x' => ['ct' => 1, 'wt' => 600, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'a==>y' => ['ct' => 2, 'wt' => 500, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]));

        $text = $trap->get(ProfileCallGraph::class)->call(['id' => $id, 'metric' => 'ct']);

        Assert::string($text)->endsWith(<<<'TEXT'
            shares.
                              0  a
                              2    y (2)
                              1    x (1)

            TEXT);
    }

    public function testRootWithNegativeMemoryShowsItsCallees(): void
    {
        $trap = new Trap();
        // `main()` frees more than it allocates: its `mu` is shown as zero
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof([
            'main()' => ['ct' => 1, 'wt' => 1000, 'cpu' => 0, 'mu' => -100, 'pmu' => 0],
            'main()==>alloc' => ['ct' => 1, 'wt' => 300, 'cpu' => 0, 'mu' => 400, 'pmu' => 0],
            'main()==>free' => ['ct' => 1, 'wt' => 600, 'cpu' => 0, 'mu' => -500, 'pmu' => 0],
        ]));

        $text = $trap->get(ProfileCallGraph::class)->call(['id' => $id, 'metric' => 'mu']);

        Assert::string($text)->endsWith(<<<'TEXT'
            shares.
              0.0%       0.00 B  main() (1)
            100.0%     400.00 B    alloc (1)
              0.0%       0.00 B    … 1 more call below 1%

            TEXT);
    }

    public function testRecordedTreeIsNotUnfolded(): void
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::SPX, Profiles::spx());

        $text = $trap->get(ProfileCallGraph::class)->call(['id' => $id, 'metric' => 'cpu']);

        Assert::string($text)
            ->startsWith("SPX profile $id: the call tree by CPU time, the calls of 1% or more; 100% is ")
            ->notContains('unfolded')
            ->contains('  main() (1)');
    }

    public function testDepthIsLimited(): void
    {
        [$trap, $id] = self::shop();

        $text = $trap->get(ProfileCallGraph::class)->call(['id' => $id, 'max_depth' => 2, 'metric' => 'ct']);

        Assert::string($text)->endsWith(<<<'TEXT'
            calls of 1% or more; calls in parentheses. The profiler sums the calls by caller and callee, so the tree is unfolded from them: a function called from several places has its callees split by their shares.
                              1  main() (1)
                              1    App\Controller::index (1)
                                     … deeper calls left out: `max_depth`
                              1    App\Repo::find (1)
                                     … deeper calls left out: `max_depth`

            TEXT);
    }

    public function testLongTextIsCut(): void
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprofApp());

        $text = $trap->get(ProfileCallGraph::class)->call(['id' => $id, 'max_depth' => 200, 'max_length' => 1000]);

        Assert::string($text)->matchesRegex('/\n\n\[Truncated: 1000 of at least \d+ characters shown\. Call again with a larger `max_length` for more\.]$/');
        Assert::int(\mb_strlen($text))->lessThan(1200);
    }

    public function testTreeOverNodeBudgetIsCut(): void
    {
        $trap = new Trap();
        $calls = Profiles::lattice(levels: 8, chain: 20);
        $profile = Profiles::xhprof($calls);
        $id = Profiles::add($trap, Type::XHProf, $profile);
        $tool = new ProfileCallGraph(new ProfileAnalyses($trap->store, nodeBudget: static fn(): int => 1_000));

        $text = $tool->call(['id' => $id, 'threshold' => 0.01, 'max_depth' => 200]);

        Assert::string($text)->contains('The tree unfolds into too many calls, so it is cut: it keeps the calls in the order they were made up to a limit and may leave out later ones, heavy ones too. The hot path of `profile_summary` and the callers and callees of `profile_calls` show the heaviest calls.');
        Assert::int(\count($profile->tree()))->lessThanOrEqual(1_000);
        Assert::int(\count(Profiles::xhprof($calls)->calls->unfold(0.0001)))->greaterThan(1_000)->lessThan(200_000);
    }

    public function testTextLetsOtherFibersRun(): void
    {
        $trap = new Trap();
        // A recorded tree: building the text is all the work
        $id = Profiles::add($trap, Type::SPX, Profiles::spx());
        $tool = new ProfileCallGraph(new ProfileAnalyses($trap->store, static fn(): TimeSlice => new TimeSlice(0.0, 1)));
        $fiber = new \Fiber(static fn(): string => $tool->call(['id' => $id, 'threshold' => 0.01, 'max_depth' => 200]));

        $suspensions = 0;
        $fiber->start();
        while (!$fiber->isTerminated()) {
            ++$suspensions;
            $fiber->resume();
        }

        Assert::int($suspensions)->greaterThan(0);
        Assert::string($fiber->getReturn())->startsWith("SPX profile $id: the call tree by ");
    }

    #[DataSet(['XHProf'], 'XHProf')]
    #[DataSet(['Xdebug'], 'Xdebug')]
    #[DataSet(['SPX'], 'SPX')]
    #[DataSet(['pprof'], 'pprof')]
    #[DataSet(['Speedscope'], 'Speedscope')]
    public function testEveryFormat(string $format): void
    {
        $trap = new Trap();
        [$type, $profile] = Profiles::formats()[$format];
        $id = Profiles::add($trap, $type, $profile());

        $text = $trap->get(ProfileCallGraph::class)->call(['id' => $id]);

        Assert::string($text)->startsWith("{$type->value} profile $id: the call tree by ")->matchesRegex('/\n100\.0%  /');
    }

    #[DataSet([['threshold' => 0], '`threshold` must be a number of at least 0.01.'], 'zero threshold')]
    #[DataSet([['threshold' => 101], '`threshold` must be a number from 0.01 to 100.'], 'threshold over 100')]
    #[DataSet([['max_depth' => 0], '`max_depth` must be an integer from 1 to 200.'], 'zero depth')]
    #[DataSet([['max_length' => 10], '`max_length` must be an integer from 100 to 200000.'], 'short text')]
    #[DataSet([['max_length' => 200_001], '`max_length` must be an integer from 100 to 200000.'], 'long text')]
    public function testInvalidArgumentFails(array $arguments, string $message): never
    {
        [$trap, $id] = self::shop();

        Expect::exception(\InvalidArgumentException::class)->withMessage($message);

        $trap->get(ProfileCallGraph::class)->call(['id' => $id] + $arguments);
    }

    public function testDescribesItselfAsReadOnlyText(): void
    {
        $tool = (new Trap())->get(ProfileCallGraph::class);

        Assert::same($tool->name(), 'profile_call_graph');
        Assert::same($tool->title(), 'Call tree of a profile');
        Assert::string($tool->description())->contains('at least `threshold` percent')->contains('`profile_calls`');
        Assert::null($tool->outputSchema(), 'The result is plain text, with no structured content.');
        Assert::same($tool->annotations(), ['readOnlyHint' => true, 'openWorldHint' => false]);
    }

    #[DataSet(['threshold', 0.001], 'threshold')]
    #[DataSet(['max_depth', 1], 'max_depth')]
    #[DataSet(['max_length', 1], 'max_length')]
    public function testSchemaBoundsAreTheAcceptedArguments(string $argument, int|float $step): void
    {
        [$trap, $id] = self::shop();
        $tool = $trap->get(ProfileCallGraph::class);
        $property = $tool->inputSchema()['properties'][$argument];

        foreach ([$property['minimum'], $property['maximum']] as $bound) {
            $tool->call(['id' => $id, $argument => $bound]);
        }
        foreach ([$property['minimum'] - $step, $property['maximum'] + $step] as $outside) {
            try {
                $tool->call(['id' => $id, $argument => $outside]);
                Assert::fail("`$argument` of $outside must be rejected.");
            } catch (\InvalidArgumentException $e) {
                Assert::string($e->getMessage())->startsWith("`$argument` must be");
            }
        }
    }

    public function testSchemaListsTheArguments(): void
    {
        $schema = (new Trap())->get(ProfileCallGraph::class)->inputSchema();
        $properties = $schema['properties'];

        Assert::same(\array_keys($properties), ['id', 'metric', 'threshold', 'max_depth', 'max_length']);
        Assert::same([$schema['required'], $schema['additionalProperties']], [['id'], false]);
        Assert::same($properties['id'], ProfileAnalyses::idSchema());
        Assert::same($properties['metric']['enum'], ['wt', 'cpu', 'mu', 'pmu', 'ct']);
    }

    #[DataSet(['threshold', 0.01, []], 'threshold')]
    #[DataSet(['max_depth', 1, []], 'max_depth')]
    #[DataSet(['max_length', 1, ['threshold' => 0.01, 'max_depth' => 200]], 'max_length')]
    public function testSchemaDefaultsAreTheDefaultsOfTheCall(string $argument, int|float $step, array $arguments): void
    {
        $trap = new Trap();
        $base = ['id' => Profiles::add($trap, Type::XHProf, Profiles::xhprofApp())] + $arguments;
        $tool = $trap->get(ProfileCallGraph::class);
        $default = $tool->inputSchema()['properties'][$argument]['default'];

        Assert::same($tool->call($base), $tool->call($base + [$argument => $default]));
        foreach ([$default - $step, $default + $step] as $other) {
            Assert::notSame($tool->call($base + [$argument => $other]), $tool->call($base), "`$argument` of $other must change the tree.");
        }
    }

    /**
     * @return array{Trap, non-empty-string}
     */
    private static function shop(): array
    {
        $trap = new Trap();

        return [$trap, Profiles::add($trap, Type::XHProf, Profiles::xhprof())];
    }
}
