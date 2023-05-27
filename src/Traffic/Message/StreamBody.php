<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Message;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;

trait StreamBody
{
    private ?StreamInterface $stream = null;

    public function getBody(): StreamInterface
    {
        if (null === $this->stream) {
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
