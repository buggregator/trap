<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Traffic;

/**
 * {@see StreamClient::fetchLine()} over the `\SplQueue<string> $queue` of chunks read from the client, shared
 * by the server stream and its test double.
 *
 * @internal
 * @psalm-require-implements StreamClient
 */
trait FetchLineTrait
{
    #[\Override]
    public function fetchLine(): string
    {
        $line = '';

        while (!$this->isFinished()) {
            if ($this->queue->isEmpty()) {
                // Only on an empty queue: the waiting ends when the queue grows, not when it has data
                $this->waitData();
                continue;
            }

            $chunk = $this->queue->dequeue();
            $eol = \strpos($chunk, "\n");
            if ($eol === false) {
                $line .= $chunk;
                continue;
            }

            $line .= \substr($chunk, 0, $eol + 1);
            $rest = \substr($chunk, $eol + 1);
            $rest === '' or $this->queue->unshift($rest);
            break;
        }

        return $line;
    }
}
