<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\XHProf;

use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Graph;
use Buggregator\Trap\Module\Profiler\Struct\Peaks;
use Buggregator\Trap\Module\Profiler\Struct\Profile;

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
        [$graph, $peaks] = $this->dataToPayload($calls);
        return new Profile(
            date: $date,
            metadata: $metadata,
            tags: $tags,
            calls: $graph,
            peaks: $peaks,
        );
    }

    /**
     * @param non-empty-string $id
     *
     * @return non-empty-string
     */
    private static function functionName(string $id): string
    {
        /** @var non-empty-string */
        return \preg_replace('/@\d+$/', '', $id);
    }

    /**
     * @param RawData $data
     * @return array{Graph, Peaks}
     */
    private function dataToPayload(array $data): array
    {
        $peaks = new Peaks();
        $graph = new Graph();

        // Normalize root
        if (!\array_key_exists('main()', $data) && \array_key_exists('value', $data)) {
            $data['main()'] = $data['value'];
        }
        unset($data['value']);

        foreach ($data as $key => $value) {
            [$callerId, $calleeId] = \explode('==>', $key, 2) + [1 => ''];
            if ($calleeId === '') {
                [$callerId, $calleeId] = [null, $callerId];
            }
            $callerId === '' and $callerId = null;
            $calleeId === '' and throw new \RuntimeException('Invalid XHProf format.');

            $edge = new Edge(
                caller: $callerId === null ? null : self::functionName($callerId),
                callee: self::functionName($calleeId),
                cost: Cost::fromArray($value),
                calleeId: $calleeId,
                callerId: $callerId,
            );

            $peaks->update($edge->cost);
            $graph->add($edge);
        }

        // Add synthetic roots for callers that are never called
        $lostParents = [];
        foreach ($graph as $edge) {
            $edge->callerId === null || $graph->callers($edge->callerId) !== [] or
                ($lostParents[$edge->callerId] ??= new Peaks())->add($edge->cost);
        }
        foreach ($lostParents as $parentId => $peak) {
            $graph->add(new Edge(
                caller: null,
                callee: self::functionName($parentId),
                cost: $peak->toCost(),
                calleeId: $parentId,
            ));
        }
        unset($lostParents);

        // Calc percentages and delta against the caller's total
        $callerCosts = [];
        foreach ($graph as $edge) {
            $cost = $edge->cost;
            $cost->p_cpu = $peaks->cpu > 0 ? \round((float) $cost->cpu / (float) $peaks->cpu * 100.0, 3) : 0;
            $cost->p_ct = $peaks->ct > 0 ? \round((float) $cost->ct / (float) $peaks->ct * 100.0, 3) : 0;
            $cost->p_mu = $peaks->mu > 0 ? \round((float) $cost->mu / (float) $peaks->mu * 100.0, 3) : 0;
            $cost->p_pmu = $peaks->pmu > 0 ? \round((float) $cost->pmu / (float) $peaks->pmu * 100.0, 3) : 0;
            $cost->p_wt = $peaks->wt > 0 ? \round((float) $cost->wt / (float) $peaks->wt * 100.0, 3) : 0;

            if ($edge->callerId !== null) {
                $parentCost = $callerCosts[$edge->callerId] ??= $graph->inclusiveCost($edge->callerId);
                $cost->d_cpu = $cost->cpu - $parentCost->cpu;
                $cost->d_ct = $cost->ct - $parentCost->ct;
                $cost->d_mu = $cost->mu - $parentCost->mu;
                $cost->d_pmu = $cost->pmu - $parentCost->pmu;
                $cost->d_wt = $cost->wt - $parentCost->wt;
            }
        }

        return [$graph, $peaks];
    }
}
