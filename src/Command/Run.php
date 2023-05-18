<?php

declare(strict_types=1);

namespace Buggregator\Client\Command;

use Buggregator\Client\Bootstrap;
use Buggregator\Client\Sender;
use Buggregator\Client\Sender\Console\ConsoleRenderer;
use Buggregator\Client\Sender\Console\Renderer;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\HtmlRenderer;
use Termwind\Termwind;

/**
 * Run application
 */
final class Run extends Command
{
    protected static $defaultName = 'run';

    public function configure(): void
    {
        $this->addArgument('sender', null, 'Sender type', 'console');
        $this->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Port to listen', 9912);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            /** @var int<1, max> $port */
            $port = (int)$input->getOption('port') ?: 9912;
            $bootstrap = new Bootstrap(
                new stdClass(),
                [
                    $port => [],
                ],
                $this->getSender($output, $input->getArgument('sender'))
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

    private function getSender(OutputInterface $output, string $type): Sender
    {
        return match ($type) {
            'socket' => new Sender\SocketSender('127.0.0.1', 9099),
            'file' => new Sender\FileSender(),
            default => $this->createConsoleSender($output),
        };
    }

    private function createConsoleSender(OutputInterface $output): Sender
    {
        Termwind::renderUsing($output);

        $htmlRenderer = new HtmlRenderer();

        // Configure renderer
        $renderer = new ConsoleRenderer($output);
        $renderer->register(new Renderer\VarDumper());
        $renderer->register(new Renderer\Monolog($htmlRenderer));
        $renderer->register(new Renderer\Http($htmlRenderer));
        $renderer->register(new Renderer\Plain($htmlRenderer));

        return new Sender\ConsoleSender($renderer);
    }
}
