<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Queue;

/**
 * A queue job: the stored attempts of one message in one project, taken by one consumer, in their order.
 * An attempt without a message ID is a job of its own.
 *
 * Its status, queue, duration and latency are those of its last attempt; it fails when its last attempt fails.
 *
 * @internal
 */
final class Job
{
    /** @var non-empty-list<Attempt> */
    public readonly array $attempts;

    public readonly Attempt $last;

    /** @var array<non-empty-string, int<1, max>> By attempt ID: unique, rising in the order of the attempts. */
    private readonly array $numbers;

    /**
     * @param non-empty-string $id See {@see QueueJobs}.
     * @param non-empty-list<Attempt> $attempts
     * @param string $fallbackName The job name of the span that published the message, for attempts without one.
     */
    public function __construct(
        public readonly string $id,
        array $attempts,
        private readonly string $fallbackName = '',
    ) {
        $this->attempts = self::order($attempts);
        $this->last = $this->attempts[\count($this->attempts) - 1];

        $numbers = [];
        $previous = 0;
        foreach ($this->attempts as $attempt) {
            $previous = $numbers[$attempt->id] = \max($previous + 1, $attempt->number ?? 1);
        }
        $this->numbers = $numbers;
    }

    public function messageId(): ?string
    {
        return $this->last->messageId;
    }

    /**
     * The number of an attempt: the one its SDK counts, raised past the attempt before it when that is not
     * higher, as an attempt without one or the same number twice.
     *
     * @return int<1, max>
     */
    public function number(Attempt $attempt): int
    {
        return $this->numbers[$attempt->id] ?? 1;
    }

    /**
     * The runs of the message: the number of its last attempt, so the stored attempts or more when an attempt
     * numbers itself higher, as the retry of a run Trap did not get.
     *
     * @return int<1, max>
     */
    public function attemptCount(): int
    {
        return $this->number($this->last);
    }

    /**
     * @return int<0, max> Of the stored attempts.
     */
    public function failedAttempts(): int
    {
        $failed = 0;
        foreach ($this->attempts as $attempt) {
            $attempt->failed() and ++$failed;
        }

        return $failed;
    }

    public function failed(): bool
    {
        return $this->last->failed();
    }

    public function retried(): bool
    {
        return $this->attemptCount() > 1;
    }

    public function name(): string
    {
        for ($i = \count($this->attempts) - 1; $i >= 0; --$i) {
            if ($this->attempts[$i]->name !== '') {
                return $this->attempts[$i]->name;
            }
        }

        return $this->fallbackName;
    }

    public function startedAt(): ?float
    {
        $start = null;
        foreach ($this->attempts as $attempt) {
            $time = $attempt->start ?? $attempt->end;
            $time === null || ($start !== null && $start <= $time) or $start = $time;
        }

        return $start;
    }

    public function finishedAt(): ?float
    {
        return $this->last->end ?? $this->last->start;
    }

    /**
     * The attempts with a number by it, immune to the clock skew between workers; those without one merged in
     * by their start, before the first numbered attempt that started later.
     *
     * @param non-empty-list<Attempt> $attempts
     * @return non-empty-list<Attempt>
     */
    private static function order(array $attempts): array
    {
        $time = static fn(Attempt $a): float => $a->start ?? $a->end ?? \INF;
        $numbered = $unnumbered = [];
        foreach ($attempts as $attempt) {
            $attempt->number === null ? $unnumbered[] = $attempt : $numbered[] = $attempt;
        }
        \usort($numbered, static fn(Attempt $a, Attempt $b): int => [$a->number, $time($a)] <=> [$b->number, $time($b)]);
        \usort($unnumbered, static fn(Attempt $a, Attempt $b): int => $time($a) <=> $time($b));

        $result = [];
        $i = $j = 0;
        while ($i < \count($numbered) || $j < \count($unnumbered)) {
            $takeUnnumbered = $j < \count($unnumbered)
                && ($i >= \count($numbered) || $time($unnumbered[$j]) < $time($numbered[$i]));
            $result[] = $takeUnnumbered ? $unnumbered[$j++] : $numbered[$i++];
        }

        /** @var non-empty-list<Attempt> $result */
        return $result;
    }
}
