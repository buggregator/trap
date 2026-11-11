<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Command;

use Buggregator\Trap\Module\Console\ConsoleSender;
use Buggregator\Trap\Module\Console\Module as ConsoleModule;
use Buggregator\Trap\Module\Files\BodyToFileSender;
use Buggregator\Trap\Module\Files\EventsToFileSender;
use Buggregator\Trap\Module\Frontend\Sender\FrontendSender;
use Buggregator\Trap\Module\Mailer\Sender\MailToFileSender;
use Buggregator\Trap\Module\Remote\RemoteSender;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Command\Run;
use Buggregator\Trap\Tests\Unit\Server\Command\Stub\TakingConsoleSender;
use Internal\Container\ObjectContainer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
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

    public function testRegistryHasSendersOfChannelModulesByName(): void
    {
        $cwd = \getcwd();
        $dir = \sys_get_temp_dir() . '/trap-run-test-' . \bin2hex(\random_bytes(4));
        \mkdir($dir);
        // The file senders create their directories in the working directory.
        \chdir($dir);
        try {
            $container = new ObjectContainer();
            $container->set(new BufferedOutput(), OutputInterface::class);
            $senders = (new Run())->createRegistry($container)
                ->getSenders(['console', 'file', 'file-body', 'server', 'ui', 'mail-to-file']);

            Assert::same(\array_map(static fn(object $sender): string => $sender::class, $senders), [
                ConsoleSender::class,
                EventsToFileSender::class,
                BodyToFileSender::class,
                RemoteSender::class,
                FrontendSender::class,
                MailToFileSender::class,
            ]);
        } finally {
            \chdir((string) $cwd);
            self::remove($dir);
        }
    }

    public function testRejectsUnknownSender(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Unknown sender type `nosuch`.');

        (new Run())->createRegistry(new ObjectContainer())->getSenders(['nosuch']);
    }

    public function testModuleCannotTakeSenderNameOfAnother(): void
    {
        $container = new ObjectContainer();
        $container->set($container->make(Modules::class, ['list' => [ConsoleModule::class, TakingConsoleSender::class]]));

        Expect::exception(\LogicException::class)->withMessageContaining(\sprintf(
            'Sender `console` of %s is already given by %s.',
            TakingConsoleSender::class,
            ConsoleModule::class,
        ));

        (new Run())->createRegistry($container);
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
