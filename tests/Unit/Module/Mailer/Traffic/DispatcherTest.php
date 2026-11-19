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

        Assert::same($replies[1], "250-mailamie\r\n250-AUTH PLAIN LOGIN\r\n250 STARTTLS\r\n");
    }

    #[Test]
    public function testEhloDoesNotAdvertiseStartTlsWhenTlsIsOff(): void
    {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(self::session($replies, "EHLO a\r\n"));

        $this->dispatch($stream);

        Assert::same($replies[1], "250-mailamie\r\n250 AUTH PLAIN LOGIN\r\n");
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

        Assert::same($replies[3], "220 Ready to start TLS\r\n");
        Assert::true($stream->isTlsStarted());
        // Over TLS the stream can't switch again
        Assert::same($replies[4], "250-mailamie\r\n250 AUTH PLAIN LOGIN\r\n");
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

        Assert::same($replies[2], "454 TLS not available\r\n");
        Assert::false($stream->isTlsStarted());
    }

    #[Test]
    #[DataSet(["EXPN staff\r\n", "502 5.5.2 Command not implemented\r\n"], 'EXPN')]
    #[DataSet(["XCLIENT ADDR=1.2.3.4\r\n", "502 5.5.2 Command not implemented\r\n"], 'an extension')]
    #[DataSet(["what is this\r\n", "502 5.5.2 Command not implemented\r\n"], 'junk')]
    #[DataSet(["\r\n", "500 5.5.2 Syntax error, command unrecognized\r\n"], 'an empty line')]
    #[DataSet(["   \r\n", "500 5.5.2 Syntax error, command unrecognized\r\n"], 'a line of spaces')]
    #[DataSet(["VRFY alice\r\n", "252 2.5.0 Cannot VRFY user, but will accept message\r\n"], 'VRFY')]
    #[DataSet(["help\r\n", "214 2.0.0 Commands: HELO EHLO MAIL RCPT DATA RSET NOOP QUIT AUTH STARTTLS VRFY HELP\r\n"], 'HELP')]
    #[DataSet(["MAIL FROM alice@example.com\r\n", "501 5.5.4 Syntax: MAIL FROM:<address>\r\n"], 'MAIL without brackets')]
    #[DataSet(["RCPT bob@example.com\r\n", "501 5.5.4 Syntax: RCPT TO:<address>\r\n"], 'RCPT without TO')]
    public function testEveryCommandGetsReply(string $command, string $expected): void
    {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(self::session($replies, "EHLO a\r\n", $command, "NOOP\r\n"));

        $this->dispatch($stream);

        Assert::same($replies[2], $expected);
        Assert::same($replies[3], "250 \r\n", 'The session goes on after the reply.');
    }

    /**
     * @param list<string> $exchange The AUTH command and the responses of the client.
     * @param list<string> $expected The reply to each line of the exchange.
     */
    #[Test]
    #[DataSet([["AUTH PLAIN AGpvaG4AczNjcmV0\r\n"], ["235 2.7.0 Authentication successful\r\n"], 'PLAIN', 'john', 's3cret'], 'PLAIN with an initial response')]
    #[DataSet([["auth plain YWRtaW4Aam9obgBzM2NyZXQ=\r\n"], ["235 2.7.0 Authentication successful\r\n"], 'PLAIN', 'john', 's3cret', 'admin'], 'PLAIN with an authorization identity')]
    #[DataSet([["AUTH PLAIN am9obgBqb2huAHMzY3JldA==\r\n"], ["235 2.7.0 Authentication successful\r\n"], 'PLAIN', 'john', 's3cret'], 'PLAIN with the username as the authorization identity')]
    #[DataSet([["AUTH PLAIN\r\n", "AGpvaG4AczNjcmV0\r\n"], ["334 \r\n", "235 2.7.0 Authentication successful\r\n"], 'PLAIN', 'john', 's3cret'], 'PLAIN after a challenge')]
    #[DataSet([["AUTH LOGIN\r\n", "am9obg==\r\n", "czNjcmV0\r\n"], ["334 VXNlcm5hbWU6\r\n", "334 UGFzc3dvcmQ6\r\n", "235 2.7.0 Authentication successful\r\n"], 'LOGIN', 'john', 's3cret'], 'LOGIN')]
    #[DataSet([["AUTH LOGIN am9obg==\r\n", "czNjcmV0\r\n"], ["334 UGFzc3dvcmQ6\r\n", "235 2.7.0 Authentication successful\r\n"], 'LOGIN', 'john', 's3cret'], 'LOGIN with an initial username')]
    #[DataSet([["AUTH LOGIN =\r\n", "=\r\n"], ["334 UGFzc3dvcmQ6\r\n", "235 2.7.0 Authentication successful\r\n"], 'LOGIN', '', ''], 'LOGIN with empty responses')]
    public function testAuthCredentialsGoWithEveryMailOfSession(
        array $exchange,
        array $expected,
        string $mechanism,
        string $username,
        string $password,
        ?string $authzid = null,
    ): void {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(self::session($replies, ...[
            "EHLO a\r\n",
            ...$exchange,
            ...self::mail('First'),
            "RSET\r\n",
            "EHLO a\r\n",
            ...self::mail('Second'),
        ]));

        $frames = $this->dispatch($stream);

        Assert::same(\array_slice($replies, 2, \count($expected)), $expected);
        Assert::count($frames, 2);
        foreach ($frames as $frame) {
            Assert::same($frame->message->getProtocol(), [
                'FROM' => ['alice@example.com'],
                'BCC' => ['bob@example.com'],
                'AUTH' => [$mechanism],
                ...($authzid === null ? [] : ['AUTHZID' => [$authzid]]),
                'USERNAME' => [$username],
                'PASSWORD' => [$password],
            ]);
        }
    }

    /**
     * @param list<string> $exchange The AUTH command and the responses of the client.
     */
    #[Test]
    #[DataSet([["AUTH PLAIN\r\n", "*\r\n"], "501 5.7.0 Authentication cancelled\r\n"], 'PLAIN cancelled')]
    #[DataSet([["AUTH LOGIN\r\n", "*\r\n"], "501 5.7.0 Authentication cancelled\r\n"], 'LOGIN cancelled at the username')]
    #[DataSet([["AUTH LOGIN am9obg==\r\n", "*\r\n"], "501 5.7.0 Authentication cancelled\r\n"], 'LOGIN cancelled at the password')]
    #[DataSet([["AUTH PLAIN not*base64\r\n"], "501 5.5.2 Cannot decode response\r\n"], 'PLAIN not in base64')]
    #[DataSet([["AUTH LOGIN\r\n", "%%%\r\n"], "501 5.5.2 Cannot decode response\r\n"], 'LOGIN not in base64')]
    #[DataSet([["AUTH PLAIN bm9udWw=\r\n"], "501 5.5.2 Malformed PLAIN response\r\n"], 'PLAIN without NUL separators')]
    #[DataSet([["AUTH CRAM-MD5\r\n"], "504 5.5.4 Unrecognized authentication type\r\n"], 'CRAM-MD5')]
    #[DataSet([["AUTH\r\n"], "501 5.5.4 Syntax: AUTH mechanism\r\n"], 'no mechanism')]
    public function testFailedAuthLeavesSessionUnauthenticated(array $exchange, string $expected): void
    {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(
            self::session($replies, "EHLO a\r\n", ...$exchange, ...self::mail('Unauthenticated')),
        );

        $frames = $this->dispatch($stream);

        Assert::same($replies[\count($exchange) + 1], $expected);
        Assert::count($frames, 1);
        Assert::same($frames[0]->message->getProtocol(), ['FROM' => ['alice@example.com'], 'BCC' => ['bob@example.com']]);
    }

    #[Test]
    public function testSecondAuthIsRefused(): void
    {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(self::session(
            $replies,
            "EHLO a\r\n",
            "AUTH PLAIN AGpvaG4AczNjcmV0\r\n",
            "AUTH LOGIN\r\n",
            ...self::mail('Twice'),
        ));

        $frames = $this->dispatch($stream);

        Assert::same($replies[3], "503 5.5.1 Already authenticated\r\n");
        Assert::same($frames[0]->message->getProtocol()['USERNAME'], ['john']);
    }

    #[Test]
    public function testAuthIsRefusedDuringMailTransaction(): void
    {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(self::session(
            $replies,
            "EHLO a\r\n",
            "MAIL FROM:<alice@example.com>\r\n",
            "AUTH PLAIN AGpvaG4AczNjcmV0\r\n",
        ));

        $this->dispatch($stream);

        Assert::same($replies[3], "503 5.5.1 AUTH not allowed during a mail transaction\r\n");
    }

    /**
     * STARTTLS starts the session over: the client authenticates again over TLS.
     */
    #[Test]
    public function testStartTlsDropsCredentials(): void
    {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(
            self::session(
                $replies,
                "EHLO a\r\n",
                "AUTH PLAIN AGpvaG4AczNjcmV0\r\n",
                "STARTTLS\r\n",
                "EHLO a\r\n",
                ...self::mail('Over TLS'),
            ),
            tls: true,
        );

        $frames = $this->dispatch($stream);

        Assert::count($frames, 1);
        Assert::same($frames[0]->message->getProtocol(), ['FROM' => ['alice@example.com'], 'BCC' => ['bob@example.com']]);
    }

    #[Test]
    #[DataSet(["MAIL FROM:<alice@example.com> SIZE=1024 BODY=8BITMIME\r\n", "RCPT TO:<bob@example.com>\r\n", 'alice@example.com', 'bob@example.com'], 'MAIL with SIZE and BODY')]
    #[DataSet(["MAIL FROM:<alice@example.com> AUTH=<>\r\n", "RCPT TO:<bob@example.com>\r\n", 'alice@example.com', 'bob@example.com'], 'MAIL with an empty AUTH')]
    #[DataSet(["MAIL FROM:<> AUTH=<>\r\n", "RCPT TO:<bob@example.com>\r\n", '', 'bob@example.com'], 'null sender with an empty AUTH')]
    #[DataSet(["MAIL FROM:<alice@example.com> AUTH=<alice@example.com> SIZE=10\r\n", "RCPT TO:<bob@example.com> NOTIFY=NEVER\r\n", 'alice@example.com', 'bob@example.com'], 'MAIL with AUTH, RCPT with NOTIFY')]
    #[DataSet(["MAIL FROM:<alice@example.com>\r\n", "RCPT TO:<bob@example.com> NOTIFY=SUCCESS,FAILURE ORCPT=rfc822;bob@example.com\r\n", 'alice@example.com', 'bob@example.com'], 'RCPT with NOTIFY and ORCPT')]
    public function testEnvelopeParametersAreNoPartOfAddress(string $mail, string $rcpt, string $from, string $to): void
    {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(
            self::session($replies, "EHLO a\r\n", $mail, $rcpt, "DATA\r\n", "Subject: Params\r\n\r\nHello\r\n.\r\n"),
        );

        $frames = $this->dispatch($stream);

        Assert::count($frames, 1);
        Assert::same($frames[0]->message->getProtocol(), ['FROM' => [$from], 'BCC' => [$to]]);
    }

    /**
     * A pipelining client sends the commands of a transaction in one chunk.
     */
    #[Test]
    public function testCommandsInOneChunk(): void
    {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(self::session(
            $replies,
            "EHLO a\r\n",
            "MAIL FROM:<alice@example.com> SIZE=10\r\nRCPT TO:<bob@example.com>\r\nRCPT TO:<carol@example.com>\r\nDATA\r\n",
            "Subject: Pipelined\r\n\r\nHello\r\n.\r\n",
        ));

        $frames = $this->dispatch($stream);

        Assert::count($frames, 1);
        Assert::same($frames[0]->message->getSubject(), 'Pipelined');
        Assert::same(
            $frames[0]->message->getProtocol(),
            ['FROM' => ['alice@example.com'], 'BCC' => ['bob@example.com', 'carol@example.com']],
        );
    }

    #[Test]
    #[DataSet(["ehlo a\r\n", "mail from:<alice@example.com>\r\n", "rcpt to:<bob@example.com>\r\n", "data\r\n"], 'lower case')]
    #[DataSet(["Ehlo a\r\n", "Mail From:<alice@example.com>\r\n", "Rcpt To:<bob@example.com>\r\n", "Data\r\n"], 'mixed case')]
    #[DataSet(["  EHLO a  \r\n", "MAIL FROM: <alice@example.com>  \r\n", "RCPT TO: <bob@example.com> \r\n", "DATA \r\n"], 'padded with spaces')]
    #[DataSet(["EHLO a\n", "MAIL FROM:<alice@example.com>\n", "RCPT TO:<bob@example.com>\n", "DATA\n"], 'bare LF')]
    public function testCommandSpelling(string $ehlo, string $mail, string $rcpt, string $data): void
    {
        $replies = [];
        $stream = StreamClientMock::createFromGenerator(
            self::session($replies, $ehlo, $mail, $rcpt, $data, "Subject: Spelled\r\n\r\nHello\r\n.\r\n"),
        );

        $frames = $this->dispatch($stream);

        Assert::count($frames, 1);
        Assert::same($frames[0]->message->getSubject(), 'Spelled');
        Assert::same($frames[0]->message->getProtocol(), ['FROM' => ['alice@example.com'], 'BCC' => ['bob@example.com']]);
        Assert::same($replies[4], "354 \r\n");
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
     * @return list<string> The commands of one mail transaction.
     */
    private static function mail(string $subject): array
    {
        return [
            "MAIL FROM:<alice@example.com>\r\n",
            "RCPT TO:<bob@example.com>\r\n",
            "DATA\r\n",
            "Subject: $subject\r\n\r\nHello\r\n.\r\n",
        ];
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
