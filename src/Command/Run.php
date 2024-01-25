<?php

declare(strict_types=1);

namespace Buggregator\Trap\Command;

use Buggregator\Trap\Application;
use Buggregator\Trap\Config\SocketServer;
use Buggregator\Trap\Info;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Sender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run application
 *
 * @internal
 */
#[AsCommand(
    name: 'run',
    description: 'Run application',
)]
final class Run extends Command implements SignalableCommandInterface
{
    private ?Application $app = null;
    private bool $cancelled = false;

    public function configure(): void
    {
        $this->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Port to listen', 9912);
        $this->addOption(
            'sender',
            's',
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            'Senders',
            ['console']
        );
        $this->addOption('ui', null, InputOption::VALUE_OPTIONAL, 'Enable WEB UI (experimental)', false);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            // Print intro
            $output->writeln(\sprintf('<fg=yellow;options=bold>%s</> <info>v%s</>', Info::NAME, Info::VERSION));
            $output->write(Info::LOGO_CLI_COLOR . "\n", true, OutputInterface::OUTPUT_RAW);

            $port = (int)$input->getOption('port') ?: 9912;
            /** @var non-empty-string[] $senders */
            $senders = (array)$input->getOption('sender');

            $registry = $this->createRegistry($output);

            $this->app = new Application(
                [new SocketServer($port)],
                new Logger($output),
                senders: $registry->getSenders($senders),
                withFrontend: $input->getOption('ui') !== false,
            );

            $this->app->run();
        } catch (\Throwable $e) {
            if ($output->isVerbose()) {
                // Write colorful exception (title, message, stacktrace)
                $output->writeln(\sprintf("<fg=red;options=bold>%s</>", $e::class));
            }

            $output->writeln(\sprintf("<fg=red>%s</>", $e->getMessage()));

            if ($output->isDebug()) {
                $output->writeln(\sprintf("<fg=gray>%s</>", $e->getTraceAsString()));
            }
        }

        return Command::SUCCESS;
    }

    public function createRegistry(OutputInterface $output): Sender\SenderRegistry
    {
        $registry = new Sender\SenderRegistry();
        $registry->register('console', Sender\ConsoleSender::create($output));
        $registry->register('file', new Sender\FileSender());
        $registry->register(
            'server',
            new Sender\RemoteSender(
                host: '127.0.0.1',
                port: 9099,
            )
        );

        return $registry;
    }

    public function getSubscribedSignals(): array
    {
        $result = [];
        \defined('SIGINT') and $result[] = \SIGINT;
        \defined('SIGTERM') and $result[] = \SIGTERM;

        return $result;
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if (\defined('SIGINT') && $signal === \SIGINT) {
            if ($this->cancelled) {
                // Force exit
                $this->app?->destroy();
                return $signal;
            }

            $this->app?->cancel();
            $this->cancelled = true;
        }

        if (\defined('SIGTERM') && $signal === \SIGTERM) {
            $this->app?->destroy();
            return $signal;
        }

        return false;
    }
}
