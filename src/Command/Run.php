<?php

declare(strict_types=1);

namespace Buggregator\Trap\Command;

use Buggregator\Trap\Application;
use Buggregator\Trap\Config\Server\SocketServer;
use Buggregator\Trap\Info;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Sender;
use Buggregator\Trap\Service\Container;
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
        $this->addOption(
            'port',
            'p',
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            'Port to listen',
            [9912],
        );
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

            /**
             * Prepare port listeners
             * @var SocketServer[] $servers
             */
            $servers = [];
            $ports = $input->getOption('port') ?: [9912];
            \assert(\is_array($ports));
            foreach ($ports as $port) {
                \assert(\is_scalar($port));
                \is_numeric($port) or throw new \InvalidArgumentException(
                    \sprintf('Invalid port `%s`. It must be a number.', (string)$port),
                );
                $port = (int)$port;
                $port > 0 && $port < 65536 or throw new \InvalidArgumentException(
                    \sprintf('Invalid port `%s`. It must be in range 1-65535.', $port),
                );
                $servers[] = new SocketServer($port);
            }

            /** @var non-empty-string[] $senders */
            $senders = (array)$input->getOption('sender');

            $registry = $this->createRegistry($output);

            $container = new Container();
            $container->set($registry);
            $container->set($input, InputInterface::class);
            $container->set(new Logger($output));
            $this->app = $container->get(Application::class, [
                'map' => $servers,
                'senders' => $registry->getSenders($senders),
                'withFrontend' => $input->getOption('ui') !== false,
            ]);


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
