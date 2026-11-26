<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Profiler\Frame\Type;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileAnalyses;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileAnalysis;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileSummary;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\JsonSchema;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp\Stub\Profiles;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ProfileSummary::class)]
#[Covers(ProfileAnalysis::class)]
#[Covers(ProfileAnalyses::class)]
final class ProfileSummaryTest
{
    public function testSummarizesProfile(): void
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof(metadata: ['app_name' => 'shop', 'hostname' => 'web-1']));

        $result = $trap->get(ProfileSummary::class)->call(['id' => $id]);

        Assert::same(\array_diff_key($result, ['date' => true, 'top' => true, 'hot_path' => true]), [
            'id' => $id,
            'name' => 'shop',
            'format' => 'XHProf',
            'hostname' => 'web-1',
            'count_type' => 'calls',
            'metrics' => ['wt', 'cpu', 'mu', 'pmu', 'ct'],
            'not_measured' => [],
            'peaks' => ['wt' => 1000, 'cpu' => 600, 'mu' => 4000, 'pmu' => 5000, 'ct' => 10],
            'functions' => 5,
            'edges' => 7,
            'metric' => 'wt',
            'unit' => 'microseconds',
            'hot_path_length' => 4,
        ]);
        Assert::same(\array_column($result['top'], 'function'), [
            'PDO::query',
            'App\Controller::index',
            'App\Repo::find',
            'main()',
            'strlen',
        ]);
        Assert::same($result['top'][2], [
            'function' => 'App\Repo::find',
            'calls' => 5,
            'inclusive' => 600,
            'inclusive_percent' => 60.0,
            'exclusive' => 200,
            'exclusive_percent' => 20.0,
        ]);
        Assert::same($result['hot_path'][2], [
            'depth' => 2,
            'function' => 'App\Repo::find',
            'calls' => 3,
            'value' => 500,
            'percent' => 50.0,
        ]);
        Assert::same(\array_column($result['hot_path'], 'function'), ['main()', 'App\Controller::index', 'App\Repo::find', 'PDO::query']);
        Assert::string($result['date'])->matchesRegex('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/');
        Assert::same(JsonSchema::errors($result, $trap->get(ProfileSummary::class)->outputSchema()), []);
    }

    /**
     * @param list<string> $metrics
     */
    #[DataSet(['XHProf', ['wt', 'cpu', 'mu', 'pmu', 'ct'], 'calls', 'wt'], 'XHProf')]
    #[DataSet(['Xdebug', ['wt', 'mu', 'ct'], 'calls', 'wt'], 'Xdebug')]
    #[DataSet(['SPX', ['wt', 'cpu', 'mu', 'ct'], 'calls', 'wt'], 'SPX')]
    #[DataSet(['pprof', ['mu', 'ct'], 'alloc_objects', 'mu'], 'pprof')]
    #[DataSet(['Speedscope', ['wt', 'ct'], 'samples', 'wt'], 'Speedscope')]
    #[DataSet(['phpspy', ['wt', 'ct'], 'samples', 'wt'], 'phpspy')]
    #[DataSet(['collapsed', ['ct'], 'samples', 'ct'], 'collapsed')]
    public function testSummarizesEveryFormat(string $format, array $metrics, string $countType, string $metric): void
    {
        $trap = new Trap();
        [$type, $profile] = Profiles::formats()[$format];
        $id = Profiles::add($trap, $type, $profile());
        $tool = $trap->get(ProfileSummary::class);

        $result = $tool->call(['id' => $id]);

        Assert::same($result['format'], $type->value);
        Assert::same($result['metrics'], $metrics);
        Assert::same(\array_keys($result['peaks']), $metrics);
        Assert::same(\count($result['not_measured']) + \count($metrics), 5);
        Assert::same($result['count_type'], $countType);
        Assert::same($result['metric'], $metric);
        Assert::notBlank($result['top']);
        Assert::notBlank($result['hot_path']);
        Assert::same(JsonSchema::errors($result, $tool->outputSchema()), []);
    }

    public function testTellsDetailsOfSpxReport(): void
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::SPX, Profiles::spx());

        $result = $trap->get(ProfileSummary::class)->call(['id' => $id]);

        Assert::same($result['details']['Command'], 'calls.php');
        Assert::same($result['not_measured'], ['pmu']);
    }

    public function testLongHotPathKeepsStartAndEnd(): void
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprofApp());

        $result = $trap->get(ProfileSummary::class)->call(['id' => $id]);

        Assert::same($result['hot_path_length'], 473);
        Assert::same(\array_column($result['hot_path'], 'depth'), [...\range(0, 4), ...\range(448, 472)]);
        Assert::same($result['hot_path'][0]['function'], 'main()');
        Assert::count($result['top'], 10);
        Assert::same($result['name'], 'app');
    }

    public function testProfileWithoutCalls(): void
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, new Profile());
        $tool = $trap->get(ProfileSummary::class);

        $result = $tool->call(['id' => $id]);

        Assert::same([$result['metric'], $result['unit'], $result['top'], $result['hot_path']], [null, null, [], []]);
        Assert::same($result['metrics'], []);
        Assert::same(JsonSchema::errors($result, $tool->outputSchema()), []);
    }

    public function testKeepsAnalysisOfRecentProfiles(): void
    {
        $trap = new Trap();
        $analyses = $trap->get(ProfileAnalyses::class);
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = Profiles::add($trap, Type::XHProf, Profiles::xhprof());
        }
        $first = $analyses->get(['id' => $ids[0]]);

        $again = $analyses->get(['id' => $ids[0]]);
        foreach (\array_slice($ids, 1) as $id) {
            $analyses->get(['id' => $id]);
        }

        Assert::same($again, $first);
        Assert::notSame($analyses->get(['id' => $ids[0]]), $first);
    }

    public function testUnknownIdFails(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('No event with ID `missing`');

        (new Trap())->get(ProfileSummary::class)->call(['id' => 'missing']);
    }

    public function testOtherEventFails(): never
    {
        $trap = new Trap();
        $record = $trap->add(Trap::dump('not a profile'));

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage(\sprintf(
                'Event `%s` is a `var-dump` event, not a profile. Call `list_events` with `type: profiler` for the profiles.',
                $record->event->uuid,
            ));

        $trap->get(ProfileSummary::class)->call(['id' => $record->event->uuid]);
    }

    #[DataSet([[]], 'missing')]
    #[DataSet([['id' => '']], 'empty')]
    #[DataSet([['id' => 5]], 'not a string')]
    public function testInvalidIdFails(array $arguments): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('`id` must be a non-empty string.');

        (new Trap())->get(ProfileSummary::class)->call($arguments);
    }

    public function testIsReadOnly(): void
    {
        $tool = (new Trap())->get(ProfileSummary::class);

        Assert::same($tool->name(), 'profile_summary');
        Assert::same($tool->annotations(), ['readOnlyHint' => true, 'openWorldHint' => false]);
        Assert::same($tool->inputSchema()['required'], ['id']);
    }

    public function testDescribesItselfAsTheStartOfAnAnalysis(): void
    {
        $tool = (new Trap())->get(ProfileSummary::class);

        Assert::same($tool->title(), 'Profile summary');
        Assert::string($tool->description())
            ->contains('the 10 functions with the highest exclusive cost')
            ->contains('Go on with `profile_top` and `profile_calls`.');
    }
}
