<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Middleware\OtlpTrap;

/**
 * An export request is bigger than a {@see TracesDecoder} accepts in one request.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Handler\Http\Middleware
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
}
