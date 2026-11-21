<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Trace;

/**
 * Why a trace can't be read, or can't be read from the place asked.
 *
 * @internal
 */
final class TraceError extends \RuntimeException
{
    public const NOT_FORMAT_1 = 'not_format_1';
    public const TOO_LARGE = 'too_large';
    public const BAD_OFFSET = 'bad_offset';

    /**
     * @param self::* $reason
     */
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The file is a trace in another format (0 is human-readable, 2 is HTML, 3 and 4 are flame graphs)
     * or no trace at all.
     */
    public static function notFormat1(): self
    {
        return new self(
            self::NOT_FORMAT_1,
            'Not an Xdebug trace in the computer-readable format: set xdebug.trace_format=1.',
        );
    }

    public static function tooLarge(int $limit): self
    {
        return new self(self::TOO_LARGE, "The trace is larger than the limit of $limit bytes.");
    }

    public static function badOffset(): self
    {
        return new self(self::BAD_OFFSET, 'The offset is not the start of a call.');
    }
}
