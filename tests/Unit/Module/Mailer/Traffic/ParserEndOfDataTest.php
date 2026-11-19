<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mailer\Traffic;

use Buggregator\Trap\Module\Mailer\Message;
use Buggregator\Trap\Module\Mailer\Traffic\Dispatcher;
use Buggregator\Trap\Module\Mailer\Traffic\Parser;
use Buggregator\Trap\Server\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Test\Mock\StreamClientMock\ClientScript;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataCross;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The mail data ends with a line holding a single dot, whatever the message is.
 *
 * The client sends each packet as a separate read and keeps the connection open while it waits for the reply,
 * so a parser that misses the end of data stays suspended: the fiber is resumed a bounded number of times.
 */
#[Covers(Parser::class)]
final class ParserEndOfDataTest
{
    use FiberTrait;

    private const HANG = 'The parser still waits for data after the client has sent the end of data.';

    /**
     * @return iterable<non-empty-string, array{string, string, list<string>|null}> The data as the client sends
     *         it, the message the parser keeps, and the values of the text parts.
     */
    public static function messages(): iterable
    {
        $alternative = self::alternative('Plain text');
        $texts = ['Plain text', '<p>HTML text</p>'];

        yield 'closing boundary, then the end line (Python smtplib)' => [$alternative . ".\r\n", $alternative, $texts];
        yield 'closing boundary, an empty line, then the end line (Symfony Mailer)' => [
            $alternative . "\r\n.\r\n",
            $alternative . "\r\n",
            $texts,
        ];
        yield 'epilogue after the closing boundary' => [
            $alternative . "Epilogue\r\n.\r\n",
            $alternative . "Epilogue\r\n",
            $texts,
        ];
        yield 'dot-stuffed lines in a part' => [
            self::alternative("Line one\r\n..dot line\r\n..\r\n...\r\nLast line") . ".\r\n",
            self::alternative("Line one\r\n.dot line\r\n.\r\n..\r\nLast line"),
            ["Line one\r\n.dot line\r\n.\r\n..\r\nLast line", '<p>HTML text</p>'],
        ];
        yield 'nested multipart' => [self::nested() . ".\r\n", self::nested(), null];
        yield 'dot-stuffed lines in a plain text' => [
            self::lines('Subject: Plain', 'Content-Type: text/plain', '', '..dot line', '..', 'Last line', '.'),
            self::lines('Subject: Plain', 'Content-Type: text/plain', '', '.dot line', '.', 'Last line'),
            [".dot line\r\n.\r\nLast line"],
        ];
        yield 'empty body' => [
            self::lines('Subject: Empty', '', '.'),
            self::lines('Subject: Empty', ''),
            [''],
        ];
        yield 'headers without an empty line' => [
            self::lines('Subject: Headers only', '.'),
            self::lines('Subject: Headers only', ''),
            [''],
        ];
    }

    /**
     * @return iterable<non-empty-string, array{\Closure(string): list<string>}>
     */
    public static function packetizations(): iterable
    {
        yield 'one packet' => [static fn(string $data): array => [$data]];
        yield 'byte by byte' => [static fn(string $data): array => \str_split($data)];
        yield 'line by line' => [
            static fn(string $data): array => \preg_split('/(?<=\n)/', $data, -1, \PREG_SPLIT_NO_EMPTY) ?: [],
        ];
        yield 'end line in a packet of its own' => [
            static fn(string $data): array => [\substr($data, 0, -3), \substr($data, -3)],
        ];
    }

    /**
     * @param \Closure(string): list<string> $packetize
     * @param list<string>|null $texts
     */
    #[DataCross(new DataProvider('messages'), new DataProvider('packetizations'))]
    #[Test]
    public function endsWithEndLine(string $data, string $raw, ?array $texts, \Closure $packetize): void
    {
        $this->assertParsed($packetize($data), $raw, $texts);
    }

    /**
     * Two packets split at every byte: the end line, a closing boundary or a stuffed dot may be cut anywhere.
     *
     * @param list<string>|null $texts
     */
    #[DataProvider('messages')]
    #[Test]
    public function endsWithEndLineSplitAnywhere(string $data, string $raw, ?array $texts): void
    {
        for ($at = 1; $at < \strlen($data); ++$at) {
            $this->assertParsed([\substr($data, 0, $at), \substr($data, $at)], $raw, $texts, "Split at $at: ");
        }
    }

    #[Test]
    public function closingBoundaryIsNotEndOfData(): void
    {
        $client = (new ClientScript())->send(self::alternative('Plain text') . "\r\n");
        $fiber = self::parse($client);

        $ended = $this->runFiberAtMost($fiber);
        $client->send(".\r\n");

        Assert::false($ended, 'The parser has taken the closing boundary for the end of data.');
        Assert::true($this->runFiberAtMost($fiber), self::HANG);
        Assert::same((string) $fiber->getReturn()->getBody(), self::alternative('Plain text') . "\r\n");
    }

    /**
     * The `354` reply pulls in the next packet: the rest of a header line is queued before the parser runs.
     */
    #[Test]
    public function readsHeaderLineSplitAcrossQueuedPackets(): void
    {
        $client = (new ClientScript())->send('Subject: Hea', "ders only\r\n\r\nBody\r\n.\r\n");
        $stream = StreamClientMock::createFromGenerator($client->generator());
        $stream->sendData("354 End data with <CR><LF>.<CR><LF>\r\n");
        $fiber = new \Fiber(static fn(): Message => (new Parser())->parseStream([], $stream));

        Assert::true($this->runFiberAtMost($fiber), self::HANG);
        Assert::same((string) $fiber->getReturn()->getBody(), "Subject: Headers only\r\n\r\nBody\r\n");
    }

    #[Test]
    public function leavesNextCommandToDispatcher(): void
    {
        $stream = StreamClientMock::createFromGenerator(
            (new ClientScript())->send(self::alternative('Plain text') . ".\r\n", "QUIT\r\n")->generator(),
        );
        $fiber = new \Fiber(static fn(): array => [
            (new Parser())->parseStream([], $stream),
            $stream->fetchLine(),
        ]);

        Assert::true($this->runFiberAtMost($fiber), self::HANG);
        Assert::same($fiber->getReturn()[1], "QUIT\r\n");
    }

    #[Test]
    public function keepsDataOfClientDisconnectedBeforeEndLine(): void
    {
        $fiber = self::parse((new ClientScript())->send(self::alternative('Plain text'))->close());

        Assert::true($this->runFiberAtMost($fiber), 'The parser waits for data from a closed connection.');
        Assert::same((string) $fiber->getReturn()->getBody(), self::alternative('Plain text'));
    }

    /**
     * A whole session with a client that waits for each reply, as Python smtplib does.
     */
    #[Test]
    #[Covers(Dispatcher::class)]
    public function repliesToMultipartMailOfWaitingClient(): void
    {
        $client = (new ClientScript())
            ->waitFor('220')
            ->send("EHLO client.test\r\n")->waitFor('250')
            ->send("MAIL FROM:<sender@example.com>\r\n")->waitFor('250')
            ->send("RCPT TO:<recipient@example.com>\r\n")->waitFor('250')
            ->send("DATA\r\n")->waitFor('354')
            ->send(self::alternative('Plain text') . ".\r\n")->waitFor('250')
            ->send("QUIT\r\n")->waitFor('221')
            ->close();
        $stream = StreamClientMock::createFromGenerator($client->generator());
        $fiber = new \Fiber(static fn(): array => \iterator_to_array((new Dispatcher())->dispatch($stream), false));

        $ended = $this->runFiberAtMost($fiber);

        Assert::true($ended, "The session hangs, the client has got:\n" . $client->received);
        Assert::count($fiber->getReturn(), 1);
        Assert::same(
            \array_map(static fn(string $line): string => \substr($line, 0, 3), \explode("\r\n", \trim($client->received))),
            // EHLO gets two lines: the greeting and the AUTH extension
            ['220', '250', '250', '250', '250', '354', '250', '221'],
        );
    }

    /**
     * @return \Fiber<mixed, mixed, Message, mixed>
     */
    private static function parse(ClientScript $client): \Fiber
    {
        $stream = StreamClientMock::createFromGenerator($client->generator());
        return new \Fiber(static fn(): Message => (new Parser())->parseStream([], $stream));
    }

    /**
     * A multipart/alternative message up to its closing boundary, as on the wire.
     */
    private static function alternative(string $plain): string
    {
        return self::lines(
            'From: sender@example.com',
            'To: recipient@example.com',
            'Subject: End of data',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="b1_trap"',
            '',
            '--b1_trap',
            'Content-Type: text/plain; charset=utf-8',
            '',
            $plain,
            '--b1_trap',
            'Content-Type: text/html; charset=utf-8',
            '',
            '<p>HTML text</p>',
            '--b1_trap--',
        );
    }

    /**
     * A multipart/mixed message with a multipart/alternative part and an attachment, up to its closing boundary.
     */
    private static function nested(): string
    {
        return self::lines(
            'From: sender@example.com',
            'To: recipient@example.com',
            'Subject: Nested',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="b1_mixed"',
            '',
            '--b1_mixed',
            'Content-Type: multipart/alternative; boundary="b2_alt"',
            '',
            '--b2_alt',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Plain text',
            '--b2_alt',
            'Content-Type: text/html; charset=utf-8',
            '',
            '<p>HTML text</p>',
            '--b2_alt--',
            '',
            '--b1_mixed',
            'Content-Type: text/plain; name=notes.txt',
            'Content-Disposition: attachment; filename=notes.txt',
            '',
            'Attached',
            '--b1_mixed--',
        );
    }

    private static function lines(string ...$lines): string
    {
        return \implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param list<string> $packets
     * @param list<string>|null $texts
     */
    private function assertParsed(array $packets, string $raw, ?array $texts, string $context = ''): void
    {
        $client = (new ClientScript())->send(...$packets);
        $stream = StreamClientMock::createFromGenerator($client->generator());
        $fiber = new \Fiber(static fn(): Message => (new Parser())->parseStream([], $stream));

        Assert::true($this->runFiberAtMost($fiber), $context . self::HANG);
        Assert::true(
            $client->isDone() && $stream->getData() === '',
            $context . 'The parser has stopped before the end of data, the rest would be read as commands.',
        );
        $message = $fiber->getReturn();
        Assert::same((string) $message->getBody(), $raw, $context . 'The stored message differs.');
        $texts === null or Assert::same(
            \array_map(static fn(Field $field): string => $field->getValue(), $message->getMessages()),
            $texts,
            $context . 'The text parts differ.',
        );
    }
}
