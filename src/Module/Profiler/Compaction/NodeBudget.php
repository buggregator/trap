<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Compaction;

use Buggregator\Trap\Support\MemoryLimit;

/**
 * Number of call tree nodes a profile build can afford in the memory left to the process.
 *
 * The budget is `memory_limit` less the memory in use, less a tenth of the limit and {@see self::RESERVE}
 * as headroom, divided by {@see self::BYTES_PER_NODE}, within {@see self::MIN_NODES} and {@see self::MAX_NODES}.
 * Measured on Xdebug and Excimer profiles of 30 to 200 thousand nodes, PHP 8.4.
 *
 * @internal
 */
final class NodeBudget
{
    /**
     * Bytes per node, with a fifth for safety. A build peaks at about 1.8 KB per node: the raw tree, twice larger,
     * and its compactions, then the tree, the call graph and their percentages. A kept profile holds about 1.2 KB
     * per node, and the UI makes 0.6 KB of flame chart spans per node on top while it sends them.
     */
    public const BYTES_PER_NODE = 2200;

    /**
     * Bytes a build takes whatever its size: the rounding sums of the caller-callee pairs, the parsing.
     */
    public const RESERVE = 16 * 1024 * 1024;

    /**
     * Nodes a profile always gets, whatever the memory: about 20 MB, enough for the hot paths.
     */
    public const MIN_NODES = 10_000;

    /**
     * Nodes a profile gets at most, with no memory limit too: about 450 MB to build, and a flame chart
     * of 60 MB of JSON, past what a browser draws smoothly.
     */
    public const MAX_NODES = 250_000;

    /** Share of the limit kept free for the rest of the server. */
    private const HEADROOM = 0.1;

    /**
     * @param string|null $limit `memory_limit` value; the current one by default.
     * @param int<0, max>|null $usage Bytes in use; the current usage by default.
     *
     * @return int<1, max>
     */
    public static function nodes(?string $limit = null, ?int $usage = null): int
    {
        try {
            $bytes = $limit === null ? MemoryLimit::current() : MemoryLimit::parse($limit);
        } catch (\InvalidArgumentException) {
            // A limit we can't read may be low
            return self::MIN_NODES;
        }
        if ($bytes === null) {
            return self::MAX_NODES;
        }

        $free = $bytes - ($usage ?? \memory_get_usage()) - (int) ((float) $bytes * self::HEADROOM) - self::RESERVE;
        return \max(self::MIN_NODES, \min(self::MAX_NODES, \intdiv(\max(0, $free), self::BYTES_PER_NODE)));
    }
}
