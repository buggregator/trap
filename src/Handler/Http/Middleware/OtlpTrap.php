<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Middleware;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap\JsonTracesDecoder;
use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap\ProtobufTracesDecoder;
use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap\TracesDecoder;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Support\Json;
use Http\Message\Encoding\GzipDecodeStream;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Receives OpenTelemetry traces over OTLP/HTTP (`POST /v1/traces`).
 *
 * JSON is always decoded. Protobuf is decoded when the suggested protobuf packages are installed,
 * see {@see ProtobufTracesDecoder}; otherwise it gets 415 like any other content type.
 * Logs and metrics (`/v1/logs`, `/v1/metrics`) are passed to the next middleware.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class OtlpTrap implements Middleware
{
    private const MAX_BODY_SIZE = 10 * 1024 * 1024;
    private const TRACES_PATH = '/v1/traces';

    // google.rpc.Code values for the error body
    private const RPC_INVALID_ARGUMENT = 3;
    private const RPC_RESOURCE_EXHAUSTED = 8;
    private const RPC_UNIMPLEMENTED = 12;

    /** @var array<non-empty-lowercase-string, TracesDecoder> Media type => decoder */
    private readonly array $tracesDecoders;

    private bool $protobufReported = false;

    /**
     * @param array<non-empty-lowercase-string, TracesDecoder>|null $tracesDecoders Media type => decoder.
     *        Null registers JSON, and protobuf when its packages are installed.
     */
    public function __construct(
        private readonly ?Logger $logger = null,
        ?array $tracesDecoders = null,
    ) {
        if ($tracesDecoders === null) {
            $json = new JsonTracesDecoder();
            $tracesDecoders = [JsonTracesDecoder::CONTENT_TYPE => $json];
            ProtobufTracesDecoder::isAvailable()
            and $tracesDecoders[ProtobufTracesDecoder::CONTENT_TYPE] = new ProtobufTracesDecoder($json);
        }

        $this->tracesDecoders = $tracesDecoders;
    }

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if ($request->getMethod() === 'POST'
            && \str_ends_with(\rtrim($request->getUri()->getPath(), '/'), self::TRACES_PATH)
        ) {
            return $this->processTraces($request);
        }

        return $next($request);
    }

    /**
     * Inflates the body by its gzip magic bytes rather than by `Content-Encoding`: the HTTP parser
     * already inflates gzip bodies and keeps the header, so the header alone would inflate twice.
     *
     * @return string|null Null when the decompressed body exceeds the size limit.
     */
    private static function readPayload(StreamInterface $body): ?string
    {
        $body->rewind();
        $magic = $body->read(2);
        $body->rewind();

        if ($magic !== "\x1f\x8b") {
            return $body->getContents();
        }

        // The zlib stream filter reports corrupted data as a PHP notice, not as an exception.
        \set_error_handler(static function (int $errno, string $message): never {
            throw new \RuntimeException('Invalid gzip body: ' . $message);
        });
        try {
            $stream = new GzipDecodeStream($body);
            $payload = '';
            while (!$stream->eof()) {
                $payload .= $stream->read(65536);
                if (\strlen($payload) > self::MAX_BODY_SIZE) {
                    return null;
                }
            }
        } finally {
            \restore_error_handler();
        }

        return $payload;
    }

    /**
     * The body is a `google.rpc.Status` as the OTLP/HTTP spec requires for failures.
     */
    private static function error(int $status, int $code, string $message): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            Json::encode(['code' => $code, 'message' => $message]),
        );
    }

    private function processTraces(ServerRequestInterface $request): ResponseInterface
    {
        $mediaType = \strtolower(\trim(\explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        $decoder = $this->tracesDecoders[$mediaType] ?? null;
        if ($decoder === null) {
            return $this->rejectContentType($mediaType);
        }

        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        $time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable();

        try {
            $body = $request->getBody();
            $size = $body->getSize();
            if ($size === null || $size > self::MAX_BODY_SIZE) {
                return self::error(413, self::RPC_RESOURCE_EXHAUSTED, 'Request body is too large.');
            }

            $payload = self::readPayload($body);
            if ($payload === null) {
                return self::error(413, self::RPC_RESOURCE_EXHAUSTED, 'Decompressed request body is too large.');
            }

            $frame = $decoder->decode($payload, $time);
        } catch (\Throwable $e) {
            $this->logger?->info('OTLP traces request rejected: %s', $e->getMessage());
            return self::error(400, self::RPC_INVALID_ARGUMENT, $e->getMessage());
        }

        \Fiber::suspend($frame);

        return $decoder->createSuccessResponse();
    }

    private function rejectContentType(string $mediaType): ResponseInterface
    {
        if ($mediaType === ProtobufTracesDecoder::CONTENT_TYPE) {
            if (!$this->protobufReported) {
                $this->protobufReported = true;
                $this->logger?->print(
                    'OTLP: to receive protobuf, run `composer require open-telemetry/gen-otlp-protobuf google/protobuf`'
                    . ' or set OTEL_EXPORTER_OTLP_PROTOCOL=http/json.',
                );
            }

            return self::error(
                415,
                self::RPC_UNIMPLEMENTED,
                'OTLP protobuf encoding needs the open-telemetry/gen-otlp-protobuf package, use http/json.',
            );
        }

        return self::error(
            415,
            self::RPC_UNIMPLEMENTED,
            \sprintf(
                'Unsupported content type `%s`, expected one of: %s.',
                $mediaType,
                \implode(', ', \array_keys($this->tracesDecoders)),
            ),
        );
    }
}
