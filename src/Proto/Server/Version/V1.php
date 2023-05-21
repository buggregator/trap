<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto\Server\Version;

use Buggregator\Client\Proto\Server\Request;
use InvalidArgumentException;
use RuntimeException;

final class V1 implements PayloadDecoder
{
    public function isSupport(string $payload): bool
    {
        return \strlen($payload) > 48;
    }

    public function decode(string $payload): Request
    {
        $header = \explode('|', \substr($payload, 0, 48), 4);
        if (\count($header) !== 4) {
            throw new InvalidArgumentException('Invalid data.');
        }

        /**
         * @var string $protocol
         * @var non-empty-string $client
         * @var non-empty-string $uuid
         * @var non-empty-string $payload
         */
        [$protocol, $client, $uuid, $payload] = $header;

        // Validation

        // Protocol version
        if (\preg_match('/^\d++$/', $protocol) !== 1) {
            throw new InvalidArgumentException('Invalid protocol.');
        }

        /** @var positive-int $protocol */
        $protocol = (int)$protocol;
        \assert($protocol > 0);

        // UUID
        $this->validateUuid($uuid);

        $payload .= \substr($payload, 48);

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
