<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * Paces the main loop: runs the next iteration at once while there is work, and waits when there is none.
 *
 * A request passes between several fibers, one hand-off per iteration, and not every hand-off reports
 * {@see Activity}: so the loop runs one more iteration after the last activity. Each iteration resumes
 * every fiber, so more of them cost CPU with many connections and win nothing: measured on small requests.
 * Waiting is a `socket_select()` on the given sockets, which ends as soon as one of them is ready.
 * On Windows any sleep or select timeout lasts at least ~15 ms, whatever it is asked for.
 *
 * @internal
 */
final class LoopPacer
{
    /** Waits ending on ready sockets without activity before the loop sleeps instead: nobody takes the sockets */
    private const MAX_UNCONSUMED = 3;

    private int $graceLeft = 0;
    private int $unconsumed = 0;

    /**
     * @param int<0, max> $idleTimeout Microseconds to wait when idle.
     * @param int<0, max> $grace Iterations to run without waiting after the last activity.
     */
    public function __construct(
        private readonly int $idleTimeout,
        private readonly int $grace = 1,
    ) {}

    /**
     * Waits if the loop is idle.
     *
     * @param bool $active Whether the iteration made progress.
     * @param \Closure(): array{list<\Socket>, list<\Socket>} $sockets Sockets to wait for reading and writing.
     *
     * @return list<\Socket> Sockets that are ready.
     */
    public function pause(bool $active, \Closure $sockets): array
    {
        if ($active) {
            $this->graceLeft = $this->grace;
            $this->unconsumed = 0;
            return [];
        }

        if ($this->graceLeft > 0) {
            --$this->graceLeft;
            return [];
        }

        if ($this->unconsumed >= self::MAX_UNCONSUMED) {
            $this->unconsumed = 0;
            \usleep($this->idleTimeout);
            return [];
        }

        [$read, $write] = $sockets();
        $ready = $this->wait($read, $write);
        $this->unconsumed = $ready === [] ? 0 : $this->unconsumed + 1;
        return $ready;
    }

    /**
     * Waits for the idle timeout or until any of the sockets is ready.
     *
     * @param list<\Socket> $read
     * @param list<\Socket> $write
     *
     * @return list<\Socket> Sockets that are ready.
     */
    public function wait(array $read, array $write = []): array
    {
        if ($read !== [] || $write !== []) {
            $except = null;
            try {
                // Fails with too many sockets for the platform's select
                $result = @\socket_select(
                    $read,
                    $write,
                    $except,
                    \intdiv($this->idleTimeout, 1_000_000),
                    $this->idleTimeout % 1_000_000,
                );
                if ($result !== false) {
                    return [...$read, ...$write];
                }
            } catch (\Throwable) {
                // A closed socket
            }
        }

        \usleep($this->idleTimeout);
        return [];
    }
}
