<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Command;

use Buggregator\Trap\Command\Run;
use Buggregator\Trap\Module\Mailer\Sender\MailToFileSender;
use Buggregator\Trap\Sender\BodyToFileSender;
use Buggregator\Trap\Sender\ConsoleSender;
use Buggregator\Trap\Sender\EventsToFileSender;
use Buggregator\Trap\Sender\RemoteSender;
use Internal\Container\ObjectContainer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Run::class)]
final class RunTest
{
    public function testStartupErrorIsReportedAndFails(): void
    {
        $tester = new CommandTester(new Run());

        $exitCode = $tester->execute(['--sender' => ['nosuch']]);

        Assert::same($exitCode, Command::FAILURE);
        Assert::string($tester->getDisplay())->contains('Unknown sender type `nosuch`.');
    }

    public function testRegistryHasCoreAndModuleSendersByName(): void
    {
        $cwd = \getcwd();
        $dir = \sys_get_temp_dir() . '/trap-run-test-' . \bin2hex(\random_bytes(4));
        \mkdir($dir);
        // The file senders create their directories in the working directory.
        \chdir($dir);
        try {
            $senders = (new Run())->createRegistry(new BufferedOutput(), new ObjectContainer())
                ->getSenders(['console', 'file', 'file-body', 'mail-to-file', 'server']);

            Assert::same(\array_map(static fn(object $sender): string => $sender::class, $senders), [
                ConsoleSender::class,
                EventsToFileSender::class,
                BodyToFileSender::class,
                MailToFileSender::class,
                RemoteSender::class,
            ]);
        } finally {
            \chdir((string) $cwd);
            self::remove($dir);
        }
    }

    public function testRejectsUnknownSender(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Unknown sender type `nosuch`.');

        (new Run())->createRegistry(new BufferedOutput(), new ObjectContainer())->getSenders(['nosuch']);
    }

    private static function remove(string $path): void
    {
        if (\is_dir($path)) {
            foreach (\scandir($path) ?: [] as $item) {
                $item === '.' || $item === '..' or self::remove("$path/$item");
            }
            \rmdir($path);
            return;
        }

        \unlink($path);
    }
}
