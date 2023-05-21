<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto\Server;

use Buggregator\Client\Proto\Server\Version\PayloadDecoder;
use RuntimeException;

final class Decoder
{
    /**
     * @param PayloadDecoder[] $payloadDecoders
     */
    public function __construct(
        private readonly array $payloadDecoders,
    ) {
        if (\count($payloadDecoders) === 0) {
            throw new RuntimeException('Payload decoders must be not empty.');
        }

        foreach ($payloadDecoders as $payloadDecoder) {
            \assert($payloadDecoder instanceof PayloadDecoder);
        }
    }

    public function decode(string $rawData): Request
    {
        foreach ($this->payloadDecoders as $payloadDecoder) {
            if ($payloadDecoder->isSupport($rawData)) {
                return $payloadDecoder->decode($rawData);
            }
        }

        throw new RuntimeException('Unsupported payload.');
    }
}
