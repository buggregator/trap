<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend\Api;

/**
 * A period cut into at most 61 equal time buckets, each aligned to its step: a 5-minute bucket starts at
 * :00, :05, ... The step is the smallest of 1m, 5m, 15m, 30m, 1h, 3h, 6h, 12h, 1d, 7d that fits 60 buckets.
 *
 * @internal
 */
final class Buckets
{
    private const STEPS = [60, 300, 900, 1800, 3600, 10800, 21600, 43200, 86400, 604800];
    private const MAX = 60;

    /** Unix seconds */
    public readonly float $start;

    /** @var int<1, max> Seconds */
    public readonly int $step;

    /** @var int<1, max> */
    public readonly int $count;

    /**
     * @param float $from Unix seconds.
     * @param float $to Unix seconds, after `$from`.
     */
    public function __construct(float $from, float $to)
    {
        $step = self::STEPS[\count(self::STEPS) - 1];
        foreach (self::STEPS as $candidate) {
            if ($to - $from <= (float) ($candidate * self::MAX)) {
                $step = $candidate;
                break;
            }
        }

        $this->step = $step;
        $this->start = \floor($from / (float) $step) * (float) $step;
        $this->count = \max(1, \min(self::MAX + 1, (int) (($to - $this->start) / (float) $step) + 1));
    }

    /**
     * @return int<0, max>|null Null for a time out of the buckets.
     */
    public function index(?float $time): ?int
    {
        if ($time === null) {
            return null;
        }

        $index = (int) \floor(($time - $this->start) / (float) $this->step);
        return $index >= 0 && $index < $this->count ? $index : null;
    }

    /**
     * @param array<int, int> $counts By bucket index.
     * @param array<int, int> $errors By bucket index.
     * @return list<array{t: string|null, count: int, errors: int}>
     */
    public function json(array $counts, array $errors): array
    {
        $result = [];
        for ($i = 0; $i < $this->count; ++$i) {
            $result[] = [
                't' => Json::time($this->start + (float) ($i * $this->step)),
                'count' => $counts[$i] ?? 0,
                'errors' => $errors[$i] ?? 0,
            ];
        }

        return $result;
    }
}
