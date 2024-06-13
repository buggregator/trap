<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\XHProf;

use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Peaks;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\Struct\Tree;

/**
 * @psalm-type RawData = array<non-empty-string, array{
 *      ct: int<0, max>,
 *      wt: int<0, max>,
 *      cpu: int<0, max>,
 *      mu: int<0, max>,
 *      pmu: int<0, max>
 *  }>
 *
 * @psalm-import-type Metadata from Profile
 *
 * @internal
 */
final class ProfileBuilder
{
    /**
     * @param Metadata $metadata
     * @param RawData $calls
     * @param array<non-empty-string, non-empty-string> $tags
     */
    public function createProfile(
        \DateTimeInterface $date = new \DateTimeImmutable(),
        array $metadata = [],
        array $tags = [],
        array $calls = [],
    ): Profile {
        [$tree, $peaks] = $this->dataToPayload($calls);
        return new Profile(
            date: $date,
            metadata: $metadata,
            tags: $tags,
            calls: $tree,
            peaks: $peaks,
        );
    }

    /**
     * @param RawData $data
     * @return array{Tree<Edge>, Peaks}
     */
    private function dataToPayload(array $data): array
    {
        $peaks = new Peaks();

        /** @var Tree<Edge> $tree */
        $tree = new Tree();

        !\array_key_exists('main()', $data) && \array_key_exists('value', $data) and $data['main()'] = $data['value'];
        unset($data['value']);

        foreach (\array_reverse($data, true) as $key => $value) {
            [$caller, $callee] = \explode('==>', $key, 2) + [1 => ''];
            if ($callee === '') {
                [$caller, $callee] = [null, $caller];
            }
            $caller === '' and $caller = null;
            \assert($callee !== '');

            $edge = new Edge(
                caller: $caller,
                callee: $callee,
                cost: Cost::fromArray($value),
            );

            $peaks->update($edge->cost);
            $tree->addItem($edge, $edge->callee, $edge->caller);
        }

        // Add parents for lost children
        $lostParents = [];
        /** @var Branch<Edge> $branch */
        foreach ($tree->iterateLostChildren() as $branch) {
            \assert($branch->item->caller !== null);
            ($lostParents[$branch->item->caller] ??= new Peaks())
                ->add($branch->item->cost);
        }
        /** @var array<non-empty-string, Peaks> $lostParents */
        foreach ($lostParents as $key => $peak) {
            $edge = new Edge(
                caller: null,
                callee: $key,
                cost: $peak->toCost(),
            );
            $tree->addItem($edge, $edge->callee, $edge->caller);
        }
        unset($lostParents);

        /**
         * Calc percentages and delta
         * @var Branch<Edge> $branch Needed for IDE
         */
        foreach ($tree->getIterator() as $branch) {
            $cost = $branch->item->cost;
            $cost->p_cpu = $peaks->cpu > 0 ? \round($cost->cpu / $peaks->cpu * 100, 3) : 0;
            $cost->p_ct = $peaks->ct > 0 ? \round($cost->ct / $peaks->ct * 100, 3) : 0;
            $cost->p_mu = $peaks->mu > 0 ? \round($cost->mu / $peaks->mu * 100, 3) : 0;
            $cost->p_pmu = $peaks->pmu > 0 ? \round($cost->pmu / $peaks->pmu * 100, 3) : 0;
            $cost->p_wt = $peaks->wt > 0 ? \round($cost->wt / $peaks->wt * 100, 3) : 0;

            if ($branch->parent !== null) {
                $parentCost = $branch->parent->item->cost;
                $cost->d_cpu = $cost->cpu - $parentCost->cpu;
                $cost->d_ct = $cost->ct - $parentCost->ct;
                $cost->d_mu = $cost->mu - $parentCost->mu;
                $cost->d_pmu = $cost->pmu - $parentCost->pmu;
                $cost->d_wt = $cost->wt - $parentCost->wt;
            }
        }

        return [$tree, $peaks];

        // return [
        //     'edges' => \iterator_to_array(match ($this->config->algorithm) {
        //         // Deep-first
        //         0 => $tree->getItemsSortedV0(null),
        //         // Deep-first with sorting by WT
        //         1 => $tree->getItemsSortedV0(
        //             static fn(Branch $a, Branch $b): int => $b->item->cost->wt <=> $a->item->cost->wt,
        //         ),
        //         // Level-by-level
        //         2 => $tree->getItemsSortedV1(null),
        //         // Level-by-level with sorting by WT
        //         3 => $tree->getItemsSortedV1(
        //             static fn(Branch $a, Branch $b): int => $b->item->cost->wt <=> $a->item->cost->wt,
        //         ),
        //         default => throw new \LogicException('Unknown XHProf sorting algorithm.'),
        //     }),
        //     'peaks' => $peaks,
        // ];
    }
}
