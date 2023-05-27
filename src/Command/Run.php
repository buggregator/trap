<?php

declare(strict_types=1);

namespace Buggregator\Client\Command;

use Buggregator\Client\Application;
use Buggregator\Client\Config\SocketServer;
use Buggregator\Client\Info;
use Buggregator\Client\Sender;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run application
 */
final class Run extends Command
{
    protected static $defaultName = 'run';

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

            $app = new Application(
                [new SocketServer($port)]
            );

            $app->run(
                senders: $registry->getSenders($senders)
            );
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
            new Sender\SaasSender(
                host: '127.0.0.1',
                port: 9099,
                clientVersion: Info::VERSION,
            )
        );

        return $registry;
    }
}
