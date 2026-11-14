<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Command;

use Buggregator\Trap\Server\Application;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Bootstrap;
use Buggregator\Trap\Server\Config\App;
use Buggregator\Trap\Server\Config\SocketServer;
use Buggregator\Trap\Server\Config\TcpPorts;
use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Sender;
use Buggregator\Trap\Support\MemoryLimit;
use Internal\Container\Container;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run application
 *
 * Examples:
 *
 * ```
 *  # Run with default ports and console sender
 *  trap run
 *
 *  # Run with UI on port 8080, listen default ports, and use console sender
 *  trap run --ui=8080
 *
 *  # Run with UI, disable console sender
 *  trap run --ui=8080 -s
 *  # Equals to
 *  trap run --ui=8080 --sender=""
 * ```
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

    #[\Override]
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
            $servers[] = new SocketServer($port, $config->host, $config->protocol);
        }
        return $servers;
    }

    public function createRegistry(Container $container): Sender\SenderRegistry
    {
        $registry = new Sender\SenderRegistry();

        /** @var array<non-empty-string, class-string> $owners Who gave the sender of each name */
        $owners = [];
        foreach ($container->get(Modules::class)->implementing(Sender\ProvidesSenders::class) as $module) {
            foreach ($module->senders() as $name => $class) {
                isset($owners[$name]) and throw new \LogicException(
                    \sprintf('Sender `%s` of %s is already given by %s.', $name, $module::class, $owners[$name]),
                );
                $owners[$name] = $module::class;
                $registry->register($name, static fn(): Sender => $container->get($class));
            }
        }

        return $registry;
    }

    #[\Override]
    public function getSubscribedSignals(): array
    {
        $result = [];
        /** @psalm-suppress MixedAssignment */
        \defined('SIGINT') and $result[] = \SIGINT;
        /** @psalm-suppress MixedAssignment */
        \defined('SIGTERM') and $result[] = \SIGTERM;

        return $result;
    }

    #[\Override]
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

    #[\Override]
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
            $senders = \array_filter((array) $input->getOption('sender'));
            $input->getOption('ui') === false or $senders[] = 'ui';

            $container = Bootstrap::init()->withConfig(
                xml: \dirname(__DIR__, 3) . '/trap.xml',
                inputOptions: $input->getOptions(),
                inputArguments: $input->getArguments(),
                environment: \getenv(),
            )->finish();
            $limit = $container->get(App::class)->memoryLimit;
            MemoryLimit::raise($limit) and $this->logger->info('Memory limit raised to %s.', $limit);
            $container->set($input, InputInterface::class);
            $container->set($output, OutputInterface::class);
            $container->set($this->logger);
            $registry = $this->createRegistry($container);
            $container->set($registry);
            $this->app = $container->get(Application::class, [
                'map' => $this->getServers($container),
                'senders' => $registry->getSenders(\array_values(\array_unique($senders))),
            ]);


            $this->app->run();
        } catch (\Throwable $e) {
            do {
                $this->logger->exception($e, important: true);
            } while ($e = $e->getPrevious());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
