<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Http\OtlpTrap;

/**
 * An export request is bigger than a {@see TracesDecoder} accepts in one request.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Http
 */
final class LimitExceededException extends \InvalidArgumentException
{
    /**
     * @param int<1, max> $limit
     */
    public static function spans(int $limit): self
    {
        return new self(\sprintf('Too many spans in one request, the limit is %d.', $limit));
    }

    /**
     * @param int<1, max> $limit
     * @param string $records `log records`, `data points`.
     */
    public static function records(int $limit, string $records): self
    {
        return new self(\sprintf('Too many %s in one request, the limit is %d.', $records, $limit));
    }
}
