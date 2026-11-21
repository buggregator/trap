<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Http\OtlpTrap;

use Buggregator\Trap\Module\Tracing\Frame;
use Psr\Http\Message\ResponseInterface;

/**
 * Decodes an export request of one OTLP signal in one encoding (JSON, protobuf).
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Http
 */
interface Decoder
{
    /**
     * Default limit of records (log records, metric data points) in one request. It bounds the memory
     * one request takes while being decoded.
     */
    public const MAX_RECORDS = 10_000;

    /**
     * @param string $payload Uncompressed request body.
     *
     * @throws LimitExceededException When the payload has more records than the decoder accepts.
     * @throws \InvalidArgumentException|\JsonException When the payload is malformed.
     */
    public function decode(string $payload, \DateTimeImmutable $time): Frame;

    /**
     * An empty export response in the encoding of the request, as OTLP/HTTP requires.
     */
    public function createSuccessResponse(): ResponseInterface;
}
