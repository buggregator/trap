<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender;

/**
 * Store event groups to files.
 * JSON format is used for serialization of each event.
 *
 * @internal
 */
class EventsToFileSender implements Sender
{
    private readonly string $path;

    public function __construct(
        string $path = 'runtime',
    ) {
        $this->path = \rtrim($path, '/\\');
        if (!\is_dir($path) && !\mkdir($path, 0o777, true) && !\is_dir($path)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $path));
        }
    }

    public function send(iterable $frames): void
    {
        $data = \implode(
            "\n",
            \array_map(
                static fn(Frame $frame): string => $frame->__toString(),
                \is_array($frames) ? $frames : \iterator_to_array($frames),
            ),
        ) . "\n";

        $fileName = 'dump-' . (new \DateTimeImmutable())->format('Y-m-d-H-i-s-v') . '.log';
        \file_put_contents("{$this->path}/{$fileName}", $data, \FILE_APPEND);
    }
}
