<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Dispatcher;

use Buggregator\Trap\Proto\Frame\Smtp as SmtpFrame;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Buggregator\Trap\Traffic\Dispatcher\Smtp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Smtp::class)]
class SmtpTest extends TestCase
{
    use FiberTrait;

    public function testDispatchOneMail(): void
    {
        $stream = StreamClientMock::createFromGenerator($this->mailMe(quit: true));

        $this->runInFiber(static function () use ($stream): void {
            $cnt = 0;
            foreach ((new Smtp())->dispatch($stream) as $frame) {
                self::assertInstanceOf(SmtpFrame::class, $frame);
                self::assertSame('Test email', $frame->message->getSubject());
                ++$cnt;
            }

            self::assertSame(1, $cnt, 'Only one frame should be yielded.');
        });
    }

    public function testDispatchOneMailWithRset(): void
    {
        $stream = StreamClientMock::createFromGenerator((function (\Generator ...$generators) {
            yield "EHLO\r\n";
            yield "MAIL FROM: <someusername@foo.bar>\r\n";
            yield "RCPT TO: <user10@company.tld>\r\n";
            yield "RCPT TO: <user22@company.tld>\r\n";
            yield "RSET\r\n";
            yield from $this->mailMe('Test email');
        })());

        $this->runInFiber(static function () use ($stream): void {
            $cnt = 0;
            foreach ((new Smtp())->dispatch($stream) as $frame) {
                self::assertInstanceOf(SmtpFrame::class, $frame);
                self::assertSame("Test email", $frame->message->getSubject());
                self::assertSame(
                    ['user1@company.tld', 'user2@company.tld'],
                    $frame->message->getProtocol()['BCC'],
                    'RSET should clear the buffered data.',
                );
                ++$cnt;
            }

            self::assertSame(1, $cnt, 'Only one frame should be yielded.');
        });
    }

    public function testDispatchMultipleMails(): void
    {
        $stream = StreamClientMock::createFromGenerator((static function (\Generator ...$generators) {
            foreach ($generators as $generator) {
                yield from $generator;
            }
        })(
            $this->mailMe('Test email 1'),
            $this->mailMe('Test email 2'),
            $this->mailMe('Test email 3'),
        ));

        $this->runInFiber(static function () use ($stream): void {
            $i = 1;
            foreach ((new Smtp())->dispatch($stream) as $frame) {
                self::assertInstanceOf(SmtpFrame::class, $frame);
                self::assertSame("Test email $i", $frame->message->getSubject());

                if (++$i === 3) {
                    return;
                }
            }

            self::fail('No frame was yielded.');
        });
    }

    private function mailMe(string $subject = 'Test email', bool $quit = false): \Generator
    {
        yield "EHLO\r\n";
        yield "MAIL FROM: <someusername@foo.bar>\r\n";
        yield "RCPT TO: <user1@company.tld>\r\n";
        yield "RCPT TO: <user2@company.tld>\r\n";
        yield "NOOP\r\n";
        yield "DATA\r\n";
        yield "From: sender@example.com\r\n";
        yield "To: recipient@example.com\r\n";
        yield "Subject: $subject\r\n";
        yield "Content-Type: text/plain\r\n";
        yield "\r\n";
        yield "Hello, this is a test email.\r\n";
        yield ".\r\n";
        yield "NOOP\r\n";
        if ($quit) {
            yield "QUIT\r\n";
        }
    }
}
