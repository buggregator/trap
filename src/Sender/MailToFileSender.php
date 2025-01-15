<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Proto\Frame\Smtp;
use Buggregator\Trap\Sender;

/**
 * @internal
 */
class MailToFileSender implements Sender
{
    private readonly string $path;

    public function __construct(
        string $path = 'runtime/mail',
    )
    {
        $this->path = \rtrim($path, '/\\');
        if (!\is_dir($path) && !\mkdir($path, 0o777, true) && !\is_dir($path)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $path));
        }
    }

    public function send(iterable $frames): void
    {
        /** @var Frame $frame */
        foreach ($frames as $frame) {
            if (!$frame instanceof Smtp) {
                continue;
            }

            foreach ($frame->message->getBcc() as $bcc) {
                if (null === $bcc->email) {
                    continue;
                }

                $path = $this->path . DIRECTORY_SEPARATOR . $bcc->email;
                if (!\is_dir($path) && !\mkdir($path, 0o777, true) && !\is_dir($path)) {
                    throw new \RuntimeException(\sprintf('Directory "%s" was not created', $path));
                }
                $filepath = \sprintf("%s/%s.json", $path, \date('Y-m-d-H-i-s-v'));
                \assert(!\file_exists($filepath));
                \file_put_contents($filepath, \json_encode($frame->message, \JSON_THROW_ON_ERROR));
            }
        }
    }
}
