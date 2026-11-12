<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Struct;

use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Graph;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\Struct\Tree;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * The contract every profiler format relies on: a parser gives raw costs, the factories derive the rest.
 */
#[Covers(Profile::class)]
#[Covers(Graph::class)]
#[Covers(Edge::class)]
#[Covers(Cost::class)]
#[Test]
final class ProfileTest
{
    public function testFromTreeMarksRecursionLevels(): void
    {
        $profile = Profile::fromTree(self::tree([
            ['n1', null, 'main', 100],
            ['n2', 'n1', 'f', 60],
            ['n3', 'n2', 'g', 40],
            ['n4', 'n3', 'f', 30],
            ['n5', 'n4', 'f', 10],
        ]));

        $ids = \array_map(static fn($b): string => $b->item->calleeId, $profile->tree()->all);
        Assert::same($ids, ['n1' => 'main', 'n2' => 'f', 'n3' => 'g', 'n4' => 'f@1', 'n5' => 'f@2']);
        Assert::same($profile->tree()->all['n5']->item->callerId, 'f@1');
        Assert::same($profile->tree()->all['n5']->item->isRecursive(), true);
        Assert::same($profile->tree()->all['n2']->item->isRecursive(), false);
        Assert::same(\count($profile->calls), 5);
    }

    public function testFromTreeSetsPercentsAndDeltasOfGraphAndTree(): void
    {
        $profile = Profile::fromTree(self::tree([
            ['n1', null, 'main', 100],
            ['n2', 'n1', 'a', 30],
            ['n3', 'n1', 'a', 20],
        ]));

        Assert::same($profile->peaks->wt, 100);
        $a = $profile->calls->callers('a')[0]->cost;
        Assert::same([$a->ct, $a->wt, $a->p_wt, $a->d_wt], [2, 50, 50.0, -50]);
        $node = $profile->tree()->all['n3']->item->cost;
        Assert::same([$node->p_wt, $node->d_wt], [20.0, -80]);
    }

    public function testFromGraphAddsRootsForCallersNeverCalled(): void
    {
        $graph = new Graph();
        $graph->add(new Edge('x', 'c', new Cost(2, 30, 20, 300, 100)));
        $graph->add(new Edge('x', 'b', new Cost(1, 10, 5, 100, 400)));
        $graph->add(new Edge(null, 'main', new Cost(1, 100, 80, 500, 1000)));

        $profile = Profile::fromGraph($graph);

        Assert::same(\array_map(static fn(Edge $e): string => $e->calleeId, $profile->calls->roots()), ['main', 'x']);
        $x = $profile->calls->callers('x')[0]->cost;
        Assert::same([$x->wt, $x->cpu, $x->mu, $x->pmu, $x->p_wt], [40, 25, 300, 400, 40.0]);
    }

    public function testFromGraphTakesPeaksOfRecordedCallsOnly(): void
    {
        $graph = new Graph();
        $graph->add(new Edge('x', 'c', new Cost(1, 30, 0, 0, 0)));
        $graph->add(new Edge('x', 'b', new Cost(1, 10, 0, 0, 0)));

        $profile = Profile::fromGraph($graph);

        Assert::same($profile->peaks->wt, 30);
        Assert::same($profile->calls->callers('b')[0]->cost->d_wt, -30);
    }

    public function testConstructorTakesPeaksOfGivenCallsWhenNoneGiven(): void
    {
        $graph = new Graph();
        $graph->add(new Edge(null, 'main', new Cost(1, 100, 20, 300, 400)));
        $graph->add(new Edge('main', 'f', new Cost(5, 60, 70, 100, 900)));

        $profile = new Profile(calls: $graph);

        Assert::same($profile->peaks->jsonSerialize(), ['ct' => 5, 'wt' => 100, 'cpu' => 70, 'mu' => 300, 'pmu' => 900]);
    }

    public function testJsonSerializeExposesSummaryForFrontend(): void
    {
        $graph = new Graph();
        $graph->add(new Edge(null, 'main', new Cost(1, 100, 0, 0, 0)));
        $graph->add(new Edge('main', 'f', new Cost(1, 60, 0, 0, 0)));
        $profile = new Profile(
            date: new \DateTimeImmutable('@1700000000'),
            metadata: ['app_name' => 'app', 'hostname' => 'host'],
            tags: ['env' => 'dev'],
            calls: $graph,
        );

        $data = $profile->jsonSerialize();

        Assert::same($data['date'], 1700000000);
        Assert::same($data['app_name'], 'app');
        Assert::same($data['hostname'], 'host');
        Assert::same($data['filename'], '');
        Assert::same($data['profile_uuid'], $profile->uuid);
        Assert::same($data['tags'], ['env' => 'dev']);
        Assert::same($data['peaks'], $profile->peaks);
        Assert::same($data['total_edges'], 2);
    }

    public function testFromArrayRestoresSerializedProfile(): void
    {
        $profile = Profile::fromArray([
            'date' => 1700000000,
            'app_name' => 'app',
            'filename' => 'x.php',
            'tags' => ['env' => 'dev'],
            'peaks' => ['ct' => 1, 'wt' => 2, 'cpu' => 3, 'mu' => 4, 'pmu' => 5],
            'total_edges' => 7,
            'profile_uuid' => 'abc-123',
        ]);

        Assert::same($profile->date->getTimestamp(), 1700000000);
        Assert::same($profile->metadata, ['app_name' => 'app', 'filename' => 'x.php']);
        Assert::same($profile->tags, ['env' => 'dev']);
        Assert::same($profile->peaks->jsonSerialize(), ['ct' => 1, 'wt' => 2, 'cpu' => 3, 'mu' => 4, 'pmu' => 5]);
        Assert::same($profile->uuid, 'abc-123');
    }

    #[DataSet([null], 'missing')]
    #[DataSet([''], 'empty')]
    #[DataSet([42], 'not a string')]
    public function testFromArrayGeneratesUuidWhenNoneUsable(mixed $uuid): void
    {
        $data = [
            'date' => 1700000000,
            'tags' => [],
            'peaks' => ['ct' => 0, 'wt' => 0, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ];
        $uuid === null or $data['profile_uuid'] = $uuid;

        $profile = Profile::fromArray($data);

        Assert::same(\strlen($profile->uuid), 36);
        Assert::same($profile->metadata, []);
    }

    public function testCostSurvivesJsonRoundTrip(): void
    {
        $data = [
            'ct' => 3, 'wt' => 120, 'cpu' => 90, 'mu' => 2048, 'pmu' => 4096,
            'p_ct' => 1.5, 'p_wt' => 12.0, 'p_cpu' => 9.0, 'p_mu' => 2.048, 'p_pmu' => 4.096,
            'd_ct' => -1, 'd_wt' => -80, 'd_cpu' => -10, 'd_mu' => 48, 'd_pmu' => -904,
        ];

        $cost = Cost::fromArray($data);

        Assert::same($cost->jsonSerialize(), $data);
    }

    public function testCostFromArrayDefaultsMissingMetricsToZero(): void
    {
        $cost = Cost::fromArray(['ct' => 1, 'wt' => 10]);

        Assert::same($cost->jsonSerialize(), [
            'ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0,
            'p_ct' => 0.0, 'p_wt' => 0.0, 'p_cpu' => 0.0, 'p_mu' => 0.0, 'p_pmu' => 0.0,
            'd_ct' => 0, 'd_wt' => 0, 'd_cpu' => 0, 'd_mu' => 0, 'd_pmu' => 0,
        ]);
    }

    /**
     * @param list<array{non-empty-string, non-empty-string|null, non-empty-string, int<0, max>}> $nodes
     *        ID, parent ID, function name and wall time of each node, parents first.
     *
     * @return Tree<Edge>
     */
    private static function tree(array $nodes): Tree
    {
        /** @var Tree<Edge> $tree */
        $tree = new Tree();
        foreach ($nodes as [$id, $parentId, $name, $wt]) {
            $caller = $parentId === null ? null : $tree->all[$parentId]->item->callee;
            $tree->addItem(new Edge($caller, $name, new Cost(1, $wt, 0, 0, 0)), $id, $parentId);
        }

        return $tree;
    }
}
