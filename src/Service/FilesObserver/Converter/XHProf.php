<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\FilesObserver\Converter;

use Buggregator\Trap\Config\Server\Files\XHProf as XHProfConfig;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Proto\Frame\Profiler as ProfilerFrame;
use Buggregator\Trap\Service\FilesObserver\FileInfo;
use Buggregator\Trap\Service\FilesObserver\FrameConverter as FileFilterInterface;

/**
 * @psalm-type RawData = array<non-empty-string, array{
 *      ct: int<0, max>,
 *      wt: int<0, max>,
 *      cpu: int<0, max>,
 *      mu: int<0, max>,
 *      pmu: int<0, max>
 *  }>
 *
 * @psalm-import-type Metadata from \Buggregator\Trap\Proto\Frame\Profiler\Payload
 * @psalm-import-type Calls from \Buggregator\Trap\Proto\Frame\Profiler\Payload
 *
 * @internal
 */
final class XHProf implements FileFilterInterface
{
    public function __construct(
        private readonly Logger $logger,
        private readonly XHProfConfig $config,
    ) {}

    public function validate(FileInfo $file): bool
    {
        return $file->getExtension() === 'xhprof';
    }

    /**
     * @return \Traversable<int, ProfilerFrame>
     */
    public function convert(FileInfo $file): \Traversable
    {
        try {
            /** @var Metadata $metadata */
            $metadata = [
                'date' => $file->mtime,
                'hostname' => \explode('.', $file->getName(), 2)[0],
                'filename' => $file->getName(),
            ];

            yield new ProfilerFrame(
                ProfilerFrame\Payload::new(
                    type: ProfilerFrame\Type::XHProf,
                    metadata: $metadata,
                    callsProvider: function () use ($file): array {
                        $content = \file_get_contents($file->path);
                        /** @var RawData $data */
                        $data = \unserialize($content, ['allowed_classes' => false]);
                        return $this->dataToPayload($data);
                    },
                ),
            );
        } catch (\Throwable $e) {
            $this->logger->exception($e);
        }
    }

    /**
     * @param RawData $data
     * @return Calls
     */
    private function dataToPayload(array $data): array
    {
        $peaks = [
            'cpu' => 0,
            'ct' => 0,
            'mu' => 0,
            'pmu' => 0,
            'wt' => 0,
        ];

        /** @var Tree<Edge> $tree */
        $tree = new Tree();

        foreach ($data as $key => $value) {
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

            $peaks['cpu'] = \max($peaks['cpu'], $edge->cost->cpu);
            $peaks['ct'] = \max($peaks['ct'], $edge->cost->ct);
            $peaks['mu'] = \max($peaks['mu'], $edge->cost->mu);
            $peaks['pmu'] = \max($peaks['pmu'], $edge->cost->pmu);
            $peaks['wt'] = \max($peaks['wt'], $edge->cost->wt);

            $tree->addItem($edge, $edge->callee, $edge->caller);
        }

        /**
         * Calc percentages and delta
         * @var Branch<Edge> $branch Needed for IDE
         */
        foreach ($tree->getIterator() as $branch) {
            $cost = $branch->item->cost;
            $cost->p_cpu = $peaks['cpu'] > 0 ? \round($cost->cpu / $peaks['cpu'] * 100, 3) : 0;
            $cost->p_ct = $peaks['ct'] > 0 ? \round($cost->ct / $peaks['ct'] * 100, 3) : 0;
            $cost->p_mu = $peaks['mu'] > 0 ? \round($cost->mu / $peaks['mu'] * 100, 3) : 0;
            $cost->p_pmu = $peaks['pmu'] > 0 ? \round($cost->pmu / $peaks['pmu'] * 100, 3) : 0;
            $cost->p_wt = $peaks['wt'] > 0 ? \round($cost->wt / $peaks['wt'] * 100, 3) : 0;

            if ($branch->parent !== null) {
                $parentCost = $branch->parent->item->cost;
                $cost->d_cpu = $cost->cpu - $parentCost->cpu;
                $cost->d_ct = $cost->ct - $parentCost->ct;
                $cost->d_mu = $cost->mu - $parentCost->mu;
                $cost->d_pmu = $cost->pmu - $parentCost->pmu;
                $cost->d_wt = $cost->wt - $parentCost->wt;
            }
        }

        return [
            'edges' => \iterator_to_array(match ($this->config->algorithm) {
                // Deep-first
                0 => $tree->getItemsSortedV0(null),
                // Deep-first with sorting by WT
                1 => $tree->getItemsSortedV0(
                    static fn(Branch $a, Branch $b): int => $b->item->cost->wt <=> $a->item->cost->wt,
                ),
                // Level-by-level
                2 => $tree->getItemsSortedV1(null),
                // Level-by-level with sorting by WT
                3 => $tree->getItemsSortedV1(
                    static fn(Branch $a, Branch $b): int => $b->item->cost->wt <=> $a->item->cost->wt,
                ),
                default => throw new \LogicException('Unknown XHProf sorting algorithm.'),
            }),
            'peaks' => $peaks,
        ];
    }
}
