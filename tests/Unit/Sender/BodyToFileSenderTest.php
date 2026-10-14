<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender;

use Buggregator\Trap\Info;
use Buggregator\Trap\Proto\Frame\Binary;
use Buggregator\Trap\Sender\BodyToFileSender;
use Nyholm\Psr7\Stream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Test]
#[Covers(BodyToFileSender::class)]
final class BodyToFileSenderTest
{
    private const ROOT = Info::TRAP_ROOT . '/runtime/tests/body-to-file-sender';

    public function testWritesBodyToFile(): void
    {
        $sender = new BodyToFileSender(self::ROOT . '/body');

        self::sendInFiber($sender, 'Hello, body!');

        $files = \glob(\str_replace('[', '[[]', self::ROOT . '/body/*.log'));
        Assert::count($files, 1);
        Assert::same(\file_get_contents($files[0]), 'Hello, body!');
    }

    public function testThrowsWhenFileIsNotCreated(): never
    {
        $sender = new BodyToFileSender(self::ROOT . '/body');
        // The target directory turns into a regular file, so fopen() cannot create a file inside it
        \rmdir(self::ROOT . '/body');
        \file_put_contents(self::ROOT . '/body', '');

        Expect::exception(\RuntimeException::class)
            ->withMessageMatchingRegex('/^File "dump-[^"]+\.log" was not created$/');

        \set_error_handler(static fn(): bool => true, \E_WARNING);
        try {
            self::sendInFiber($sender, 'Hello, body!');
        } finally {
            \restore_error_handler();
        }
    }

    #[AfterTest]
    protected function cleanup(): void
    {
        $body = self::ROOT . '/body';
        if (\is_dir($body)) {
            \array_map('unlink', \glob("$body/*"));
            \rmdir($body);
        } elseif (\is_file($body)) {
            \unlink($body);
        }
        \is_dir(self::ROOT) and \rmdir(self::ROOT);
    }

    private static function sendInFiber(BodyToFileSender $sender, string $body): void
    {
        $fiber = new \Fiber(static fn() => $sender->send([new Binary(Stream::create($body))]));
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }
    }
}
