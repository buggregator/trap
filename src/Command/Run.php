<?php

declare(strict_types=1);

namespace Buggregator\Client\Command;

use Buggregator\Client\Sender;
use Buggregator\Client\SocketServer;
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
            $senders = new Sender\SenderRegistry($output);
            $server = new SocketServer($senders);

            $port = (int)$input->getOption('port') ?: 9912;
            $senders = (array)$input->getOption('sender');

            $server->run($senders, $port);
        } catch (\Throwable $e) {
            // Write colorful exception (title, message, stacktrace)
            $output->writeln(\sprintf("<fg=red;options=bold>%s</>", $e::class));
            $output->writeln(\sprintf("<fg=red>%s</>", $e->getMessage()));
            $output->writeln(\sprintf("<fg=gray>%s</>", $e->getTraceAsString()));
        }

        return Command::SUCCESS;
    }
}
