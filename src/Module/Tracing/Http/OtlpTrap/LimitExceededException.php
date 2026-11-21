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
     */
    public static function elements(int $limit, bool $extension = true): self
    {
        return new self(\sprintf(
            'Too many elements (records, attributes, events, links, exemplars, values) in one request, the limit is %d.%s',
            $limit,
            $extension ? '' : ' Without ext-protobuf the limit is low: install it, use http/json, or export smaller batches.',
        ));
    }

    /**
     * @param int<1, max> $limit
     */
    public static function text(int $limit, bool $extension = true): self
    {
        return new self(\sprintf(
            'Too much text (strings and bytes, as JSON) in one request, the limit is %d bytes.%s',
            $limit,
            $extension ? '' : ' Without ext-protobuf the limit is low: install it, use http/json, or export smaller batches.',
        ));
    }

    /**
     * @param int<1, max> $limit
     */
    public static function containers(int $limit): self
    {
        return new self(\sprintf('Too many JSON objects and arrays in one request, the limit is %d.', $limit));
    }

    /**
     * @param int<1, max> $limit
     */
    public static function fields(int $limit): self
    {
        return new self(\sprintf('Too many protobuf fields in one request, the limit is %d.', $limit));
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
