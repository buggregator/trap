<?php

declare(strict_types=1);

namespace Buggregator\Client\Command;

use Buggregator\Client\Bootstrap;
use Buggregator\Client\Sender\Console\ConsoleRenderer;
use Buggregator\Client\Sender\Console\Renderer;
use Buggregator\Client\Sender;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\HtmlRenderer;
use Termwind\Termwind;

/**
 * Run application
 */
final class Run extends Command
{
    protected static $defaultName = 'run';

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            Termwind::renderUsing($output);
            // Configure renderer
            $renderer = new ConsoleRenderer($output);
            $renderer->register(new Renderer\VarDumper());
            $renderer->register(new Renderer\Monolog(new HtmlRenderer()));
            $renderer->register(new Renderer\Plain());

            $bootstrap = new Bootstrap(
                new stdClass(),
                [
                    9912 => [],
                ],
                // todo configure senders using params
                new Sender\ConsoleSender($renderer)
                // new SocketSender('127.0.0.1', 9099),
                // new FileSender(),
            );

            while (true) {
                $bootstrap->process();
                \usleep(500);
            }
        } catch (\Throwable $e) {
            // Write colorful exception (title, message, stacktrace)
            $output->writeln(\sprintf("<fg=red;options=bold>%s</>", $e::class));
            $output->writeln(\sprintf("<fg=red>%s</>", $e->getMessage()));
            $output->writeln(\sprintf("<fg=gray>%s</>", $e->getTraceAsString()));
        }

        return Command::SUCCESS;
    }
}
