<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\FilesObserver\Converter;

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

    /** @var int<0, max> */
    public int $d_cpu = 0;

    /** @var int<0, max> */
    public int $d_ct = 0;

    /** @var int<0, max> */
    public int $d_mu = 0;

    /** @var int<0, max> */
    public int $d_pmu = 0;

    /** @var int<0, max> */
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
     *     p_ct?: float<0, 100>,
     *     p_wt?: float<0, 100>,
     *     p_cpu?: float<0, 100>,
     *     p_mu?: float<0, 100>,
     *     p_pmu?: float<0, 100>,
     *     d_ct?: int<0, max>,
     *     d_wt?: int<0, max>,
     *     d_cpu?: int<0, max>,
     *     d_mu?: int<0, max>,
     *     d_pmu?: int<0, max>
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
        \array_key_exists('p_ct', $data) and $self->p_ct = $data['p_ct'];
        \array_key_exists('p_wt', $data) and $self->p_wt = $data['p_wt'];
        \array_key_exists('p_cpu', $data) and $self->p_cpu = $data['p_cpu'];
        \array_key_exists('p_mu', $data) and $self->p_mu = $data['p_mu'];
        \array_key_exists('p_pmu', $data) and $self->p_pmu = $data['p_pmu'];
        \array_key_exists('d_ct', $data) and $self->d_ct = $data['d_ct'];
        \array_key_exists('d_wt', $data) and $self->d_wt = $data['d_wt'];
        \array_key_exists('d_cpu', $data) and $self->d_cpu = $data['d_cpu'];
        \array_key_exists('d_mu', $data) and $self->d_mu = $data['d_mu'];
        \array_key_exists('d_pmu', $data) and $self->d_pmu = $data['d_pmu'];

        return $self;
    }

    /**
     * @return array{
     *     ct: int<0, max>,
     *     wt: int<0, max>,
     *     cpu: int<0, max>,
     *     mu: int<0, max>,
     *     pmu: int<0, max>,
     *     p_ct: float<0, 100>,
     *     p_wt: float<0, 100>,
     *     p_cpu: float<0, 100>,
     *     p_mu: float<0, 100>,
     *     p_pmu: float<0, 100>,
     *     d_ct: int<0, max>,
     *     d_wt: int<0, max>,
     *     d_cpu: int<0, max>,
     *     d_mu: int<0, max>,
     *     d_pmu: int<0, max>
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
