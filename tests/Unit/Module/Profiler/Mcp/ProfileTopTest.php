<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Mcp\Tools;
use Buggregator\Trap\Module\Profiler\Frame\Type;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileAnalysis;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileSchema;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileSummary;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileTop;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\JsonSchema;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp\Stub\Profiles;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ProfileTop::class)]
#[Covers(ProfileAnalysis::class)]
#[Covers(ProfileSchema::class)]
final class ProfileTopTest
{
    public function testRanksByExclusiveWallTime(): void
    {
        [$trap, $id] = self::shop();
        $tool = $trap->get(ProfileTop::class);

        $result = $tool->call(['id' => $id]);

        Assert::same(\array_diff_key($result, ['functions' => true]), [
            'metric' => 'wt',
            'unit' => 'microseconds',
            'peak' => 1000,
            'sort' => 'exclusive',
            'matched' => 5,
            'has_more' => false,
        ]);
        Assert::same(\array_column($result['functions'], 'exclusive'), [400, 250, 200, 100, 50]);
        Assert::same($result['functions'][1], [
            'function' => 'App\Controller::index',
            'calls' => 1,
            'inclusive' => 800,
            'inclusive_percent' => 80.0,
            'exclusive' => 250,
            'exclusive_percent' => 25.0,
        ]);
        Assert::same(JsonSchema::errors($result, $tool->outputSchema()), []);
    }

    public function testRanksByInclusiveValue(): void
    {
        [$trap, $id] = self::shop();

        $result = $trap->get(ProfileTop::class)->call(['id' => $id, 'sort' => 'inclusive']);

        Assert::same(\array_column($result['functions'], 'function'), [
            'main()',
            'App\Controller::index',
            'App\Repo::find',
            'PDO::query',
            'strlen',
        ]);
        Assert::same($result['sort'], 'inclusive');
    }

    /**
     * @param list<int> $exclusive
     */
    #[DataSet(['cpu', 'microseconds', 600, [250, 160, 100, 50, 40]], 'CPU time')]
    #[DataSet(['mu', 'bytes', 4000, [1900, 800, 700, 500, 100]], 'memory')]
    #[DataSet(['ct', 'calls', 10, [10, 5, 3, 1, 1]], 'calls')]
    public function testRanksByGivenMetric(string $metric, string $unit, int $peak, array $exclusive): void
    {
        [$trap, $id] = self::shop();

        $result = $trap->get(ProfileTop::class)->call(['id' => $id, 'metric' => $metric]);

        Assert::same([$result['metric'], $result['unit'], $result['peak']], [$metric, $unit, $peak]);
        Assert::same(\array_column($result['functions'], 'exclusive'), $exclusive);
    }

    #[DataSet(['app\\', ['App\Controller::index', 'App\Repo::find']], 'substring in any case')]
    #[DataSet(['App\\*', ['App\Controller::index', 'App\Repo::find']], 'wildcard')]
    #[DataSet(['*::query', ['PDO::query']], 'wildcard of the end')]
    #[DataSet(['Repo', ['App\Repo::find']], 'part of a name')]
    #[DataSet(['nothing', []], 'no match')]
    public function testFiltersFunctions(string $filter, array $functions): void
    {
        [$trap, $id] = self::shop();

        $result = $trap->get(ProfileTop::class)->call(['id' => $id, 'filter' => $filter]);

        Assert::same(\array_column($result['functions'], 'function'), $functions);
        Assert::same($result['matched'], \count($functions));
    }

    public function testLimitsFunctions(): void
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprofApp());
        $tool = $trap->get(ProfileTop::class);

        $default = $tool->call(['id' => $id]);
        $two = $tool->call(['id' => $id, 'limit' => 2]);
        $all = $tool->call(['id' => $id, 'limit' => 200]);

        Assert::count($default['functions'], 20);
        Assert::same([$default['matched'], $default['has_more']], [582, true]);
        Assert::same($two['functions'], \array_slice($default['functions'], 0, 2));
        Assert::count($all['functions'], 200);
    }

    #[DataSet(['XHProf'], 'XHProf')]
    #[DataSet(['Xdebug'], 'Xdebug')]
    #[DataSet(['SPX'], 'SPX')]
    #[DataSet(['pprof'], 'pprof')]
    #[DataSet(['Speedscope'], 'Speedscope')]
    public function testEveryMeasuredMetricOfEveryFormat(string $format): void
    {
        $trap = new Trap();
        [$type, $profile] = Profiles::formats()[$format];
        $id = Profiles::add($trap, $type, $profile());
        $tool = $trap->get(ProfileTop::class);

        foreach (['exclusive', 'inclusive'] as $sort) {
            foreach (self::metrics($trap, $id) as $metric) {
                $result = $tool->call(['id' => $id, 'metric' => $metric, 'sort' => $sort]);

                $values = \array_column($result['functions'], $sort);
                $sorted = $values;
                \rsort($sorted);
                Assert::same($values, $sorted);
                Assert::notBlank($result['functions']);
                foreach ($result['functions'] as $row) {
                    Assert::int($row['inclusive'])->greaterThanOrEqual(0);
                    Assert::int($row['exclusive'])->greaterThanOrEqual(0)->lessThanOrEqual($row['inclusive']);
                    foreach (['inclusive_percent', 'exclusive_percent'] as $percent) {
                        $row[$percent] === null or Assert::numeric($row[$percent])->between(0, 100);
                    }
                }
                Assert::same(JsonSchema::errors($result, $tool->outputSchema()), []);
            }
        }
    }

    public function testMetricNotMeasuredFails(): never
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XDebug, Profiles::xdebug());

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage("XDebug profile `$id` does not measure `cpu`; it has: wt, mu, ct.");

        $trap->get(ProfileTop::class)->call(['id' => $id, 'metric' => 'cpu']);
    }

    #[DataSet([['metric' => 'time'], '`metric` must be one of: wt, cpu, mu, pmu, ct.'], 'unknown metric')]
    #[DataSet([['sort' => 'self'], '`sort` must be one of: exclusive, inclusive.'], 'unknown order')]
    #[DataSet([['limit' => 0], '`limit` must be an integer from 1 to 200.'], 'zero limit')]
    #[DataSet([['limit' => 201], '`limit` must be an integer from 1 to 200.'], 'limit over the maximum')]
    #[DataSet([['filter' => ''], '`filter` must be a non-empty string.'], 'empty filter')]
    #[DataSet([['filter' => ['App']], '`filter` must be a non-empty string.'], 'filter list')]
    public function testInvalidArgumentFails(array $arguments, string $message): never
    {
        [$trap, $id] = self::shop();

        Expect::exception(\InvalidArgumentException::class)->withMessage($message);

        $trap->get(ProfileTop::class)->call(['id' => $id] + $arguments);
    }

    public function testProfileWithoutCallsFails(): never
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof([]));

        Expect::exception(\InvalidArgumentException::class)->withMessage("Profile `$id` measures nothing: it has no calls.");

        $trap->get(ProfileTop::class)->call(['id' => $id]);
    }

    public function testProfileToolsAreListed(): void
    {
        $tools = (new Trap())->get(Tools::class);

        $names = \array_map(static fn(object $tool): string => $tool->name(), $tools->all());

        Assert::array($names)
            ->contains('profile_summary')
            ->contains('profile_top')
            ->contains('profile_calls')
            ->contains('profile_call_graph');
        foreach (['profile_summary', 'profile_top', 'profile_calls', 'profile_call_graph'] as $name) {
            Assert::same($tools->get($name)?->annotations(), ['readOnlyHint' => true, 'openWorldHint' => false]);
        }
    }

    public function testDescribesItself(): void
    {
        $tool = (new Trap())->get(ProfileTop::class);

        Assert::same($tool->title(), 'Top functions of a profile');
        Assert::string($tool->description())->contains('Sort by `exclusive`')->contains('`filter`')->contains('`App\*`');
    }

    public function testSchemaBoundsAreTheAcceptedArguments(): void
    {
        [$trap, $id] = self::shop();
        $tool = $trap->get(ProfileTop::class);
        $limit = $tool->inputSchema()['properties']['limit'];

        foreach ([$limit['minimum'], $limit['maximum']] as $bound) {
            $tool->call(['id' => $id, 'limit' => $bound]);
        }
        foreach ([$limit['minimum'] - 1, $limit['maximum'] + 1] as $outside) {
            try {
                $tool->call(['id' => $id, 'limit' => $outside]);
                Assert::fail("`limit` of $outside must be rejected.");
            } catch (\InvalidArgumentException $e) {
                Assert::string($e->getMessage())->startsWith('`limit` must be');
            }
        }
    }

    public function testSchemaListsTheArguments(): void
    {
        $schema = (new Trap())->get(ProfileTop::class)->inputSchema();
        $properties = $schema['properties'];

        Assert::same(\array_keys($properties), ['id', 'metric', 'sort', 'filter', 'limit']);
        Assert::same([$schema['required'], $schema['additionalProperties']], [['id'], false]);
        Assert::same([$properties['sort']['enum'], $properties['sort']['default']], [['exclusive', 'inclusive'], 'exclusive']);
        Assert::same($properties['limit']['default'], 20);
    }

    public function testMetricSchemaListsEveryMetricAndWhatTheyAre(): void
    {
        $metric = ProfileSchema::metric();

        Assert::same([$metric['type'], $metric['enum']], ['string', ['wt', 'cpu', 'mu', 'pmu', 'ct']]);
        Assert::string($metric['description'])->contains('`pmu` peak memory');
    }

    /**
     * @return array{Trap, non-empty-string}
     */
    private static function shop(): array
    {
        $trap = new Trap();

        return [$trap, Profiles::add($trap, Type::XHProf, Profiles::xhprof())];
    }

    /**
     * @return list<string>
     */
    private static function metrics(Trap $trap, string $id): array
    {
        return $trap->get(ProfileSummary::class)->call(['id' => $id])['metrics'];
    }
}
