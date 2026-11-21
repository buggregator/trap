<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Config;

use Buggregator\Trap\Server\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\XPath;
use Buggregator\Trap\Support\MemoryLimit;

/**
 * Bounds of the received Xdebug function traces. Traces stay on disk, in a temporary directory of the process.
 *
 * @internal
 */
#[InflectableConfig]
final class Traces
{
    public const DEFAULT_MAX_UPLOAD = 100 << 20;
    public const DEFAULT_MAX_SIZE = 256 << 20;
    public const DEFAULT_KEEP = 20;

    /** Bytes of a trace as sent, gzipped or not, in the size format of `TRAP_HTTP_MAX_INFLATED_SIZE` */
    #[Env('TRAP_XDEBUG_TRACE_MAX_UPLOAD')]
    #[XPath('/trap/xdebugTraces/@maxUpload')]
    public string $maxUpload = '100M';

    /** Uncompressed bytes read of a trace: a longer one is cut there */
    #[Env('TRAP_XDEBUG_TRACE_MAX_SIZE')]
    #[XPath('/trap/xdebugTraces/@maxSize')]
    public string $maxSize = '256M';

    /** Traces kept: one more deletes the oldest. Values below 1 fall back to the default. */
    #[Env('TRAP_XDEBUG_TRACE_KEEP')]
    #[XPath('/trap/xdebugTraces/@keep')]
    public int $keep = self::DEFAULT_KEEP;

    /**
     * @return positive-int
     */
    public function maxUploadBytes(): int
    {
        return self::bytes($this->maxUpload, self::DEFAULT_MAX_UPLOAD);
    }

    /**
     * @return positive-int
     */
    public function maxSizeBytes(): int
    {
        return self::bytes($this->maxSize, self::DEFAULT_MAX_SIZE);
    }

    /**
     * @return positive-int
     */
    public function keepCount(): int
    {
        return $this->keep > 0 ? $this->keep : self::DEFAULT_KEEP;
    }

    /**
     * @param positive-int $default
     *
     * @return positive-int
     */
    private static function bytes(string $value, int $default): int
    {
        try {
            return MemoryLimit::parse($value) ?? $default;
        } catch (\InvalidArgumentException) {
            return $default;
        }
    }
}
