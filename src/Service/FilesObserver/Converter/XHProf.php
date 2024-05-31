<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\FilesObserver\Converter;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Proto\Frame\Profiler as ProfilerFrame;
use Buggregator\Trap\Service\FilesObserver\FileInfo;
use Buggregator\Trap\Service\FilesObserver\FrameConverter as FileFilterInterface;

/**
 * @internal
 */
final class XHProf implements FileFilterInterface
{
    public function __construct(
        private readonly Logger $logger,
    ) {}

    public function validate(FileInfo $file): bool
    {
        return $file->getExtension() === 'xhprof';
    }

    public function convert(FileInfo $file): \Traversable
    {
        try {
            $metadata = [
                'date' => $file->mtime,
                'hostname' => \explode('.', $file->getName(), 2)[0],
                'filename' => $file->getName(),
            ];

            /** @psalm-suppress MixedArgumentTypeCoercion */
            yield new ProfilerFrame(
                ProfilerFrame\Payload::new(
                    type: ProfilerFrame\Type::XHProf,
                    metadata: $metadata,
                    callsProvider: function () use ($file): array {
                        $content = \file_get_contents($file->path);
                        $data = \unserialize($content, ['allowed_classes' => false]);
                        return $this->dataToPayload($data);
                    },
                ),
            );
        } catch (\Throwable $e) {
            $this->logger->exception($e);
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
        /** @var array<string, list<array>> $parents items with unknown caller */
        $callerLess = [];
        $i = 0;
        \uasort($data, static function (array $a, array $b) {
            return $b['wt'] <=> $a['wt'];
        });
        // $data = \array_reverse($data, true);
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

            // if (++$j > 10) {
            //     print_r(\array_keys($parents));
            //     print_r(\array_keys($edges));
            //     die;
            // }

            if ($caller !== null && !\array_key_exists($caller, $parents) && $caller !== $callee) {
                $callerLess[$caller][] = &$edge;
                // echo "CALLER: $caller\n";
                // echo "CALLEE: $callee\n";
            } else {
                // echo "CALLER: $callee\n";
                $parents[$callee] = &$edge['cost'];
                $edges['e' . ++$i] = &$edge;
                if (\array_key_exists($callee, $callerLess)) {
                    foreach ($callerLess[$callee] as $item) {
                        $edges['a' . ++$i] = &$item;
                        $parents[$item['callee']] = &$item['cost'];
                        unset($item);
                    }
                    unset($callerLess[$callee]);
                }
            }

            $peaks['cpu'] = \max($peaks['cpu'], $edge['cost']['cpu']);
            $peaks['ct'] = \max($peaks['ct'], $edge['cost']['ct']);
            $peaks['mu'] = \max($peaks['mu'], $edge['cost']['mu']);
            $peaks['pmu'] = \max($peaks['pmu'], $edge['cost']['pmu']);
            $peaks['wt'] = \max($peaks['wt'], $edge['cost']['wt']);

            unset($edge);
        }

        // Merge callerLess items
        while ($callerLess !== []) {
            $merged = 0;
            foreach ($callerLess as $caller => $items) {
                if (\array_key_exists($caller, $parents)) {
                    foreach ($items as &$item) {
                        $edges['c' . ++$i] = &$item;
                        $parents[$item['callee']] = &$item['cost'];
                        unset($item);
                    }
                    ++$merged;
                    unset($callerLess[$caller]);
                }
            }

            // Just merge all as is
            if ($merged === 0) {
                foreach ($callerLess as $items) {
                    foreach ($items as &$item) {
                        $edges['f' . ++$i] = &$item;
                        $parents[$item['callee']] = &$item['cost'];
                        unset($item);
                    }
                }

                $callerLess = [];
            }
        }

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
