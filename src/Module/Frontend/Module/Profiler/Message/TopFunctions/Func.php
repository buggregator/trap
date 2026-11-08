<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler\Message\TopFunctions;

use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Peaks;

/**
 * Metrics of a function summed over all its calls.
 *
 * Inclusive metrics (`wt`, `cpu`, ...) include the callees' cost,
 * exclusive ones (`excl_wt`, `excl_cpu`, ...) are the function's own cost.
 *
 * @internal
 */
final class Func implements \JsonSerializable
{
    /** @var array{ct: int, wt: int, cpu: int, mu: int, pmu: int} */
    private array $inclusive = ['ct' => 0, 'wt' => 0, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];

    /** @var array{ct: int, wt: int, cpu: int, mu: int, pmu: int} */
    private array $exclusive = ['ct' => 0, 'wt' => 0, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];

    /** @var array<string, int|float|string>|null */
    private ?array $serialized = null;

    public function __construct(
        public readonly string $function,
        private readonly Peaks $totals,
    ) {}

    /**
     * @param list<Cost> $children Costs of the calls made from this call.
     * @param bool $recursive The call is nested in another call of the same function,
     *        so its cost is already a part of the outer call's inclusive cost.
     */
    public function addCall(Cost $cost, array $children, bool $recursive): void
    {
        $this->serialized = null;
        $own = self::metrics($cost);
        foreach ($children as $child) {
            $child = self::metrics($child);
            $own['wt'] -= $child['wt'];
            $own['cpu'] -= $child['cpu'];
            $own['mu'] -= $child['mu'];
            $own['pmu'] -= $child['pmu'];
        }

        foreach ($own as $metric => $value) {
            $this->exclusive[$metric] += $value;
        }

        $this->inclusive['ct'] += $cost->ct;
        if (!$recursive) {
            $this->inclusive['wt'] += $cost->wt;
            $this->inclusive['cpu'] += $cost->cpu;
            $this->inclusive['mu'] += $cost->mu;
            $this->inclusive['pmu'] += $cost->pmu;
        }
    }

    /**
     * Value by a key of the serialized form: `wt`, `excl_wt`, `p_wt`, `p_excl_wt`, etc.
     */
    public function get(string $key): int|float
    {
        $value = $this->jsonSerialize()[$key] ?? 0;
        return \is_string($value) ? 0 : $value;
    }

    /**
     * @return array<string, int|float|string>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        if ($this->serialized !== null) {
            return $this->serialized;
        }

        $totals = self::metrics($this->totals->toCost());
        $result = ['function' => $this->function];
        foreach ($this->inclusive as $metric => $inclusive) {
            // Freed memory and timer jitter make a negative own cost possible
            $exclusive = \max(0, $this->exclusive[$metric]);
            $total = $totals[$metric];

            $result[$metric] = $inclusive;
            $result['excl_' . $metric] = $exclusive;
            $result['p_' . $metric] = $total > 0 ? \round((float) $inclusive / (float) $total * 100.0, 3) : 0;
            $result['p_excl_' . $metric] = $total > 0 ? \round((float) $exclusive / (float) $total * 100.0, 3) : 0;
        }

        return $this->serialized = $result;
    }

    /**
     * @return array{ct: int, wt: int, cpu: int, mu: int, pmu: int}
     */
    private static function metrics(Cost $cost): array
    {
        return ['ct' => $cost->ct, 'wt' => $cost->wt, 'cpu' => $cost->cpu, 'mu' => $cost->mu, 'pmu' => $cost->pmu];
    }
}
