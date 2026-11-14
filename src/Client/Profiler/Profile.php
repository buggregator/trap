<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler;

use Buggregator\Trap\Client\Profiler\Internal\Recorded;

/**
 * A recorded profile, as destinations get it and {@see Session::stop()} returns it.
 *
 * @api
 */
final class Profile
{
    /**
     * @internal
     */
    public function __construct(
        private readonly Recorded $recorded,
        private readonly Metadata $metadata,
    ) {}

    /**
     * Formats the profile can be encoded in, the native one first.
     *
     * @return non-empty-list<Format>
     */
    public function formats(): array
    {
        return $this->recorded->formats();
    }

    /**
     * @throws \InvalidArgumentException When the profiler can't produce the format.
     */
    public function encode(Format $format): string
    {
        return $this->recorded->encode($format, $this->metadata);
    }

    /**
     * @internal The document in chunks, for the destinations that write it on the go.
     *
     * @return array{int<0, max>, iterable<string>} The size of the document and its chunks.
     *
     * @throws \InvalidArgumentException When the profiler can't produce the format.
     */
    public function stream(Format $format): array
    {
        return $this->recorded->stream($format, $this->metadata);
    }

    public function metadata(): Metadata
    {
        return $this->metadata;
    }
}
