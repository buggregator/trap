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
        $startJson = \strpos($payload, '[');

        if ($startJson === false) {
            return false;
        }

        $totalSegments = \count(\explode('|', \substr($payload, 0, $startJson - 1)));

        return $totalSegments === 3;
    }

    public function decode(string $payload): Request
    {
        $startJson = \strpos($payload, '[') - 1;

        $header = \explode('|', \substr($payload, 0, $startJson), 3);
        if (\count($header) !== 3) {
            throw new InvalidArgumentException('Invalid data.');
        }

        /**
         * @var string $protocol
         * @var non-empty-string $client
         * @var non-empty-string $uuid
         * @var non-empty-string $payload
         */
        [$protocol, $client, $uuid] = $header;

        $payload = \substr($payload, $startJson+1);
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
