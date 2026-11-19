<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mailer\Traffic;

use Buggregator\Trap\Module\Mailer\Frame as SmtpFrame;
use Buggregator\Trap\Module\Mailer\Traffic\Dispatcher;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(Dispatcher::class)]
final class DispatcherTest
{
    use FiberTrait;

    #[Test]
    public function testDispatchOneMail(): void
    {
        $stream = StreamClientMock::createFromGenerator($this->mailMe(quit: true));

        $this->runInFiber(static function () use ($stream): void {
            $cnt = 0;
            foreach ((new Dispatcher())->dispatch($stream) as $frame) {
                Assert::instanceOf($frame, SmtpFrame::class);
                Assert::same($frame->message->getSubject(), 'Test email');
                ++$cnt;
            }

            Assert::same($cnt, 1, 'Only one frame should be yielded.');
        });
    }

    #[Test]
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
            foreach ((new Dispatcher())->dispatch($stream) as $frame) {
                Assert::instanceOf($frame, SmtpFrame::class);
                Assert::same($frame->message->getSubject(), "Test email");
                Assert::same($frame->message->getProtocol()['BCC'], ['user1@company.tld', 'user2@company.tld'], 'RSET should clear the buffered data.');
                ++$cnt;
            }

            Assert::same($cnt, 1, 'Only one frame should be yielded.');
        });
    }

    #[Test]
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
            foreach ((new Dispatcher())->dispatch($stream) as $frame) {
                Assert::instanceOf($frame, SmtpFrame::class);
                Assert::same($frame->message->getSubject(), "Test email $i");

                if (++$i === 3) {
                    return;
                }
            }

            Assert::fail('No frame was yielded.');
        });
    }

    #[Test]
    #[DataSet(['', 0.4, null], 'silent for a while')]
    #[DataSet(['', 0.6, true], 'silent long enough')]
    #[DataSet(['', 1.2, true], 'silent over a second')]
    #[DataSet(["EHLO\r\n", 5.0, false], 'speaking first')]
    public function testDetect(string $data, float $silence, ?bool $expected): void
    {
        Assert::same((new Dispatcher())->detect($data, $silence), $expected);
    }

    #[Test]
    public function testEhloAdvertisesStartTlsWhenTlsIsOn(): void
    {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(self::session($replies, "EHLO a\r\n"), tls: true);

        $this->dispatch($stream);

        Assert::same($replies[1], "250-mailamie\r\n250 STARTTLS\r\n");
    }

    #[Test]
    public function testEhloDoesNotAdvertiseStartTlsWhenTlsIsOff(): void
    {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(self::session($replies, "EHLO a\r\n"));

        $this->dispatch($stream);

        Assert::same($replies[1], "250 \r\n");
    }

    #[Test]
    public function testStartTlsSwitchesTheStreamAndStartsTheSessionOver(): void
    {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(
            self::session($replies, "EHLO a\r\n", "MAIL FROM: <a@b.c>\r\n", "STARTTLS\r\n", "EHLO a\r\n"),
            tls: true,
        );

        $frames = $this->dispatch($stream);

        Assert::same($replies[3], "220 Ready to start TLS \r\n");
        Assert::true($stream->isTlsStarted());
        // Over TLS the stream can't switch again
        Assert::same($replies[4], "250 \r\n");
        Assert::same($frames, []);
    }

    #[Test]
    public function testCommandsSentInPlainTextWithStartTlsAreDropped(): void
    {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(
            self::session(
                $replies,
                "EHLO a\r\n",
                "STARTTLS\r\nMAIL FROM: <injected@evil>\r\nRCPT TO: <injected@evil>\r\n",
                // Over TLS, with no EHLO to start the session over
                "RCPT TO: <recipient@example.com>\r\n",
                "DATA\r\n",
                "Subject: Over TLS\r\n\r\nHello\r\n.\r\n",
            ),
            tls: true,
        );

        $frames = $this->dispatch($stream);

        Assert::count($frames, 1);
        Assert::same($frames[0]->message->getProtocol(), ['BCC' => ['recipient@example.com']]);
    }

    #[Test]
    public function testStartTlsIsRefusedWhenTlsIsOff(): void
    {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(self::session($replies, "EHLO a\r\n", "STARTTLS\r\n"));

        $this->dispatch($stream);

        Assert::same($replies[2], "454 TLS not available \r\n");
        Assert::false($stream->isTlsStarted());
    }

    /**
     * Sends the commands and the closing QUIT, collecting the reply to each.
     *
     * @param list<string> $replies The greeting, then the reply to each command.
     */
    private static function session(array &$replies, string ...$commands): \Generator
    {
        // The stream reports what was sent before each read, nothing on the first one
        foreach ([...$commands, "QUIT\r\n", ''] as $command) {
            $reply = yield $command;
            $reply === '' or $replies[] = $reply;
        }
    }

    /**
     * @return list<SmtpFrame>
     */
    private function dispatch(StreamClientMock $stream): array
    {
        $frames = [];
        $this->runInFiber(static function () use ($stream, &$frames): void {
            foreach ((new Dispatcher())->dispatch($stream) as $frame) {
                $frames[] = $frame;
            }
        });

        return $frames;
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
