<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Acceptance;

use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Tests\Acceptance\Support\SymfonyMailer;
use Buggregator\Trap\Tests\Acceptance\Support\TrapProcess;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Symfony Mailer (`tools/mailer`) sends mail to the SMTP server of `bin/trap` over TLS, on the same
 * port as plain SMTP: implicit TLS (SMTPS) and STARTTLS. Without `TRAP_TLS_CERT` and `TRAP_TLS_KEY`
 * the server has a self-signed certificate, so the client does not verify the peer.
 * Skipped when the tool or ext-openssl is not installed.
 */
#[Test]
final class SymfonyMailerTlsTest
{
    private const CERT = Info::TRAP_ROOT . '/tests/Stub/tls/localhost.crt';
    private const KEY = Info::TRAP_ROOT . '/tests/Stub/tls/localhost.key';
    private const MAIL = [
        'from' => 'sender@acceptance.test',
        'to' => ['Alice <alice@acceptance.test>'],
        'text' => 'Hello over TLS.',
    ];

    private ?TrapProcess $trap = null;
    private int $port = 0;

    #[BeforeTest]
    public function pickPort(): void
    {
        $this->trap = null;
        $this->port = TrapProcess::freePort();
    }

    #[AfterTest]
    public function stopTrap(): void
    {
        $this->trap?->stop();
        $this->trap?->removeWorkDir();
        $this->trap = null;
    }

    public function testImplicitTls(): void
    {
        self::requireTls();
        $trap = $this->startTrap('symfony-mailer-smtps');

        $result = SymfonyMailer::send(
            "smtps://127.0.0.1:$this->port?verify_peer=0",
            ['subject' => 'Symfony SMTPS subject'] + self::MAIL,
        );

        Assert::same($result['error'], null, "{$result['error']}\n{$result['debug']}");
        Assert::same($result['sent'], 1);
        $mail = $this->waitForDumpedMail($trap, 'Symfony SMTPS subject');
        Assert::same($mail['protocol']['BCC'], ['alice@acceptance.test']);
        Assert::string($mail['messages'][0]['value'])->contains('Hello over TLS.');
    }

    /**
     * The certificate from `TRAP_TLS_CERT` and `TRAP_TLS_KEY` is the one the server presents.
     */
    public function testImplicitTlsWithConfiguredCertificate(): void
    {
        self::requireTls();
        $trap = $this->startTrap('symfony-mailer-smtps-cert', ['TRAP_TLS_CERT' => self::CERT, 'TRAP_TLS_KEY' => self::KEY]);
        $fingerprint = \openssl_x509_fingerprint((string) \file_get_contents(self::CERT), 'sha1');

        $result = SymfonyMailer::send(
            "smtps://127.0.0.1:$this->port?verify_peer=0&peer_fingerprint=$fingerprint",
            ['subject' => 'Symfony SMTPS certificate subject'] + self::MAIL,
        );

        Assert::same($result['error'], null, "{$result['error']}\n{$result['debug']}");
        $this->waitForDumpedMail($trap, 'Symfony SMTPS certificate subject');
    }

    /**
     * The client upgrades the session by itself when `EHLO` advertises `STARTTLS`, and says `EHLO` again.
     * `require_tls` makes the client fail rather than send in plain text.
     */
    public function testStartTls(): void
    {
        self::requireTls();
        $trap = $this->startTrap('symfony-mailer-starttls');

        $result = SymfonyMailer::send(
            "smtp://127.0.0.1:$this->port?verify_peer=0&require_tls=1",
            ['subject' => 'Symfony STARTTLS subject'] + self::MAIL,
        );

        Assert::same($result['error'], null, "{$result['error']}\n{$result['debug']}");
        Assert::string($result['debug'])->contains('> STARTTLS')->matchesRegex('/> STARTTLS.*> EHLO/s');
        $mail = $this->waitForDumpedMail($trap, 'Symfony STARTTLS subject');
        Assert::same($mail['protocol']['FROM'], ['sender@acceptance.test']);
        Assert::same($mail['protocol']['BCC'], ['alice@acceptance.test']);
    }

    /**
     * A client that does not take the `STARTTLS` offered by the server goes on in plain text.
     */
    public function testPlainSessionWhenClientSkipsStartTls(): void
    {
        self::requireTls();
        $trap = $this->startTrap('symfony-mailer-no-auto-tls');

        $result = SymfonyMailer::send(
            "smtp://127.0.0.1:$this->port?auto_tls=false",
            ['subject' => 'Symfony no STARTTLS subject'] + self::MAIL,
        );

        Assert::same($result['error'], null, "{$result['error']}\n{$result['debug']}");
        Assert::string($result['debug'])->matchesRegex('/^(?:\[[^\]]*\] < )?250[ -]STARTTLS/m')->notContains('> STARTTLS');
        $this->waitForDumpedMail($trap, 'Symfony no STARTTLS subject');
    }

    /**
     * One server, one port: a plain session, an SMTPS one and a STARTTLS one, each mail caught.
     */
    public function testPlainAndTlsClientsOnSamePort(): void
    {
        self::requireTls();
        $trap = $this->startTrap('symfony-mailer-mixed');

        $plain = SymfonyMailer::send(
            "smtp://127.0.0.1:$this->port?auto_tls=false",
            ['subject' => 'Symfony mixed plain subject'] + self::MAIL,
        );
        $smtps = SymfonyMailer::send(
            "smtps://127.0.0.1:$this->port?verify_peer=0",
            ['subject' => 'Symfony mixed SMTPS subject'] + self::MAIL,
        );
        $startTls = SymfonyMailer::send(
            "smtp://127.0.0.1:$this->port?verify_peer=0&require_tls=1",
            ['subject' => 'Symfony mixed STARTTLS subject'] + self::MAIL,
        );

        Assert::same($plain['error'], null, "{$plain['error']}\n{$plain['debug']}");
        Assert::same($smtps['error'], null, "{$smtps['error']}\n{$smtps['debug']}");
        Assert::same($startTls['error'], null, "{$startTls['error']}\n{$startTls['debug']}");
        $this->waitForDumpedMail($trap, 'Symfony mixed plain subject');
        $this->waitForDumpedMail($trap, 'Symfony mixed SMTPS subject');
        $this->waitForDumpedMail($trap, 'Symfony mixed STARTTLS subject');
    }

    private static function requireTls(): void
    {
        SymfonyMailer::require();
        \extension_loaded('openssl') or throw new SkipTest('ext-openssl is not loaded.');
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

    /**
     * @param array<non-empty-string, string> $env
     */
    private function startTrap(string $name, array $env = []): TrapProcess
    {
        return $this->trap = TrapProcess::start(
            $name,
            ["--port=$this->port", '--sender=file'],
            ['TRAP_TCP_PORTS' => (string) $this->port, ...$env],
            [$this->port],
        );
    }
}
