<?php

declare(strict_types=1);

namespace Buggregator\Trap\Command;

use Buggregator\Trap\Info;
use Buggregator\Trap\Logger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run application
 *
 * @internal
 */
#[AsCommand(
    name: 'test',
    description: 'Send test data',
)]
final class Test extends Command
{
    private string $addr = '127.0.0.1';

    private int $port = 9912;

    /** @psalm-suppress PropertyNotSetInConstructor */
    private Logger $logger;

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $this->logger = new Logger($output);

        // XHProf
        $this->sendContent('yii-xhprof.http');

        $this->dump();
        \usleep(100_000);
        $this->mail($output, true);
        \usleep(100_000);
        $this->mail($output, false);
        \usleep(100_000);
        $this->sendContent('sentry-store.http'); // Sentry Store very short
        $this->sendContent('sentry-store-2.http'); // Sentry Store full
        $this->sendContent('sentry-envelope.http'); // Sentry envelope
        \usleep(100_000);
        $this->sendContent('logo.png');


        return Command::SUCCESS;
    }

    private function dump(): void
    {
        $_SERVER['VAR_DUMPER_FORMAT'] = 'server';
        $_SERVER['VAR_DUMPER_SERVER'] = "$this->addr:$this->port";

        trap(['foo' => 'bar']);
        trap(123);
        trap(new \DateTimeImmutable());

        $message = (new \Buggregator\Trap\Test\Proto\Message())
            ->setId(123)
            ->setPayload('foo')
            ->setCommand('bar')
            ->setMainMetadata([
                (new \Buggregator\Trap\Test\Proto\Message\Metadata())
                    ->setKey('foo')
                    ->setValue('bar'),
            ])
            ->setHeader(
                (new \Buggregator\Trap\Test\Proto\Message\Header())
                    ->setKey('foo')
                    ->setValue('bar'),
            )
            ->setMapaMapa(['foo' => 'bar', 'baz' => 'qux', '2' => 'quuz', 'quux ff' => 'quuz'])
            ->setFoo(\Buggregator\Trap\Test\Proto\Message\Foo::BAR);
        trap(Nested: (object) ['msg' => $message]);

        try {
            $socket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            @\socket_connect($socket, $this->addr, $this->port);
            @\socket_write($socket, $message->serializeToString());
        } catch (\Throwable $e) {
            $this->logger->exception($e, "Proto sending error", important: true);
        } finally {
            if (isset($socket)) {
                @\socket_close($socket);
            }
        }
    }

    private function mail(OutputInterface $output, bool $multipart = false): void
    {
        try {
            $socket = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            \socket_connect($socket, $this->addr, $this->port);

            $this->sendMailPackage($output, $socket, '', '220 ');
            $this->sendMailPackage($output, $socket, "HELO\r\n", '250 ');
            $this->sendMailPackage($output, $socket, "MAIL FROM: <someusername@foo.bar>\r\n", '250 ');
            $this->sendMailPackage($output, $socket, "RCPT TO: <user1@company.tld>\r\n", '250 ');
            $this->sendMailPackage($output, $socket, "RCPT TO: <user2@company.tld>\r\n", '250 ');
            $this->sendMailPackage($output, $socket, "DATA\r\n", '354 ');

            // Send Data
            if ($multipart) {
                $this->sendMailPackage($output, $socket, "From: sender@example.com\r\n", '');
                $this->sendMailPackage($output, $socket, "To: recipient@example.com\r\n", '');
                $this->sendMailPackage($output, $socket, "Subject: Multipart Email Example\r\n", '');
                $this->sendMailPackage(
                    $output,
                    $socket,
                    "Content-Type: multipart/alternative; boundary=\"boundary-string\"\r\n",
                    '',
                );
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "--boundary-string\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Type: text/plain; charset=\"utf-8\"\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Transfer-Encoding: quoted-printable\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Disposition: inline\r\n", '');
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "Plain text email goes here!\r\n", '');
                $this->sendMailPackage(
                    $output,
                    $socket,
                    "This is the fallback if email client does not support HTML\r\n",
                    '',
                );
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "--boundary-string\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Type: text/html; charset=\"utf-8\"\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Transfer-Encoding: quoted-printable\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Disposition: inline\r\n", '');
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "<h1>This is the HTML Section!</h1>\r\n", '');
                $this->sendMailPackage(
                    $output,
                    $socket,
                    "<p>This is what displays in most modern email clients</p>\r\n",
                    '',
                );
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "--boundary-string--\r\n", '');
                // Attachment
                $this->sendMailPackage($output, $socket, "Content-Type: image/x-icon\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Transfer-Encoding: base64\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Disposition: attachment;filename=logo.ico\r\n", '');
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage(
                    $output,
                    $socket,
                    \base64_encode(\file_get_contents(Info::TRAP_ROOT . '/resources/public/favicon.ico')) . "\r\n",
                    '',
                );
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "--boundary-string--\r\n", '');

                $this->sendMailPackage($output, $socket, "Content-Type: text/watch-html; charset=\"utf-8\"\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Transfer-Encoding: quoted-printable\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Disposition: inline\r\n", '');
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "<b>Apple Watch formatted content</b>\r\n", '');
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "--boundary-string--\r\n", '');
                $this->sendMailPackage($output, $socket, "\r\n", '250 ');
            } else {
                $this->sendMailPackage($output, $socket, "From: Some User <someusername@somecompany.ru>\r\n", '');
                $this->sendMailPackage($output, $socket, "To: User1 <user1@company.tld>\r\n", '');
                $this->sendMailPackage($output, $socket, "Subject: Very important theme!\r\n", '');
                $this->sendMailPackage($output, $socket, "Content-Type: text/plain\r\n", '');
                $this->sendMailPackage($output, $socket, "\r\n", '');
                $this->sendMailPackage($output, $socket, "Hi!\r\n", '');
                $this->sendMailPackage($output, $socket, ".\r\n", '250 ');
            }
            // End of data
            $this->sendMailPackage($output, $socket, "QUIT\r\n", '221 ');

            \socket_close($socket);

        } catch (\Throwable $e) {
            $this->logger->exception($e, 'Mail protocol error', important: true);
        }
    }

    private function sendMailPackage(
        OutputInterface $output,
        \Socket $socket,
        string $content,
        string $expectedResponsePrefix,
    ): void {
        if ($content !== '') {
            \socket_write($socket, $content);
            // print green "hello" string in raw console markup
            $output->write(
                '> ' . \str_replace(["\r", "\n"], ["\e[32m\\r\e[0m", "\e[32m\\n\e[0m"], $content),
                true,
                OutputInterface::OUTPUT_RAW,
            );
        }

        if ($expectedResponsePrefix === '') {
            return;
        }
        @\socket_recv($socket, $buf, 65536, 0);
        /** @var string|null $buf */
        if ($buf === null) {
            $output->writeln('<error>Disconnected</>');
            return;
        }

        $output->write(
            \sprintf(
                "\e[33m< \"%s\"\e[0m",
                \str_replace(["\r", "\n"], ["\e[32m\\r\e[33m", "\e[32m\\n\e[33m"], $buf),
            ),
            true,
            OutputInterface::OUTPUT_RAW,
        );

        $prefix = \substr($buf, 0, \strlen($expectedResponsePrefix));
        if ($prefix !== $expectedResponsePrefix) {
            throw new \RuntimeException("Invalid response `$buf`. Prefix `$expectedResponsePrefix` expected.");
        }
    }

    /**
     * @param non-empty-string $file File from the {@link resources/payloads} directory
     */
    private function sendContent(string $file): void
    {
        try {
            $socket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            @\socket_connect($socket, $this->addr, $this->port);

            $fp = @\fopen(Info::TRAP_ROOT . '/resources/payloads/' . $file, 'rb');
            if ($fp === false) {
                throw new \RuntimeException('Cannot open file.');
            }
            @\flock($fp, LOCK_SH);
            while (!\feof($fp)) {
                $read = \fread($fp, 4096);
                @\socket_write($socket, $read);
            }

        } catch (\Throwable $e) {
            $this->logger->exception($e, "$file sending error", important: true);
        } finally {
            if (isset($fp)) {
                @\flock($fp, LOCK_UN);
                @\fclose($fp);
            }
            if (isset($socket)) {
                @\socket_close($socket);
            }
        }
    }
}
