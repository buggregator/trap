<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Dispatcher;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Traffic\Dispatcher;
use Buggregator\Client\Traffic\StreamClient;

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

            yield new Frame\Monolog(
                (array)\json_decode($line, true, 512, JSON_THROW_ON_ERROR)
            );
        }
    }

    public function detect(string $data): ?bool
    {
        return \str_starts_with($data, '{"message":');
    }
}
