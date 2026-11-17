<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Profiler\Frame\Type;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileCallGraph;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp\Stub\Profiles;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Test]
#[Covers(ProfileCallGraph::class)]
final class ProfileCallGraphTest
{
    private ?string $memoryLimit = null;

    #[AfterTest]
    public function restoreMemoryLimit(): void
    {
        $this->memoryLimit === null or \ini_set('memory_limit', $this->memoryLimit);
    }

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
            100% is 10, calls in parentheses. The profiler sums the calls by caller and callee, so the tree is unfolded from them: a function called from several places has its callees split by their shares.
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

    public function testTreeTooLargeToUnfoldIsCut(): void
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof(Profiles::lattice(levels: 12, chain: 100)));
        // Little memory left: the tree gets a small budget of nodes, see NodeBudget
        $limit = \ini_set('memory_limit', (string) (\memory_get_usage(true) + (48 << 20)));
        $limit === false and throw new \RuntimeException('Failed to lower `memory_limit`.');
        $this->memoryLimit = $limit;

        $text = $trap->get(ProfileCallGraph::class)->call(['id' => $id, 'threshold' => 0.01, 'max_depth' => 200]);

        Assert::string($text)->contains('The tree unfolds into too many calls, so it is cut: some calls are left out.');
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
    #[DataSet([['max_length' => 10], '`max_length` must be an integer from 100 to ' . \PHP_INT_MAX . '.'], 'short text')]
    public function testInvalidArgumentFails(array $arguments, string $message): never
    {
        [$trap, $id] = self::shop();

        Expect::exception(\InvalidArgumentException::class)->withMessage($message);

        $trap->get(ProfileCallGraph::class)->call(['id' => $id] + $arguments);
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
