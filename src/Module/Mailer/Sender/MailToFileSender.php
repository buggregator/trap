<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer\Sender;

use Buggregator\Trap\Module\Mailer\Contact;
use Buggregator\Trap\Module\Mailer\Frame as MailerFrame;
use Buggregator\Trap\Module\Mailer\Message;
use Buggregator\Trap\Proto\Frame;
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

    #[\Override]
    public function send(iterable $frames): void
    {
        /** @var Frame $frame */
        foreach ($frames as $frame) {
            if (!$frame instanceof MailerFrame) {
                continue;
            }

            foreach (self::fetchDirectories($frame->message) as $dirName) {
                $path = $this->path . DIRECTORY_SEPARATOR . $dirName;
                FileSystem::mkdir($path);
                $filepath = \sprintf("%s/%s.json", $path, $frame->time->format('Y-m-d-H-i-s-v'));

                \assert(!\file_exists($filepath));
                \file_put_contents($filepath, \json_encode($frame->message, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
            }
        }
    }

    /**
     * Get normalized email address for file or directory name.
     *
     * @return non-empty-string|null
     */
    private static function normalizeEmail(?string $email): ?string
    {
        $normalized = \preg_replace(
            ['/[^a-z0-9.\\-_ @]/i', '/\s+/'],
            ['!', '_'],
            (string) $email,
        );

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return list<non-empty-string>
     */
    private static function fetchDirectories(Message $message): array
    {
        return \array_values(\array_filter(
            \array_unique(
                \array_map(
                    static fn(Contact $c): ?string => self::normalizeEmail($c->email),
                    \array_merge($message->getBcc(), $message->getTo()),
                ),
            ),
            static fn(?string $dir): bool => $dir !== null,
        ));
    }
}
