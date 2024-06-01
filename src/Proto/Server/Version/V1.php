<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Server\Version;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Proto\Server\Request;
use Buggregator\Trap\ProtoType;

/**
 * @internal
 * @psalm-internal Buggregator
 */
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
            throw new \InvalidArgumentException('Invalid data.');
        }

        /**
         * @var string $protocol
         * @var non-empty-string $client
         * @var non-empty-string $uuid
         * @var non-empty-string $payload
         */
        [$protocol, $client, $uuid] = $header;

        $payload = \substr($payload, $startJson + 1);
        // Validation

        // Protocol version
        if (\preg_match('/^\d++$/', $protocol) !== 1) {
            throw new \InvalidArgumentException('Invalid protocol.');
        }

        /** @var positive-int $protocol */
        $protocol = (int) $protocol;
        \assert($protocol > 0);

        // UUID
        $this->validateUuid($uuid);

        return new Request(
            protocol: $protocol,
            client: $client,
            uuid: $uuid,
            payload: $payload,
            payloadParser: static function (string $payload): iterable {
                $data = \json_decode($payload, true, 8, \JSON_THROW_ON_ERROR);
                if (!\is_array($data)) {
                    throw new \RuntimeException('Decoded data must be array.');
                }

                return \array_map(
                    static function (array $item): Frame {
                        \assert(isset($item['type']) && \is_string($item['data']), 'Missing type.');
                        \assert(isset($item['data']) && \is_string($item['data']), 'Missing data.');
                        \assert(isset($item['time']) && \is_string($item['time']), 'Missing time.');

                        $payload = \base64_decode($item['data'], true);
                        \assert($payload !== false, 'Invalid data.');

                        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $item['time']);
                        \assert($date !== false, 'Invalid date.');

                        return match ($item['type']) {
                            ProtoType::SMTP->value => Frame\Smtp::fromString($payload, $date),
                            ProtoType::Monolog->value => Frame\Monolog::fromString($payload, $date),
                            ProtoType::VarDumper->value => Frame\VarDumper::fromString($payload, $date),
                            ProtoType::HTTP->value => Frame\Http::fromString($payload, $date),
                            ProtoType::Sentry->value => Frame\Sentry::fromString($payload, $date),
                            ProtoType::Profiler->value => Frame\Profiler::fromString($payload, $date),
                            default => throw new \RuntimeException('Invalid type.'),
                        };
                    },
                    $data,
                );
            },
        );
    }

    /**
     * @psalm-assert non-empty-string $uuid
     */
    private function validateUuid(string $uuid): void
    {
        if (\preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) !== 1) {
            throw new \InvalidArgumentException('Invalid UUID.');
        }
    }
}
