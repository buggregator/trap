<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend\Api;

/**
 * Requests, errors and durations of a service-map node or edge over a period.
 *
 * @internal
 */
final class Stats
{
    /** @var int<0, max> */
    public int $count = 0;

    /** @var int<0, max> */
    private int $errors = 0;

    /** @var list<int> Milliseconds */
    private array $durations = [];

    /** @var array<int, int> Bucket index => requests */
    private array $bucketCounts = [];

    /** @var array<int, int> Bucket index => errors */
    private array $bucketErrors = [];

    public function add(Buckets $buckets, ?float $time, ?int $durationMs, bool $isError): void
    {
        ++$this->count;
        $isError and ++$this->errors;
        $durationMs === null or $this->durations[] = $durationMs;

        $index = $buckets->index($time);
        if ($index !== null) {
            $this->bucketCounts[$index] = ($this->bucketCounts[$index] ?? 0) + 1;
            $isError and $this->bucketErrors[$index] = ($this->bucketErrors[$index] ?? 0) + 1;
        }
    }

    /**
     * @param float $minutes Length of the period, for the requests per minute.
     */
    public function json(Buckets $buckets, float $minutes): array
    {
        $avg = $p95 = null;
        if ($this->durations !== []) {
            $durations = $this->durations;
            \sort($durations);
            // As floats: the sum of many long durations may not fit an int.
            $sum = 0.0;
            foreach ($durations as $duration) {
                $sum += (float) $duration;
            }
            $avg = (int) \floor($sum / (float) \count($durations));
            // Nearest rank
            $p95 = $durations[\intdiv(95 * \count($durations) + 99, 100) - 1];
        }

        return [
            'request_count' => $this->count,
            'error_count' => $this->errors,
            'error_rate' => $this->count > 0 ? \round($this->errors / $this->count, 3) : 0.0,
            'rpm' => $minutes > 0 ? \round((float) $this->count / $minutes, 3) : 0.0,
            'avg_duration_ms' => $avg,
            'p95_duration_ms' => $p95,
            'buckets' => $buckets->json($this->bucketCounts, $this->bucketErrors),
        ];
    }
}
