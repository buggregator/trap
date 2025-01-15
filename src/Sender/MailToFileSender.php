<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Proto\Frame\Smtp;
use Buggregator\Trap\Sender;
use Buggregator\Trap\Support\FileSystem;

/**
 * @internal
 */
class MailToFileSender implements Sender
{
    private readonly string $path;

    public function __construct(
        string $path = 'runtime/mail',
    ) {
        $this->path = \rtrim($path, '/\\');
        FileSystem::mkdir($path);
    }

    public function send(iterable $frames): void
    {
        /** @var Frame $frame */
        foreach ($frames as $frame) {
            if (!$frame instanceof Smtp) {
                continue;
            }

            foreach ($frame->message->getBcc() as $bcc) {
                if ($bcc->email === null) {
                    continue;
                }

                $path = $this->path . DIRECTORY_SEPARATOR . $bcc->email;
                FileSystem::mkdir($path);
                $filepath = \sprintf("%s/%s.json", $path, \date('Y-m-d-H-i-s-v'));
                \assert(!\file_exists($filepath));
                \file_put_contents($filepath, \json_encode($frame->message, \JSON_THROW_ON_ERROR));
            }
        }
    }
}
