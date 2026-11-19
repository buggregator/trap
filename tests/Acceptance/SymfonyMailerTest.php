<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Acceptance;

use Buggregator\Trap\Tests\Acceptance\Support\SymfonyMailer;
use Buggregator\Trap\Tests\Acceptance\Support\TrapProcess;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Skip;
use Testo\Test;

/**
 * Symfony Mailer (`tools/mailer`) sends mail to the SMTP server of `bin/trap` over plain TCP.
 * Each test checks the SMTP session went through, then what the `file` sender wrote and what
 * the web UI shows. Skipped when the tool is not installed.
 */
#[Test]
final class SymfonyMailerTest
{
    private ?TrapProcess $trap = null;
    private int $port = 0;
    private int $uiPort = 0;

    #[BeforeTest]
    public function pickPorts(): void
    {
        $this->trap = null;
        $this->port = TrapProcess::freePort();
        $this->uiPort = TrapProcess::freePort();
    }

    #[AfterTest]
    public function stopTrap(): void
    {
        $this->trap?->stop();
        $this->trap?->removeWorkDir();
        $this->trap = null;
    }

    public function testPlainTextMail(): void
    {
        SymfonyMailer::require();
        $trap = $this->startTrap('symfony-mailer-plain');

        $result = SymfonyMailer::send("smtp://127.0.0.1:$this->port", [
            'from' => 'Sender <sender@acceptance.test>',
            'to' => ['Alice <alice@acceptance.test>'],
            'subject' => 'Symfony plain subject',
            'text' => 'Hello from Symfony Mailer.',
        ]);

        Assert::same($result['error'], null, "{$result['error']}\n{$result['debug']}");
        Assert::same($result['sent'], 1);
        $mail = $this->waitForDumpedMail($trap, 'Symfony plain subject');
        Assert::same($mail['protocol']['FROM'], ['sender@acceptance.test']);
        Assert::same($mail['protocol']['BCC'], ['alice@acceptance.test']);
        Assert::same($mail['attachments'], []);
        $event = $trap->waitForUiEvent($this->uiPort, 'smtp');
        Assert::same($event['payload']['subject'], 'Symfony plain subject');
        Assert::array($event['payload']['from'])->contains(['name' => 'Sender', 'email' => 'sender@acceptance.test']);
        Assert::same($event['payload']['to'], [['name' => 'Alice', 'email' => 'alice@acceptance.test']]);
        Assert::string($event['payload']['text'])->contains('Hello from Symfony Mailer.');
        Assert::same($event['payload']['html'], '');
    }

    /**
     * To, Cc and Bcc recipients come as RCPTs, and only To and Cc are in the headers.
     */
    public function testSeveralRecipients(): void
    {
        SymfonyMailer::require();
        $trap = $this->startTrap('symfony-mailer-recipients');

        $result = SymfonyMailer::send("smtp://127.0.0.1:$this->port", [
            'from' => 'sender@acceptance.test',
            'to' => ['Alice <alice@acceptance.test>', 'bob@acceptance.test'],
            'cc' => ['Carol <carol@acceptance.test>'],
            'bcc' => ['dave@acceptance.test'],
            'subject' => 'Symfony recipients subject',
            'text' => 'Hello, everyone.',
        ]);

        Assert::same($result['error'], null, "{$result['error']}\n{$result['debug']}");
        $mail = $this->waitForDumpedMail($trap, 'Symfony recipients subject');
        Assert::array($mail['protocol']['BCC'])->sameElementsAs([
            'alice@acceptance.test',
            'bob@acceptance.test',
            'carol@acceptance.test',
            'dave@acceptance.test',
        ]);
        Assert::false(isset($mail['headers']['Bcc']));
        $event = $trap->waitForUiEvent($this->uiPort, 'smtp');
        Assert::same($event['payload']['to'], [
            ['name' => 'Alice', 'email' => 'alice@acceptance.test'],
            ['name' => null, 'email' => 'bob@acceptance.test'],
        ]);
        Assert::same($event['payload']['cc'], [['name' => 'Carol', 'email' => 'carol@acceptance.test']]);
        Assert::array(\array_column($event['payload']['bcc'], 'email'))->contains('dave@acceptance.test');
    }

    /**
     * Text and HTML alternatives: a `multipart/alternative` message.
     */
    public function testTextAndHtmlAlternatives(): void
    {
        SymfonyMailer::require();
        $trap = $this->startTrap('symfony-mailer-alternative');

        $result = SymfonyMailer::send("smtp://127.0.0.1:$this->port", [
            'from' => 'sender@acceptance.test',
            'to' => ['alice@acceptance.test'],
            'subject' => 'Symfony alternative subject',
            'text' => 'Plain alternative.',
            'html' => '<p>HTML alternative.</p>',
        ]);

        Assert::same($result['error'], null, "{$result['error']}\n{$result['debug']}");
        $mail = $this->waitForDumpedMail($trap, 'Symfony alternative subject');
        Assert::string($mail['headers']['Content-Type'][0])->startsWith('multipart/alternative');
        Assert::same($mail['attachments'], []);
        $event = $trap->waitForUiEvent($this->uiPort, 'smtp');
        Assert::string($event['payload']['text'])->contains('Plain alternative.');
        Assert::string($event['payload']['html'])->contains('<p>HTML alternative.</p>');
    }

    /**
     * A text with a file attached: a `multipart/mixed` message.
     */
    public function testTextWithAttachment(): void
    {
        SymfonyMailer::require();
        $trap = $this->startTrap('symfony-mailer-attachment');
        $attachment = \str_repeat("acceptance attachment line\n", 200);

        $result = SymfonyMailer::send("smtp://127.0.0.1:$this->port", [
            'from' => 'sender@acceptance.test',
            'to' => ['alice@acceptance.test'],
            'subject' => 'Symfony attachment subject',
            'text' => 'See the report attached.',
            'attachments' => [['name' => 'report.txt', 'content' => $attachment, 'type' => 'text/plain']],
        ]);

        Assert::same($result['error'], null, "{$result['error']}\n{$result['debug']}");
        $mail = $this->waitForDumpedMail($trap, 'Symfony attachment subject');
        Assert::string($mail['headers']['Content-Type'][0])->startsWith('multipart/mixed');
        Assert::count($mail['attachments'], 1);
        Assert::same($mail['attachments'][0]['fileName'], 'report.txt');
        Assert::same($mail['attachments'][0]['size'], \strlen($attachment));
        $event = $trap->waitForUiEvent($this->uiPort, 'smtp');
        Assert::string($event['payload']['text'])->contains('See the report attached.');
    }

    /**
     * Text and HTML alternatives with a file attached: a `multipart/mixed` message holding
     * a `multipart/alternative` one, the way Symfony Mailer builds it.
     */
    public function testTextAndHtmlWithAttachment(): void
    {
        SymfonyMailer::require();
        $trap = $this->startTrap('symfony-mailer-multipart');

        $result = SymfonyMailer::send("smtp://127.0.0.1:$this->port", [
            'from' => 'sender@acceptance.test',
            'to' => ['alice@acceptance.test'],
            'subject' => 'Symfony multipart subject',
            'text' => 'Plain alternative.',
            'html' => '<p>HTML alternative.</p>',
            'attachments' => [['name' => 'report.txt', 'content' => 'report', 'type' => 'text/plain']],
        ]);

        Assert::same($result['error'], null, "{$result['error']}\n{$result['debug']}");
        $mail = $this->waitForDumpedMail($trap, 'Symfony multipart subject');
        Assert::same(\array_column($mail['attachments'], 'fileName'), ['report.txt']);
        $event = $trap->waitForUiEvent($this->uiPort, 'smtp');
        Assert::string($event['payload']['text'])->contains('Plain alternative.');
        Assert::string($event['payload']['html'])->contains('<p>HTML alternative.</p>');
    }

    /**
     * Symfony Mailer encodes a non-ASCII subject as RFC 2047 words and a non-ASCII text as quoted-printable,
     * and quotes a display name holding a comma.
     */
    #[Skip('Trap decodes neither RFC 2047 headers nor quoted-printable bodies, and splits a quoted name at its comma')]
    public function testNonAsciiMail(): void
    {
        SymfonyMailer::require();
        $trap = $this->startTrap('symfony-mailer-non-ascii');

        $result = SymfonyMailer::send("smtp://127.0.0.1:$this->port", [
            'from' => 'sender@acceptance.test',
            'to' => ['"Doe, John" <john@acceptance.test>'],
            'subject' => 'Привет, мир',
            'text' => 'Grüße aus Symfony Mailer.',
        ]);

        Assert::same($result['error'], null, "{$result['error']}\n{$result['debug']}");
        $event = $trap->waitForUiEvent($this->uiPort, 'smtp');
        Assert::same($event['payload']['subject'], 'Привет, мир');
        Assert::same($event['payload']['to'], [['name' => 'Doe, John', 'email' => 'john@acceptance.test']]);
        Assert::string($event['payload']['text'])->contains('Grüße aus Symfony Mailer.');
    }

    /**
     * Symfony Mailer encodes a non-ASCII subject and display name as RFC 2047 words, quotes a display name
     * holding a comma, and sends the address of `From` as the envelope sender too.
     */
    public function testEncodedHeaders(): void
    {
        SymfonyMailer::require();
        $trap = $this->startTrap('symfony-mailer-encoded-headers');

        $result = SymfonyMailer::send("smtp://127.0.0.1:$this->port", [
            'from' => 'Отправитель <sender@acceptance.test>',
            'to' => ['"Doe, John" <john@acceptance.test>', 'Иван Петров <ivan@acceptance.test>'],
            'subject' => 'Привет, мир',
            'text' => 'Hello.',
        ]);

        Assert::same($result['error'], null, "{$result['error']}\n{$result['debug']}");
        $event = $trap->waitForUiEvent($this->uiPort, 'smtp');
        Assert::same($event['payload']['subject'], 'Привет, мир');
        Assert::same($event['payload']['from'], [['name' => 'Отправитель', 'email' => 'sender@acceptance.test']]);
        Assert::same($event['payload']['to'], [
            ['name' => 'Doe, John', 'email' => 'john@acceptance.test'],
            ['name' => 'Иван Петров', 'email' => 'ivan@acceptance.test'],
        ]);
    }

    /**
     * One connection, several messages: the server takes each one after the previous.
     */
    public function testSeveralMailsInOneSession(): void
    {
        SymfonyMailer::require();
        $trap = $this->startTrap('symfony-mailer-session');

        $result = SymfonyMailer::send(
            "smtp://127.0.0.1:$this->port",
            [
                'from' => 'sender@acceptance.test',
                'to' => ['alice@acceptance.test'],
                'subject' => 'Symfony first subject',
                'text' => 'First.',
            ],
            [
                'from' => 'sender@acceptance.test',
                'to' => ['bob@acceptance.test'],
                'subject' => 'Symfony second subject',
                'html' => '<b>Second.</b>',
            ],
        );

        Assert::same($result['error'], null, "{$result['error']}\n{$result['debug']}");
        Assert::same($result['sent'], 2);
        Assert::same($this->waitForDumpedMail($trap, 'Symfony first subject')['protocol']['BCC'], ['alice@acceptance.test']);
        Assert::same($this->waitForDumpedMail($trap, 'Symfony second subject')['protocol']['BCC'], ['bob@acceptance.test']);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function waitForDumpedMail(TrapProcess $trap, string $subject): array
    {
        return $trap->waitForDumpedLine(
            static fn(string $line): ?array => \str_contains($line, $subject)
                ? (array) \json_decode($line, true, 512, \JSON_THROW_ON_ERROR)
                : null,
            "the mail `$subject` in the dump file",
        );
    }

    private function startTrap(string $name): TrapProcess
    {
        return $this->trap = TrapProcess::start(
            $name,
            ["--port=$this->port", '--sender=file', "--ui=$this->uiPort"],
            ['TRAP_TCP_PORTS' => (string) $this->port, 'TRAP_UI_PORT' => (string) $this->uiPort],
            [$this->port, $this->uiPort],
        );
    }
}
