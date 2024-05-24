<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Dispatcher;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Support\StreamHelper;
use Buggregator\Trap\Traffic\Dispatcher;
use Buggregator\Trap\Traffic\StreamClient;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
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

    public function detect(string $data, \DateTimeImmutable $createdAt): ?bool
    {
        // Detect bin data
        if (\preg_match_all('/[\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f\\x7f]/', $data) === 1) {
            return true;
        }

        return null;
    }
}
