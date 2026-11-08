<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Struct;

/**
 * @internal
 */
final class Cost implements \JsonSerializable
{
    public float $p_cpu = 0;
    public float $p_ct = 0;
    public float $p_mu = 0;
    public float $p_pmu = 0;
    public float $p_wt = 0;

    /** @var int<min, max> */
    public int $d_cpu = 0;

    /** @var int<min, max> */
    public int $d_ct = 0;

    /** @var int<min, max> */
    public int $d_mu = 0;

    /** @var int<min, max> */
    public int $d_pmu = 0;

    /** @var int<min, max> */
    public int $d_wt = 0;

    /**
     * @param int<0, max> $ct
     * @param int<0, max> $wt
     * @param int<0, max> $cpu
     * @param int<0, max> $mu
     * @param int<0, max> $pmu
     */
    public function __construct(
        public readonly int $ct,
        public readonly int $wt,
        public readonly int $cpu,
        public readonly int $mu,
        public readonly int $pmu,
    ) {}

    /**
     * @param array{
     *     ct: int<0, max>,
     *     wt: int<0, max>,
     *     cpu: int<0, max>,
     *     mu: int<0, max>,
     *     pmu: int<0, max>,
     *     p_ct?: float,
     *     p_wt?: float,
     *     p_cpu?: float,
     *     p_mu?: float,
     *     p_pmu?: float,
     *     d_ct?: int<min, max>,
     *     d_wt?: int<min, max>,
     *     d_cpu?: int<min, max>,
     *     d_mu?: int<min, max>,
     *     d_pmu?: int<min, max>
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
        $self->p_ct = $data['p_ct'] ?? 0;
        $self->p_wt = $data['p_wt'] ?? 0;
        $self->p_cpu = $data['p_cpu'] ?? 0;
        $self->p_mu = $data['p_mu'] ?? 0;
        $self->p_pmu = $data['p_pmu'] ?? 0;
        $self->d_ct = $data['d_ct'] ?? 0;
        $self->d_wt = $data['d_wt'] ?? 0;
        $self->d_cpu = $data['d_cpu'] ?? 0;
        $self->d_mu = $data['d_mu'] ?? 0;
        $self->d_pmu = $data['d_pmu'] ?? 0;

        return $self;
    }

    /**
     * Sets the `p_*` metrics: shares of the peaks, %.
     */
    public function setPercentsOf(Peaks $peaks): void
    {
        $this->p_ct = self::percent($this->ct, $peaks->ct);
        $this->p_wt = self::percent($this->wt, $peaks->wt);
        $this->p_cpu = self::percent($this->cpu, $peaks->cpu);
        $this->p_mu = self::percent($this->mu, $peaks->mu);
        $this->p_pmu = self::percent($this->pmu, $peaks->pmu);
    }

    /**
     * Sets the `d_*` metrics: differences from the parent's cost.
     */
    public function setDeltasTo(self $parent): void
    {
        $this->d_ct = $this->ct - $parent->ct;
        $this->d_wt = $this->wt - $parent->wt;
        $this->d_cpu = $this->cpu - $parent->cpu;
        $this->d_mu = $this->mu - $parent->mu;
        $this->d_pmu = $this->pmu - $parent->pmu;
    }

    private static function percent(int $value, int $total): float
    {
        return $total > 0 ? \round((float) $value / (float) $total * 100.0, 3) : 0.0;
    }

    /**
     * @return array{
     *     ct: int<0, max>,
     *     wt: int<0, max>,
     *     cpu: int<0, max>,
     *     mu: int<0, max>,
     *     pmu: int<0, max>,
     *     p_ct: float,
     *     p_wt: float,
     *     p_cpu: float,
     *     p_mu: float,
     *     p_pmu: float,
     *     d_ct: int<min, max>,
     *     d_wt: int<min, max>,
     *     d_cpu: int<min, max>,
     *     d_mu: int<min, max>,
     *     d_pmu: int<min, max>
     * }
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'ct' => $this->ct,
            'wt' => $this->wt,
            'cpu' => $this->cpu,
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
