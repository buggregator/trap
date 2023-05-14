<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Dispatcher;

use Buggregator\Client\Logger;
use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Traffic\Dispatcher;
use DateTimeImmutable;

final class Monolog implements Dispatcher
{
    public function dispatch(StreamClient $stream): iterable
    {
        while (!$stream->isFinished()) {
            $line = \trim($stream->fetchLine());
            if ($line === '') {
                continue;
            }

            Logger::debug('Got monolog');

            yield new Frame(
                new DateTimeImmutable(),
                ProtoType::Monolog,
                $line,
            );
        }

    }

    public function detect(string $data): ?bool
    {
        return \str_starts_with($data, '{"message":');
    }
}
