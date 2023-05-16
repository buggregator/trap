<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Dispatcher;

use Buggregator\Client\Proto\MonologFrame;
use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Traffic\Dispatcher;

final class Monolog implements Dispatcher
{
    /**
     * @throws \JsonException
     */
    public function dispatch(StreamClient $stream): iterable
    {
        while (!$stream->isFinished()) {
            $line = \trim($stream->fetchLine());
            if ($line === '') {
                continue;
            }

            yield new MonologFrame(
                (array)\json_decode($line, true, 512, JSON_THROW_ON_ERROR)
            );
        }
    }

    public function detect(string $data): ?bool
    {
        return \str_starts_with($data, '{"message":');
    }
}
