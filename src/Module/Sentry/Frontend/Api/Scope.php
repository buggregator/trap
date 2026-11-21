<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend\Api;

/**
 * The project and the period a Sentry list of the UI asks for.
 *
 * @internal
 */
final class Scope
{
    /**
     * @param non-empty-string|null $project Any project when null.
     * @param float|null $from Unix seconds; unbounded when null.
     * @param float|null $to Unix seconds, inclusive; unbounded when null.
     */
    public function __construct(
        public readonly ?string $project = null,
        public readonly ?float $from = null,
        public readonly ?float $to = null,
    ) {}

    /**
     * @param string|null $window `1h`, `15m`, `7d` back from now; `all` or empty for no bound.
     *        Ignored when `from` or `to` is given.
     * @param string|null $from Unix seconds or an ISO 8601 date.
     * @param string|null $to Unix seconds or an ISO 8601 date.
     */
    public static function fromQuery(
        ?string $project = null,
        ?string $window = null,
        ?string $from = null,
        ?string $to = null,
        ?float $now = null,
    ): self {
        $project = \trim((string) $project);
        $fromTime = self::parseTime($from);
        $toTime = self::parseTime($to);
        if ($fromTime === null && $toTime === null) {
            $seconds = self::parseWindow($window);
            $seconds === null or $fromTime = ($now ?? \microtime(true)) - (float) $seconds;
        }

        return new self($project === '' ? null : $project, $fromTime, $toTime);
    }

    /**
     * @return int<1, max>|null Seconds; null for no bound.
     */
    public static function parseWindow(?string $window): ?int
    {
        if ($window === null || \preg_match('/^\s*(\d++)\s*([smhdw])\s*$/i', $window, $match) !== 1) {
            return null;
        }

        $seconds = (int) $match[1] * match (\strtolower($match[2])) {
            's' => 1,
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            default => 604800,
        };

        return $seconds > 0 ? $seconds : null;
    }

    public static function parseTime(?string $value): ?float
    {
        $value = \trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (\is_numeric($value)) {
            return (float) $value > 0 ? (float) $value : null;
        }

        try {
            return (float) (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('U.u');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * A record without a time is in scope only when the period is unbounded, as an SQL comparison with NULL.
     */
    public function contains(string $project, ?float $time): bool
    {
        if ($this->project !== null && $this->project !== $project) {
            return false;
        }

        if ($this->from === null && $this->to === null) {
            return true;
        }

        return $time !== null
            && ($this->from === null || $time >= $this->from)
            && ($this->to === null || $time <= $this->to);
    }

    public function hasProject(string $project): bool
    {
        return $this->project === null || $this->project === $project;
    }
}
