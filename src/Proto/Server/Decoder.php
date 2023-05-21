<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto\Server;

use InvalidArgumentException;
use RuntimeException;

final class Decoder
{
    public function decode(string $rawData): Request
    {
        // V1
        if (\strlen($rawData) <= 48) {
            throw new InvalidArgumentException('Payload is too short.');
        }
        $header = \explode('|', \substr($rawData, 0, 48), 4);
        if (\count($header) !== 4) {
            throw new InvalidArgumentException('Invalid data.');
        }
        [$protocol, $client, $uuid, $payload] = $header;

        // Validation

        // Protocol version
        if (\preg_match('/^\d++$/', $protocol) !== 1) {
            throw new InvalidArgumentException('Invalid protocol.');
        }
        $protocol = (int)$protocol;
        // UUID
        $this->validateUuid($uuid);

        $payload .= \substr($rawData, 48);

        return new Request(
            protocol: $protocol,
            client: $client,
            uuid: $uuid,
            payload: $payload,
            payloadParser: function (string $payload): iterable {
                $data = \json_decode($payload, true, 8, \JSON_THROW_ON_ERROR);
                if (!\is_array($data)) {
                    throw new RuntimeException('Decoded data must be array.');
                }
                return $data;
            },
        );
    }

    /**
     * @psalm-assert non-empty-string $uuid
     */
    private function validateUuid(string $uuid): void
    {
        if (\preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) !== 1) {
            throw new InvalidArgumentException('Invalid UUID.');
        }
    }
}
