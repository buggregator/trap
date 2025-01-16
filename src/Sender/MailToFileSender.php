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

            foreach ($this->collectUniqueEmails($frame->message) as $email) {
                $email = self::normalizeEmail($email);

                $path = $this->path . DIRECTORY_SEPARATOR . $email;
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
        return \str_replace('@', '[at]', \trim($email));
    }

    /**
     * @return list<non-empty-string>
     */
    private function collectUniqueEmails(Message\Smtp $message): array
    {
        $fn = static fn(Contact $c) => $c->email;

        return \array_unique(
            \array_filter(
                \array_merge(
                    \array_map($fn, $message->getBcc()),
                    \array_map($fn, $message->getTo()),
                ),
                static fn(string $email): bool => false !== \filter_var($email, \FILTER_VALIDATE_EMAIL)
            ),
        );
    }
}
