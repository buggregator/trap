<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Server;

use Buggregator\Trap\Proto\Server\Version\PayloadDecoder;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class Decoder
{
    /**
     * @param PayloadDecoder[] $payloadDecoders
     */
    public function __construct(
        private readonly array $payloadDecoders,
    ) {
        if (\count($payloadDecoders) === 0) {
            throw new \RuntimeException('Payload decoders must be not empty.');
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

        throw new \RuntimeException('Unsupported payload.');
    }
}
