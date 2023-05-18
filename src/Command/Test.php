<?php

declare(strict_types=1);

namespace Buggregator\Client\Command;

use Buggregator\Client\Logger;
use DateTimeImmutable;
use RuntimeException;
use Socket;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run application
 */
final class Test extends Command
{
    protected static $defaultName = 'test';

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        // $this->dump();
        // \usleep(100_000);
        $this->mail();

        return Command::SUCCESS;
    }

    private function dump(): void
    {
        $_SERVER['VAR_DUMPER_FORMAT'] = 'server';
        $_SERVER['VAR_DUMPER_SERVER'] = '127.0.0.1:9912';

        \dump(['foo' => 'bar']);
        \dump(123);
        \dump(new DateTimeImmutable());
    }

    private function mail(): void
    {
        try {
            $socket = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            \socket_connect($socket, '127.0.0.1', 9912);

            $this->sendMailPackage($socket, '', '220 ');
            $this->sendMailPackage($socket, "HELO\r\n", '250 ');
            $this->sendMailPackage($socket, "MAIL FROM: <someusername@foo.bar>\r\n", '250 ');
            // $this->sendMailPackage($socket, "RCPT TO: <user1@company.tld>\r\n", '250 ');
            // $this->sendMailPackage($socket, "RCPT TO: <user2@company.tld>\r\n", '250 ');
            $this->sendMailPackage($socket, "DATA\r\n", '354 ');
            // Data
            $this->sendMailPackage($socket, "From: Some User <someusername@somecompany.ru>\r\n", '');
            $this->sendMailPackage($socket, "To: User1 <user1@company.tld>", '');
            $this->sendMailPackage($socket, "\r\nSubject: tema\r\nContent-Type: text/plain\r\n\r\nHi!\r\n", '');
            $this->sendMailPackage($socket, ".\r\n", '250 ');
            // End of data
            $this->sendMailPackage($socket, "QUIT\r\n", '221 ');

            \socket_close($socket);

        } catch (\Throwable $e) {
            Logger::exception($e, 'Mail protocol error');
        }
    }

    private function sendMailPackage(Socket $socket, string $content, string $expectedResponsePrefix): void
    {
        if ($content !== '') {
            \socket_write($socket, $content);
            Logger::print('> "%s"', \str_replace(["\r", "\n"], ['\\r', '\\n'], $content));
        }

        if ($expectedResponsePrefix === '') {
            return;
        }
        \socket_recv($socket, $buf, 65536, 0);

        Logger::info('< "%s"', \str_replace(["\r", "\n"], ['\\r', '\\n'], $buf));

        $prefix = \substr($buf, 0, \strlen($expectedResponsePrefix));
        if ($prefix !== $expectedResponsePrefix) {
            throw new RuntimeException("Invalid response `$buf`. Prefix `$expectedResponsePrefix` expected.");
        }
    }
}
