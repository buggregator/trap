<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Dispatcher;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Support\StreamHelper;
use Buggregator\Client\Traffic\Dispatcher;
use Buggregator\Client\Traffic\StreamClient;
use DateTimeImmutable;

/**
 * @internal
 * @psalm-internal Buggregator\Client
 */
final class Binary implements Dispatcher
{
    public function dispatch(StreamClient $stream): iterable
    {
        $fileStream = StreamHelper::createFileStream();
        foreach ($stream->getIterator() as $chunk) {
            $fileStream->write($chunk);
        }

        return [new Frame\Binary($fileStream, $stream->getCreatedAt())];
    }

    public function detect(string $data, DateTimeImmutable $createdAt): ?bool
    {
        // Detect bin data
        if (\preg_match_all('/[\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f\\x7f]/', $data) === 1) {
            return true;
        }

        return null;
    }
}
