<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\XHProf;

use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Graph;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Support\TimeSlice;

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
     * @param TimeSlice|null $timeSlice Lets other fibers run during a long build.
     */
    public function createProfile(
        \DateTimeInterface $date = new \DateTimeImmutable(),
        array $metadata = [],
        array $tags = [],
        array $calls = [],
        ?TimeSlice $timeSlice = null,
    ): Profile {
        return Profile::fromGraph($this->buildGraph($calls, $timeSlice), $date, $metadata, $tags);
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
     */
    private function buildGraph(array $data, ?TimeSlice $timeSlice): Graph
    {
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

            $graph->add($edge);
            $timeSlice?->tick();
        }

        return $graph;
    }
}
