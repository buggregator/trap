<?php

declare(strict_types=1);

namespace Buggregator\Trap\Command;

use Buggregator\Trap\Application;
use Buggregator\Trap\Bootstrap;
use Buggregator\Trap\Config\Server\SocketServer;
use Buggregator\Trap\Config\Server\TcpPorts;
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

    private Logger $logger;

    private bool $cancelled = false;

    public function configure(): void
    {
        $this->addOption(
            'port',
            'p',
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            'Port to listen',
        );
        $this->addOption(
            'sender',
            's',
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            'Senders',
            ['console'],
        );
        $this->addOption('ui', null, InputOption::VALUE_OPTIONAL, 'Enable WEB UI (experimental)', false);
    }

    /**
     * Prepare port listeners
     * @return SocketServer[]
     */
    public function getServers(Container $container): array
    {
        /** @var TcpPorts $config */
        $config = $container->get(TcpPorts::class);

        $servers = [];
        $ports = $config->ports;
        /** @var scalar $port */
        foreach ($ports as $port) {
            \is_numeric($port) or throw new \InvalidArgumentException(
                \sprintf('Invalid port `%s`. It must be a number.', (string) $port),
            );
            $port = (int) $port;
            $port > 0 && $port < 65536 or throw new \InvalidArgumentException(
                \sprintf('Invalid port `%s`. It must be in range 1-65535.', $port),
            );
            $servers[] = new SocketServer($port, $config->host, $config->type, $config->pollingInterval);
        }
        return $servers;
    }

    public function createRegistry(OutputInterface $output): Sender\SenderRegistry
    {
        $registry = new Sender\SenderRegistry();
        $registry->register('console', Sender\ConsoleSender::create($output));
        $registry->register('file', new Sender\EventsToFileSender());
        $registry->register('file-body', new Sender\BodyToFileSender());
        $registry->register(
            'server',
            new Sender\RemoteSender(
                host: '127.0.0.1',
                port: 9099,
            ),
        );

        return $registry;
    }

    public function getSubscribedSignals(): array
    {
        $result = [];
        /** @psalm-suppress MixedAssignment */
        \defined('SIGINT') and $result[] = \SIGINT;
        /** @psalm-suppress MixedAssignment */
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

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $this->logger = new Logger($output);
        try {
            // Print intro
            $output->writeln(\sprintf('<fg=yellow;options=bold>%s</> <info>v%s</>', Info::NAME, Info::version()));
            $output->write(Info::LOGO_CLI_COLOR . "\n", true, OutputInterface::OUTPUT_RAW);

            /** @var non-empty-string[] $senders */
            $senders = (array) $input->getOption('sender');

            $registry = $this->createRegistry($output);

            $container = Bootstrap::init()->withConfig(
                xml: \dirname(__DIR__, 2) . '/trap.xml',
                inputOptions: $input->getOptions(),
                inputArguments: $input->getArguments(),
                environment: \getenv(),
            )->finish();
            $container->set($registry);
            $container->set($input, InputInterface::class);
            $container->set($this->logger);
            $this->app = $container->get(Application::class, [
                'map' => $this->getServers($container),
                'senders' => $registry->getSenders($senders),
                'withFrontend' => $input->getOption('ui') !== false,
            ]);


            $this->app->run();
        } catch (\Throwable $e) {
            do {
                $this->logger->exception($e);
            } while ($e = $e->getPrevious());
        }

        return Command::SUCCESS;
    }
}
