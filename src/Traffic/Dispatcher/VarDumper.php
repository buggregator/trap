<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Dispatcher;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Traffic\Dispatcher;

final class VarDumper implements Dispatcher
{
    public function dispatch(StreamClient $stream): iterable
    {
        while (!$stream->isFinished()) {
            $line = \trim($stream->fetchLine());
            if ($line === '') {
                continue;
            }

            yield new Frame\VarDumper(
                $line
            );
        }
    }

    public function detect(string $data): ?bool
    {
        // Detect non-base64 symbols
        if (\preg_match_all('/[^a-zA-Z0-9\\/+=\\n]/', $data) !== 0) {
            return false;
        }

        return \str_contains($data, "\n") && \trim($data) !== '' ? true : null;
    }
}
