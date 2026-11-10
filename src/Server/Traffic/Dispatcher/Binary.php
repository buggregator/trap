<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Traffic\Dispatcher;

use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Traffic\Dispatcher;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Buggregator\Trap\Support\StreamHelper;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Binary implements Dispatcher
{
    #[\Override]
    public function dispatch(StreamClient $stream): iterable
    {
        $fileStream = StreamHelper::createFileStream();
        foreach ($stream->getIterator() as $chunk) {
            $fileStream->write($chunk);
        }

        return [new Frame\Binary($fileStream, $stream->getCreatedAt())];
    }

    #[\Override]
    public function detect(string $data, \DateTimeImmutable $createdAt): ?bool
    {
        // Detect bin data
        if (\preg_match_all('/[\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f\\x7f]/', $data) === 1) {
            return true;
        }

        return null;
    }
}
