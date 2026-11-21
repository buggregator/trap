<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Http;

use Buggregator\Trap\Module\Mcp\McpStatus;
use Buggregator\Trap\Module\Tracing\Frame\OtlpLogs;
use Buggregator\Trap\Module\Tracing\Frame\OtlpMetrics;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\Decoder;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\JsonLogsDecoder;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\JsonMetricsDecoder;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\JsonTracesDecoder;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\LimitExceededException;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\ProtobufDecoder;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\ProtobufTracesDecoder;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\TracesDecoder;
use Buggregator\Trap\Module\Tracing\SpanStore;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Support\Json;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Receives OpenTelemetry traces, logs and metrics over OTLP/HTTP: `POST /v1/traces`, `/v1/logs`, `/v1/metrics`,
 * also under a path prefix such as `/api/{project}/integration/otlp`.
 *
 * JSON is always decoded. Protobuf is decoded when the suggested protobuf packages are installed,
 * see {@see ProtobufTracesDecoder}; otherwise it gets 415.
 * While the MCP server runs, received spans go to the {@see SpanStore} for its tools here, once per request.
 *
 * The project of the data is the `{project}` of `/api/{project}/integration/otlp/...`, else the
 * `X-Buggregator-Project` header; the default project without either.
 *
 * Only requests with an OTLP content type (JSON or protobuf) are handled. Other requests to these paths,
 * such as a form posted to `/api/v1/logs`, go down the pipeline and are dumped as HTTP requests.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class OtlpTrap implements Middleware
{
    private const MAX_BODY_SIZE = 10 * 1024 * 1024;
    private const OTLP_MEDIA_TYPES = [JsonTracesDecoder::CONTENT_TYPE, ProtobufTracesDecoder::CONTENT_TYPE];
    private const SIGNALS = [
        '/v1/traces' => 'traces',
        '/v1/logs' => 'logs',
        '/v1/metrics' => 'metrics',
    ];

    // google.rpc.Code values for the error body
    private const RPC_INVALID_ARGUMENT = 3;
    private const RPC_RESOURCE_EXHAUSTED = 8;
    private const RPC_UNIMPLEMENTED = 12;

    /** @var array<'traces'|'logs'|'metrics', array<non-empty-lowercase-string, Decoder>> Signal => media type => decoder */
    private readonly array $decoders;

    private bool $protobufReported = false;

    /**
     * @param array<non-empty-lowercase-string, TracesDecoder>|null $tracesDecoders Media type => decoder.
     *        Null registers JSON, and protobuf when its packages are installed; so do the other decoder lists.
     * @param SpanStore|null $spans Filled for the MCP tools while the server runs; null keeps no spans.
     * @param array<non-empty-lowercase-string, Decoder>|null $logsDecoders
     * @param array<non-empty-lowercase-string, Decoder>|null $metricsDecoders
     */
    public function __construct(
        private readonly ?Logger $logger = null,
        ?array $tracesDecoders = null,
        private readonly ?SpanStore $spans = null,
        private readonly McpStatus $mcp = new McpStatus(),
        ?array $logsDecoders = null,
        ?array $metricsDecoders = null,
    ) {
        if ($tracesDecoders === null) {
            $json = new JsonTracesDecoder();
            $tracesDecoders = [JsonTracesDecoder::CONTENT_TYPE => $json];
            ProtobufTracesDecoder::isAvailable()
            and $tracesDecoders[ProtobufTracesDecoder::CONTENT_TYPE] = new ProtobufTracesDecoder($json);
        }

        if ($logsDecoders === null) {
            $logsDecoders = [JsonLogsDecoder::CONTENT_TYPE => new JsonLogsDecoder()];
            ProtobufDecoder::isAvailable()
            and $logsDecoders[ProtobufDecoder::CONTENT_TYPE] = ProtobufDecoder::logs();
        }

        if ($metricsDecoders === null) {
            $metricsDecoders = [JsonMetricsDecoder::CONTENT_TYPE => new JsonMetricsDecoder()];
            ProtobufDecoder::isAvailable()
            and $metricsDecoders[ProtobufDecoder::CONTENT_TYPE] = ProtobufDecoder::metrics();
        }

        $this->decoders = ['traces' => $tracesDecoders, 'logs' => $logsDecoders, 'metrics' => $metricsDecoders];
    }

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if ($request->getMethod() !== 'POST' || !\in_array(self::mediaType($request), self::OTLP_MEDIA_TYPES, true)) {
            return $next($request);
        }

        $path = \rtrim($request->getUri()->getPath(), '/');
        foreach (self::SIGNALS as $suffix => $signal) {
            if (\str_ends_with($path, $suffix)) {
                return $this->process($request, $signal);
            }
        }

        return $next($request);
    }

    /**
     * The `{project}` of `/api/{project}/integration/otlp/...`, else the `X-Buggregator-Project` header.
     * A numeric path segment is the project ID of a Sentry DSN, not a project name.
     */
    private static function project(ServerRequestInterface $request): ?string
    {
        if (\preg_match('#^/api/([^/]++)/integration/otlp/#', $request->getUri()->getPath(), $match) === 1
            && !\ctype_digit($match[1])
        ) {
            return \rawurldecode($match[1]);
        }

        $project = \trim($request->getHeaderLine('X-Buggregator-Project'));
        return $project === '' ? null : $project;
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

        // zlib reports corrupted data as a PHP warning, not as an exception.
        // A stream filter is not used: dropping one with unread input warns at an arbitrary later point.
        \set_error_handler(static function (int $errno, string $message): never {
            throw new \RuntimeException('Invalid gzip body: ' . $message);
        });
        try {
            $inflate = \inflate_init(\ZLIB_ENCODING_GZIP) ?: throw new \RuntimeException('Cannot inflate the gzip body.');
            $payload = '';
            do {
                $chunk = $body->read(65536);
                $inflated = \inflate_add($inflate, $chunk, $body->eof() ? \ZLIB_FINISH : \ZLIB_SYNC_FLUSH);
                $inflated === false and throw new \RuntimeException('Invalid gzip body.');
                $payload .= $inflated;
                if (\strlen($payload) > self::MAX_BODY_SIZE) {
                    return null;
                }
            } while ($chunk !== '');
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
        return $mediaType === ProtobufTracesDecoder::CONTENT_TYPE
            ? new Response(
                $status,
                ['Content-Type' => ProtobufTracesDecoder::CONTENT_TYPE],
                self::encodeStatus($code, $message),
            )
            : new Response(
                $status,
                ['Content-Type' => JsonTracesDecoder::CONTENT_TYPE],
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
     * @param 'traces'|'logs'|'metrics' $signal
     */
    private function process(ServerRequestInterface $request, string $signal): ResponseInterface
    {
        $mediaType = self::mediaType($request);
        $decoder = $this->decoders[$signal][$mediaType] ?? null;
        if ($decoder === null) {
            return $this->rejectContentType($mediaType, $signal);
        }

        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        $time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable();

        try {
            $body = $request->getBody();
            $size = $body->getSize();
            if ($size === null || $size > self::MAX_BODY_SIZE) {
                return $this->reject($signal, $mediaType, 413, self::RPC_RESOURCE_EXHAUSTED, 'Request body is too large.');
            }

            $payload = self::readPayload($body);
            if ($payload === null) {
                return $this->reject(
                    $signal,
                    $mediaType,
                    413,
                    self::RPC_RESOURCE_EXHAUSTED,
                    'Decompressed request body is too large.',
                );
            }

            $frame = $decoder->decode($payload, $time);
        } catch (LimitExceededException $e) {
            return $this->reject($signal, $mediaType, 413, self::RPC_RESOURCE_EXHAUSTED, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->reject($signal, $mediaType, 400, self::RPC_INVALID_ARGUMENT, $e->getMessage());
        }

        $project = self::project($request);
        $frame = match (true) {
            $frame instanceof OtlpTraces => $frame->countSpans() === 0 ? null : $frame->withProject($project),
            $frame instanceof OtlpLogs => $frame->countRecords() === 0 ? null : $frame->withProject($project),
            $frame instanceof OtlpMetrics => $frame->countDataPoints() === 0 ? null : $frame->withProject($project),
            default => $frame,
        };

        if ($frame === null) {
            return $decoder->createSuccessResponse();
        }

        $frame instanceof OtlpTraces && $this->mcp->isOn() and $this->spans?->receive($frame);
        \Fiber::suspend($frame);

        return $decoder->createSuccessResponse();
    }

    private function rejectContentType(string $mediaType, string $signal): ResponseInterface
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

        return $this->reject(
            $signal,
            $mediaType,
            415,
            self::RPC_UNIMPLEMENTED,
            \sprintf(
                'Unsupported content type `%s`, expected one of: %s.',
                $mediaType,
                \implode(', ', \array_keys($this->decoders[$signal] ?? [])),
            ),
        );
    }

    /**
     * Prints the reason at the default verbosity: an exporter logs a failed export on its own side,
     * where it is easy to miss, and Trap would otherwise show nothing.
     *
     * @param int<0, max> $code `google.rpc.Code`
     */
    private function reject(string $signal, string $mediaType, int $status, int $code, string $message): ResponseInterface
    {
        $this->logger?->print(OutputFormatter::escape((string) \preg_replace(
            '/[\x00-\x1F\x7F]/',
            '?',
            \sprintf('OTLP: %s request rejected with %d: %s', $signal, $status, $message),
        )));

        return self::error($mediaType, $status, $code, $message);
    }
}
