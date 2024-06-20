<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender;
use Buggregator\Trap\Support\StreamHelper;
use Nyholm\Psr7\Stream;

/**
 * Sends event body stream to file if possible.
 * It creates a new file for each frame.
 *
 * @internal
 */
class BodyToFileSender implements Sender
{
    private readonly string $path;

    public function __construct(
        string $path = 'runtime/body',
    ) {
        $this->path = \rtrim($path, '/\\');
        if (!\is_dir($path) && !\mkdir($path, 0o777, true) && !\is_dir($path)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $path));
        }
    }

    public function send(iterable $frames): void
    {
        $fileName = 'dump-' . (new \DateTimeImmutable())->format('Y-m-d-H-i-s-v') . '[%d].log';
        $i = 0;

        /** @var Frame $frame */
        foreach ($frames as $frame) {
            if (!$frame instanceof \Buggregator\Trap\Proto\StreamCarrier) {
                continue;
            }

            $stream = $frame->getStream();
            $size = $stream?->getSize();
            if ($stream === null || $size === null || $size === 0) {
                continue;
            }

            \assert($size > 0);
            $stream = StreamHelper::concurrentReadStream($stream);
            $stream->rewind();

            // Create file descriptor
            $fName = \sprintf($fileName, $i);
            $fd = \fopen("{$this->path}/{$fName}", 'wb');
            try {
                $toStream = new Stream($fd);
                $fd === false and throw new \RuntimeException(\sprintf('File "%s" was not created', $fName));
                \flock($fd, \LOCK_EX);
                StreamHelper::writeStream($stream, $toStream, $size);
            } finally {
                \flock($fd, \LOCK_UN);
                \fclose($fd);
            }
        }
    }
}
