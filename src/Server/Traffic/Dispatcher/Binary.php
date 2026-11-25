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
    /**
     * Bytes of a connection kept in memory, the rest in a temporary file: an event keeps the stream until the
     * events limit pushes it out, so many large connections would otherwise fill the memory limit.
     */
    public const MAX_MEMORY = 64 * 1024;

    #[\Override]
    public function dispatch(StreamClient $stream): iterable
    {
        $fileStream = StreamHelper::createFileStream(maxMemory: self::MAX_MEMORY);
        foreach ($stream->getIterator() as $chunk) {
            $fileStream->write($chunk);
        }

        return [new Frame\Binary($fileStream, $stream->getCreatedAt())];
    }

    #[\Override]
    public function detect(string $data, float $silence): ?bool
    {
        // Detect bin data
        if (\preg_match_all('/[\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f\\x7f]/', $data) === 1) {
            return true;
        }

        return null;
    }
}
