<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Dispatcher;

use Buggregator\Client\Logger;
use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Traffic\Dispatcher;
use DateTimeImmutable;

final class Http implements Dispatcher
{
    public function dispatch(StreamClient $stream): iterable
    {
        Logger::debug('Got http');
        yield new Frame(
            new DateTimeImmutable(),
            ProtoType::HTTP,
            $stream->fetchAll(),
        );
    }

    public function detect(string $data): ?bool
    {
        if (\preg_match('/^(GET|POST|PUT|HEAD|OPTIONS) \\W HTTP\\/1\\.\\d$/', $data) === 1) {
            Logger::info('THIS IS HTTP!');
            return true;
        }

        return \str_contains($data, "\n") ? false : null;
    }
}
