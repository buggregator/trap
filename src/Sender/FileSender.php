<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Sender;
use DateTimeImmutable;

class FileSender implements Sender
{
    public function send(iterable $frames): void
    {
        $data = \implode("\n", \array_map(
            static fn (Frame $frame): string => $frame->__toString(),
            \is_array($frames) ? $frames : \iterator_to_array($frames),
        )) . "\n";

        \file_put_contents('dump-' . (new DateTimeImmutable())->format('Y-m-d-H-i-s-v') . '.log', $data, \FILE_APPEND);
    }
}
