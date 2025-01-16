<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Proto\Frame\Smtp;
use Buggregator\Trap\Sender;
use Buggregator\Trap\Support\FileSystem;
use Buggregator\Trap\Traffic\Message;
use Buggregator\Trap\Traffic\Message\Smtp\Contact;

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

            foreach (self::fetchDirectories($frame->message) as $dirName) {
                $path = $this->path . DIRECTORY_SEPARATOR . $dirName;
                FileSystem::mkdir($path);
                $filepath = \sprintf("%s/%s.json", $path, $frame->time->format('Y-m-d-H-i-s-v'));

                \assert(!\file_exists($filepath));
                \file_put_contents($filepath, \json_encode($frame->message, \JSON_THROW_ON_ERROR));
            }
        }
    }

    /**
     * Get normalized email address for file or directory name.
     *
     * @return non-empty-string
     */
    private static function normalizeEmail(string $email): string
    {
        return \preg_replace(['/[^a-z0-9.\\- @]/i', '/@/', '/\s+/'], ['!', '[at]', '_'], $email);
    }

    /**
     * @return list<non-empty-string>
     */
    private static function fetchDirectories(Message\Smtp $message): array
    {
        return
            \array_filter(
                \array_unique(
                    \array_map(
                        static fn(Contact $c) => self::normalizeEmail($c->email),
                        \array_merge($message->getBcc(), $message->getTo()),
                    ),
                ),
            );
    }
}
