<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Dispatcher;

use Buggregator\Client\Logger;
use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Traffic\Dispatcher;
use DateTimeImmutable;

final class VarDumper implements Dispatcher
{
    public function dispatch(StreamClient $stream): iterable
    {
        foreach ($stream->getIterator() as $payload) {
            yield new Frame(
                new DateTimeImmutable(),
                ProtoType::VarDumper,
                \rtrim($payload),
            );
        }
    }

    public function detect(string $data): ?bool
    {
        // Detect non-base64 symbols
        if (\preg_match('/[^a-zA-Z0-9\\/+=\\n]/', $data) !== 0) {
            Logger::info($data);
            return false;
        }

        return \str_contains($data, "\n") && \trim($data) !== '' ? true : null;
    }
}
