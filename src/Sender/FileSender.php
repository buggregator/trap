<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender;

use Buggregator\Client\Sender;
use DateTimeImmutable;

class FileSender implements Sender
{
    public function send(string $data): void
    {
        \file_put_contents(
            'dump-' . (new DateTimeImmutable())->format('Y-m-d-H-i-s-v') . '.txt',
            $data . "\n",
            \FILE_APPEND,
        );
    }
}
