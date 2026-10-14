<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Middleware;

use Buggregator\Trap\Handler\Http\Handler\Fallback;
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
 * Logs and metrics (`/v1/logs`, `/v1/metrics`) are not supported: they are answered with success and dropped,
 * so an exporter that sends every signal to Trap does not report errors.
 *
 * Responses to OTLP requests that produce no frame, rejected ones included, are not dumped as HTTP requests.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class OtlpTrap implements Middleware
{
    private const MAX_BODY_SIZE = 10 * 1024 * 1024;
    private const TRACES_PATH = '/v1/traces';
    private const UNSUPPORTED_SIGNALS = [
        '/v1/logs' => 'logs',
        '/v1/metrics' => 'metrics',
    ];

    // google.rpc.Code values for the error body
    private const RPC_INVALID_ARGUMENT = 3;
    private const RPC_RESOURCE_EXHAUSTED = 8;
    private const RPC_UNIMPLEMENTED = 12;

    /** @var array<non-empty-lowercase-string, TracesDecoder> Media type => decoder */
    private readonly array $tracesDecoders;

    private bool $protobufReported = false;

    /** @var array<string, true> Unsupported signals already reported in the log */
    private array $reportedSignals = [];

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
        if ($request->getMethod() !== 'POST') {
            return $next($request);
        }

        $path = \rtrim($request->getUri()->getPath(), '/');
        if (\str_ends_with($path, self::TRACES_PATH)) {
            return $this->processTraces($request);
        }

        foreach (self::UNSUPPORTED_SIGNALS as $suffix => $signal) {
            if (\str_ends_with($path, $suffix)) {
                return $this->dropSignal($request, $signal);
            }
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
     * The body is a `google.rpc.Status` in the encoding of the request, as the OTLP/HTTP spec requires
     * for failures; JSON for an unknown encoding.
     *
     * @param int<0, max> $code `google.rpc.Code`
     */
    private static function error(string $mediaType, int $status, int $code, string $message): ResponseInterface
    {
        $headers = [Fallback::NO_DUMP_HEADER => '1'];

        return $mediaType === ProtobufTracesDecoder::CONTENT_TYPE
            ? new Response(
                $status,
                ['Content-Type' => ProtobufTracesDecoder::CONTENT_TYPE] + $headers,
                self::encodeStatus($code, $message),
            )
            : new Response(
                $status,
                ['Content-Type' => JsonTracesDecoder::CONTENT_TYPE] + $headers,
                Json::encode(['code' => $code, 'message' => $message]),
            );
    }

    /**
     * Encodes `google.rpc.Status` by hand, so that it does not need the protobuf packages:
     * field 1 `code` as a varint and field 2 `message` as a length-delimited string.
     *
     * @param int<0, max> $code
     */
    private static function encodeStatus(int $code, string $message): string
    {
        return "\x08" . self::varint($code) . "\x12" . self::varint(\strlen($message)) . $message;
    }

    /**
     * @param int<0, max> $value
     */
    private static function varint(int $value): string
    {
        $result = '';
        while ($value > 0x7F) {
            $result .= \chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }

        return $result . \chr($value);
    }

    private static function mediaType(ServerRequestInterface $request): string
    {
        return \strtolower(\trim(\explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
    }

    /**
     * Answers with an empty export response of the request encoding, as for a successful export.
     */
    private function dropSignal(ServerRequestInterface $request, string $signal): ResponseInterface
    {
        if (!isset($this->reportedSignals[$signal])) {
            $this->reportedSignals[$signal] = true;
            $this->logger?->print(\sprintf(
                'OTLP: %1$s are not supported yet and are dropped; set OTEL_%2$s_EXPORTER=none to stop sending them.',
                $signal,
                \strtoupper($signal),
            ));
        }

        return self::mediaType($request) === ProtobufTracesDecoder::CONTENT_TYPE
            ? new Response(200, ['Content-Type' => ProtobufTracesDecoder::CONTENT_TYPE, Fallback::NO_DUMP_HEADER => '1'])
            : new Response(200, ['Content-Type' => 'application/json', Fallback::NO_DUMP_HEADER => '1'], '{}');
    }

    private function processTraces(ServerRequestInterface $request): ResponseInterface
    {
        $mediaType = self::mediaType($request);
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
                return self::error($mediaType, 413, self::RPC_RESOURCE_EXHAUSTED, 'Request body is too large.');
            }

            $payload = self::readPayload($body);
            if ($payload === null) {
                return self::error(
                    $mediaType,
                    413,
                    self::RPC_RESOURCE_EXHAUSTED,
                    'Decompressed request body is too large.',
                );
            }

            $frame = $decoder->decode($payload, $time);
        } catch (\Throwable $e) {
            $this->logger?->info('OTLP traces request rejected: %s', $e->getMessage());
            return self::error($mediaType, 400, self::RPC_INVALID_ARGUMENT, $e->getMessage());
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
                $mediaType,
                415,
                self::RPC_UNIMPLEMENTED,
                'OTLP protobuf encoding needs the open-telemetry/gen-otlp-protobuf package, use http/json.',
            );
        }

        return self::error(
            $mediaType,
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
