<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Frontend\Message;

use Buggregator\Trap\Module\Profiler\Frontend\Message\TopFunctions\Func;

/**
 * Functions of two profiles side by side: calls, exclusive wall time and memory of each, and the change.
 *
 * @internal
 */
final class Comparison implements \JsonSerializable
{
    /**
     * The number of the functions shown: those that changed most.
     */
    public const LIMIT = 100;

    /** @var list<array<non-empty-string, string|int>> */
    private readonly array $functions;

    /**
     * @param array<string, Func> $base By function names.
     * @param array<string, Func> $compare By function names.
     */
    public function __construct(array $base, array $compare)
    {
        $rows = $wt = $mu = $names = [];
        foreach (\array_keys($base + $compare) as $name) {
            $row = ['function' => (string) $name];
            foreach (['ct', 'excl_wt', 'excl_mu'] as $metric) {
                $a = isset($base[$name]) ? (int) $base[$name]->get($metric) : 0;
                $b = isset($compare[$name]) ? (int) $compare[$name]->get($metric) : 0;
                $row["base_$metric"] = $a;
                $row["compare_$metric"] = $b;
                $row["diff_$metric"] = $b - $a;
            }

            $rows[] = $row;
            $wt[] = \abs($row['diff_excl_wt']);
            $mu[] = \abs($row['diff_excl_mu']);
            $names[] = (string) $name;
        }

        \array_multisort($wt, \SORT_DESC, $mu, \SORT_DESC, $names, \SORT_ASC, $rows);
        $this->functions = \array_slice($rows, 0, self::LIMIT);
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return ['functions' => $this->functions];
    }
}
