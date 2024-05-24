<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Message;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 */
trait StreamBody
{
    private ?StreamInterface $stream = null;

    public function hasBody(): bool
    {
        return $this->stream !== null;
    }

    public function getBody(): StreamInterface
    {
        if ($this->stream === null) {
            $this->stream = Stream::create('');
        }

        return $this->stream;
    }

    public function withBody(StreamInterface $body): static
    {
        if ($body === $this->stream) {
            return $this;
        }

        $new = clone $this;
        $new->stream = $body;

        return $new;
    }
}
