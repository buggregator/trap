<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\FilesObserver\Filter;

use Buggregator\Trap\Proto\Frame\Profiler as ProfilerFrame;
use Buggregator\Trap\Service\FilesObserver\FileInfo;
use Buggregator\Trap\Service\FilesObserver\FrameConverter as FileFilterInterface;

/**
 * @internal
 */
final class XHProf implements FileFilterInterface
{
    public function validate(FileInfo $file): bool
    {
        return $file->getExtension() === 'xhprof';
    }

    public function convert(FileInfo $file): \Traversable
    {
        try {
            // todo read in a stream
            $content = \file_get_contents($file->path);

            $data = \unserialize($content, ['allowed_classes' => false]);

            $payload = $this->dataToPayload($data);
            $payload['date'] = $file->mtime;
            $payload['hostname'] = \explode('.', $file->getName(), 2)[0];

            yield new ProfilerFrame\Payload(
                $payload,
            );
        } catch (\Throwable $e) {
            // todo log
            var_dump($e->getMessage());
        }
    }

    private function dataToPayload(array $data): array
    {
        /** @var array<string, array<string, int>> $data */
        $peaks = [
            'cpu' => 0,
            'ct' => 0,
            'mu' => 0,
            'pmu' => 0,
            'wt' => 0,
        ];

        $edges = [];
        /** @var array<string, array<string, int>> $parents */
        $parents = [];
        $i = 0;
        foreach ($data as $key => $value) {
            [$caller, $callee] = \explode('==>', $key, 2) + [1 => null];
            if ($callee === null) {
                [$caller, $callee] = [null, $caller];
            }

            $edge = [
                'callee' => $callee,
                'caller' => $caller,
                'cost' => [
                    'cpu' => (int)$value['cpu'],
                    'ct' => (int)$value['ct'],
                    'mu' => (int)$value['mu'],
                    'pmu' => (int)$value['pmu'],
                    'wt' => (int)$value['wt'],
                ],
            ];

            $edges['e' . ++$i] = &$edge;
            $parents[$callee] = &$edge['cost'];

            $peaks['cpu'] = \max($peaks['cpu'], $edge['cost']['cpu']);
            $peaks['ct'] = \max($peaks['ct'], $edge['cost']['ct']);
            $peaks['mu'] = \max($peaks['mu'], $edge['cost']['mu']);
            $peaks['pmu'] = \max($peaks['pmu'], $edge['cost']['pmu']);
            $peaks['wt'] = \max($peaks['wt'], $edge['cost']['wt']);

            unset($edge);
        }

        $edges = \array_reverse($edges);

        // calc percentages and delta
        foreach ($edges as &$value) {
            $cost = &$value['cost'];
            $cost['p_cpu'] = $peaks['cpu'] > 0 ? \round($cost['cpu'] / $peaks['cpu'] * 100, 2) : 0;
            $cost['p_ct'] = $peaks['ct'] > 0 ? \round($cost['ct'] / $peaks['ct'] * 100, 2) : 0;
            $cost['p_mu'] = $peaks['mu'] > 0 ? \round($cost['mu'] / $peaks['mu'] * 100, 2) : 0;
            $cost['p_pmu'] = $peaks['pmu'] > 0 ? \round($cost['pmu'] / $peaks['pmu'] * 100, 2) : 0;
            $cost['p_wt'] = $peaks['wt'] > 0 ? \round($cost['wt'] / $peaks['wt'] * 100, 2) : 0;

            $caller = $value['caller'];
            if ($caller !== null) {
                $cost['d_cpu'] = $cost['cpu'] - ($parents[$caller]['cpu'] ?? 0);
                $cost['d_ct'] = $cost['ct'] - ($parents[$caller]['ct'] ?? 0);
                $cost['d_mu'] = $cost['mu'] - ($parents[$caller]['mu'] ?? 0);
                $cost['d_pmu'] = $cost['pmu'] - ($parents[$caller]['pmu'] ?? 0);
                $cost['d_wt'] = $cost['wt'] - ($parents[$caller]['wt'] ?? 0);
            }
            unset($value, $cost);
        }
        unset($parents);

        return [
            'edges' => $edges,
            'peaks' => $peaks,
        ];
    }
}
