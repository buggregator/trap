<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal\Model;

/**
 * Caller to callee pairs with inclusive costs, as XHProf records them.
 *
 * Function ids follow XHProf: a function already on the call path gets the `@N` suffix,
 * where N is how many times it is there (`foo`, `foo@1`, `foo@2`).
 *
 * @internal
 */
final class CallGraph
{
    /** @var array<non-empty-string, int> Pair ids by `caller==>callee` keys, or by the callee alone for the root. */
    private array $keys = [];

    /** @var array<int, int> Microseconds by pair id. */
    private array $wt = [];

    /** @var array<int, int> CPU microseconds by pair id. */
    private array $cpu = [];

    /** @var array<int, int> Memory by pair id. */
    private array $mu = [];

    /** @var array<int, int> Peak memory by pair id. */
    private array $pmu = [];

    /** @var array<int, int> Calls by pair id. */
    private array $ct = [];

    /** @var array<int, int> Samples by pair id. */
    private array $samples = [];

    /**
     * @param list<Metric> $metrics The metrics the profile measured.
     */
    public function __construct(
        public readonly array $metrics,
    ) {}

    /**
     * Adds the costs to the pair, creating it when missing.
     *
     * @param non-empty-string|null $caller
     * @param non-empty-string $callee
     * @param int $wt Microseconds.
     * @param int $cpu Microseconds.
     * @param int $mu Bytes.
     * @param int $pmu Bytes.
     */
    public function add(?string $caller, string $callee, int $wt, int $cpu, int $mu, int $pmu, int $ct, int $samples): void
    {
        $key = $caller === null ? $callee : "{$caller}==>{$callee}";
        $id = $this->keys[$key] ?? null;
        if ($id === null) {
            $id = \count($this->keys);
            $this->keys[$key] = $id;
            $this->wt[$id] = $wt;
            $this->cpu[$id] = $cpu;
            $this->mu[$id] = $mu;
            $this->pmu[$id] = $pmu;
            $this->ct[$id] = $ct;
            $this->samples[$id] = $samples;
            return;
        }

        $this->wt[$id] += $wt;
        $this->cpu[$id] += $cpu;
        $this->mu[$id] += $mu;
        $this->pmu[$id] += $pmu;
        $this->ct[$id] += $ct;
        $this->samples[$id] += $samples;
    }

    /**
     * @return array<non-empty-string, int> Pair ids by `caller==>callee` keys, or by the callee alone for the root,
     *         in the order of adding.
     */
    public function keys(): array
    {
        return $this->keys;
    }

    /**
     * @return array{array<int, int>, array<int, int>, array<int, int>, array<int, int>, array<int, int>, array<int, int>}
     *         Inclusive wt, cpu, mu, pmu, ct and samples, each by pair id.
     */
    public function costs(): array
    {
        return [$this->wt, $this->cpu, $this->mu, $this->pmu, $this->ct, $this->samples];
    }
}
