<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support\Spx;

/**
 * Metadata of an SPX full report: the `<key>.json` file SPX writes next to the `<key>.txt.gz` trace.
 *
 * The trace columns have no names: {@see self::$metrics} names them, in the order SPX writes the values.
 * SPX writes `n/a` for what it doesn't know, such as the request URI of a CLI script; {@see self::string()}
 * takes it for no value.
 *
 * @internal
 */
final class Metadata
{
    /** Size limit of the JSON in bytes: SPX caps the custom metadata at 4 KB, the rest is short. */
    public const MAX_SIZE = 64 * 1024;

    /** @var array<non-empty-string, int> Column indexes by metric keys */
    private readonly array $columns;

    /**
     * @param array<array-key, mixed> $data The decoded JSON.
     * @param list<non-empty-string> $metrics Keys of the metrics, one per trace column.
     * @param int<0, max> $functionCount Functions the trace names.
     * @param int<0, max> $callCount Calls the trace records.
     */
    private function __construct(
        public readonly array $data,
        public readonly array $metrics,
        public readonly int $functionCount,
        public readonly int $callCount,
    ) {
        $this->columns = \array_flip($metrics);
    }

    /**
     * @throws \InvalidArgumentException When the JSON is not SPX report metadata or is too large.
     */
    public static function fromJson(string $json): self
    {
        \strlen($json) > self::MAX_SIZE and throw new \InvalidArgumentException(
            'The metadata is larger than ' . self::MAX_SIZE . ' bytes.',
        );

        // SPX escapes only `\b \f \n \r \t`: other control characters of a command line or the custom metadata
        // come raw, which JSON doesn't allow. The raw whitespace between the tokens stays.
        $json = (string) \preg_replace_callback(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
            static fn(array $m): string => \sprintf('\u%04x', \ord($m[0])),
            $json,
        );

        try {
            $data = \json_decode($json, true, 8, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("Invalid metadata JSON: {$e->getMessage()}.", previous: $e);
        }
        \is_array($data) && !\array_is_list($data) or throw new \InvalidArgumentException('The metadata is not a JSON object.');

        /** @var mixed $metrics */
        $metrics = $data['enabled_metrics'] ?? null;
        \is_array($metrics) && \array_is_list($metrics) && \count($metrics) <= 64
            or throw new \InvalidArgumentException('No `enabled_metrics` list in the metadata.');
        foreach ($metrics as $metric) {
            \is_string($metric) && \preg_match('/^[a-z]{1,16}$/', $metric) === 1
                or throw new \InvalidArgumentException('Invalid metric in `enabled_metrics`.');
        }
        \count(\array_unique($metrics)) === \count($metrics)
            or throw new \InvalidArgumentException('Repeated metric in `enabled_metrics`.');
        /** @var list<non-empty-string> $metrics */

        return new self(
            $data,
            $metrics,
            self::count($data, 'called_function_count', TraceReader::MAX_FUNCTIONS),
            self::count($data, 'recorded_call_count', \PHP_INT_MAX),
        );
    }

    /**
     * @return int|null Index of the metric's column in the trace; null when the metric was not enabled.
     */
    public function column(string $metric): ?int
    {
        return $this->columns[$metric] ?? null;
    }

    /**
     * @return non-empty-string|null Null when missing, empty or `n/a`.
     */
    public function string(string $key): ?string
    {
        /** @var mixed $value */
        $value = $this->data[$key] ?? null;

        return \is_string($value) && $value !== '' && $value !== 'n/a' ? $value : null;
    }

    public function int(string $key): ?int
    {
        /** @var mixed $value */
        $value = $this->data[$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    /**
     * Whether a CLI script was profiled rather than an HTTP request.
     */
    public function isCli(): bool
    {
        return ($this->data['cli'] ?? null) === 1 || ($this->data['cli'] ?? null) === true;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return int<0, max>
     */
    private static function count(array $data, string $key, int $max): int
    {
        /** @var mixed $value */
        $value = $data[$key] ?? null;
        \is_int($value) && $value >= 0 or throw new \InvalidArgumentException("No `$key` count in the metadata.");
        $value <= $max or throw new \InvalidArgumentException("The `$key` is over $max.");

        return $value;
    }
}
