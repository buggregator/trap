<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Http\Stub;

use Nyholm\Psr7\Stream;

/**
 * A stream that doesn't know its size, as a body streamed in chunks.
 */
final class UnsizedStream extends Stream
{
    public static function of(string $content): self
    {
        $resource = \fopen('php://memory', 'w+b');
        \fwrite($resource, $content);
        \rewind($resource);

        return new self($resource);
    }

    #[\Override]
    public function getSize(): ?int
    {
        return null;
    }
}
