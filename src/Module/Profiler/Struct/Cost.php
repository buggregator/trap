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
     *     cpu?: int<0, max>,
     *     mu?: int<0, max>,
     *     pmu?: int<0, max>,
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
            $data['cpu'] ?? 0,
            $data['mu'] ?? 0,
            $data['pmu'] ?? 0,
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
     * Converts the result of arithmetic to an integer clamped to the integer range: received data may pass it,
     * where integer arithmetic turns into a float and an `(int)` cast wraps around.
     *
     * @param int|float $value A float in the range is truncated, like by an `(int)` cast; NaN is 0.
     */
    public static function saturate(int|float $value): int
    {
        return match (true) {
            \is_int($value) => $value,
            \is_nan($value) => 0,
            $value >= (float) \PHP_INT_MAX => \PHP_INT_MAX,
            $value <= (float) \PHP_INT_MIN => \PHP_INT_MIN,
            default => (int) $value,
        };
    }

    /**
     * Sums values of at least 0, saturated at the integer maximum like {@see self::saturate()}.
     *
     * @param int<0, max> $a
     * @param int<0, max> $b
     *
     * @return int<0, max>
     */
    public static function sum(int $a, int $b): int
    {
        return $a > \PHP_INT_MAX - $b ? \PHP_INT_MAX : $a + $b;
    }

    /**
     * Sets the `p_*` metrics: shares of the peaks, %.
     */
    public function setPercentsOf(Peaks $peaks): void
    {
        // Not a call per metric: this runs for every node and edge of a profile
        $this->p_ct = $peaks->ct > 0 ? \round((float) $this->ct / (float) $peaks->ct * 100.0, 3) : 0.0;
        $this->p_wt = $peaks->wt > 0 ? \round((float) $this->wt / (float) $peaks->wt * 100.0, 3) : 0.0;
        $this->p_cpu = $peaks->cpu > 0 ? \round((float) $this->cpu / (float) $peaks->cpu * 100.0, 3) : 0.0;
        $this->p_mu = $peaks->mu > 0 ? \round((float) $this->mu / (float) $peaks->mu * 100.0, 3) : 0.0;
        $this->p_pmu = $peaks->pmu > 0 ? \round((float) $this->pmu / (float) $peaks->pmu * 100.0, 3) : 0.0;
    }

    /**
     * Sets the `d_*` metrics: differences from the parent's cost.
     */
    public function setDeltasTo(self $parent): void
    {
        $this->d_ct = self::saturate($this->ct - $parent->ct);
        $this->d_wt = self::saturate($this->wt - $parent->wt);
        $this->d_cpu = self::saturate($this->cpu - $parent->cpu);
        $this->d_mu = self::saturate($this->mu - $parent->mu);
        $this->d_pmu = self::saturate($this->pmu - $parent->pmu);
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
