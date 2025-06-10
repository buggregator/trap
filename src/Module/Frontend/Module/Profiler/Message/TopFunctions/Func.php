<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler\Message\TopFunctions;

use Buggregator\Trap\Module\Profiler\Struct\Edge;

/**
 * @internal
 */
final class Func implements \JsonSerializable
{
    public function __construct(
        public readonly string $function,
        public readonly int $cpu,
        public readonly int $ct,
        public readonly int $wt,
        public readonly int $mu,
        public readonly int $pmu,
        public readonly float $p_ct,
        public readonly float $p_wt,
        public readonly float $p_cpu,
        public readonly float $p_mu,
        public readonly float $p_pmu,
        public readonly int $d_ct,
        public readonly int $d_wt,
        public readonly int $d_cpu,
        public readonly int $d_mu,
        public readonly int $d_pmu,
    ) {}

    public static function fromEdge(Edge $edge): self
    {
        return new self(
            $edge->callee,
            $edge->cost->cpu,
            $edge->cost->ct,
            $edge->cost->wt,
            $edge->cost->mu,
            $edge->cost->pmu,
            $edge->cost->p_ct,
            $edge->cost->p_wt,
            $edge->cost->p_cpu,
            $edge->cost->p_mu,
            $edge->cost->p_pmu,
            $edge->cost->d_ct,
            $edge->cost->d_wt,
            $edge->cost->d_cpu,
            $edge->cost->d_mu,
            $edge->cost->d_pmu,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'function' => $this->function,
            'cpu' => $this->cpu,
            'ct' => $this->ct,
            'wt' => $this->wt,
            'mu' => $this->mu,
            'pmu' => $this->pmu,
            'p_ct' => $this->p_ct,
            'p_wt' => $this->p_wt,
            'p_cpu' => $this->p_cpu,
            'p_mu' => $this->p_mu,
            'p_pmu' => $this->p_pmu,
            'd_ct' => $this->d_ct,
            'd_wt' => $this->d_wt,
            'd_cpu' => $this->d_cpu,
            'd_mu' => $this->d_mu,
            'd_pmu' => $this->d_pmu,
        ];
    }
}
