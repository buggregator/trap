<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Dispatcher;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Traffic\Dispatcher;
use Buggregator\Trap\Traffic\StreamClient;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class VarDumper implements Dispatcher
{
    public function dispatch(StreamClient $stream): iterable
    {
        while (!$stream->isFinished()) {
            $line = \trim($stream->fetchLine());

            if ($line === '') {
                continue;
            }

            yield new Frame\VarDumper($line);
        }
    }

    public function detect(string $data, \DateTimeImmutable $createdAt): ?bool
    {
        // Detect non-base64 symbols
        if (\preg_match_all('/[^a-zA-Z0-9\\/+=\\n]/', $data) !== 0) {
            return false;
        }

        return \str_contains($data, "\n") && \trim($data) !== '' ? true : null;
    }
}
