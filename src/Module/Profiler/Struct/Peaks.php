<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Struct;

/**
 * @psalm-type PeaksData = array{
 *     ct: int<0, max>,
 *     wt: int<0, max>,
 *     cpu: int<0, max>,
 *     mu: int<0, max>,
 *     pmu: int<0, max>
 * }
 *
 * @internal
 */
final class Peaks implements \JsonSerializable
{
    /**
     * @param int<0, max> $ct
     * @param int<0, max> $wt
     * @param int<0, max> $cpu
     * @param int<0, max> $mu
     * @param int<0, max> $pmu
     */
    public function __construct(
        public int $ct = 0,
        public int $wt = 0,
        public int $cpu = 0,
        public int $mu = 0,
        public int $pmu = 0,
    ) {}

    /**
     * @param array{
     *     ct: int<0, max>,
     *     wt: int<0, max>,
     *     cpu: int<0, max>,
     *     mu: int<0, max>,
     *     pmu: int<0, max>
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $self = new self(
            $data['ct'],
            $data['wt'],
            $data['cpu'],
            $data['mu'],
            $data['pmu'],
        );

        return $self;
    }

    /**
     * @return array{
     *     ct: int<0, max>,
     *     wt: int<0, max>,
     *     cpu: int<0, max>,
     *     mu: int<0, max>,
     *     pmu: int<0, max>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'ct' => $this->ct,
            'wt' => $this->wt,
            'cpu' => $this->cpu,
            'mu' => $this->mu,
            'pmu' => $this->pmu,
        ];
    }

    public function update(Cost $cost): void
    {
        $this->ct = \max($this->ct, $cost->ct);
        $this->wt = \max($this->wt, $cost->wt);
        $this->cpu = \max($this->cpu, $cost->cpu);
        $this->mu = \max($this->mu, $cost->mu);
        $this->pmu = \max($this->pmu, $cost->pmu);
    }

    public function toCost(): Cost
    {
        return new Cost($this->ct, $this->wt, $this->cpu, $this->mu, $this->pmu);
    }

    public function add(Cost $cost): void
    {
        $this->wt += $cost->wt;
        $this->cpu += $cost->cpu;
        $this->mu = \max($this->mu, $cost->mu);
        $this->pmu = \max($this->pmu, $cost->pmu);
    }
}
