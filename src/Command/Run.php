<?php

declare(strict_types=1);

namespace Buggregator\Client\Command;

use Buggregator\Client\Bootstrap;
use Buggregator\Client\Logger;
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
        $this->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Port to listen', 9912);
        $this->addOption('sender', 's', InputOption::VALUE_OPTIONAL|InputOption::VALUE_IS_ARRAY, 'Senders', ['console']);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            $senders = $this->getSenders($output, (array)$input->getOption('sender'));

            Logger::debug('Selected senders: ' . \implode(', ', \array_map(fn(Sender $sender) => $sender::class, $senders)));

            /** @var int<1, max> $port */
            $port = (int)$input->getOption('port') ?: 9912;
            $bootstrap = new Bootstrap(
                new stdClass(),
                [
                    $port => [],
                ],
                $this->getSenders($output, (array)$input->getOption('sender'))
            );

            while (true) {
                $bootstrap->process();
                \usleep(50);
            }
        } catch (\Throwable $e) {
            // Write colorful exception (title, message, stacktrace)
            $output->writeln(\sprintf("<fg=red;options=bold>%s</>", $e::class));
            $output->writeln(\sprintf("<fg=red>%s</>", $e->getMessage()));
            $output->writeln(\sprintf("<fg=gray>%s</>", $e->getTraceAsString()));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<non-empty-string> $types
     * @return Sender[]
     */
    private function getSenders(OutputInterface $output, array $types): array
    {
        $senders = [];
        foreach ($types as $type) {
            $senders[] = match ($type) {
                'server' => new Sender\SaasSender(host: '127.0.0.1', port: 9099),
                'file' => new Sender\FileSender(),
                'console' => $this->createConsoleSender($output),
                default => throw new \InvalidArgumentException(\sprintf('Unknown sender type "%s"', $type)),
            };
        }

        return $senders;
    }

    private function createConsoleSender(OutputInterface $output): Sender
    {
        Termwind::renderUsing($output);

        $htmlRenderer = new HtmlRenderer();

        // Configure renderer
        $renderer = new ConsoleRenderer($output);
        $renderer->register(new Renderer\VarDumper());
        $renderer->register(new Renderer\Monolog($htmlRenderer));
        $renderer->register(new Renderer\Http());
        $renderer->register(new Renderer\Smtp());
        $renderer->register(new Renderer\Plain($htmlRenderer));

        return new Sender\ConsoleSender($renderer);
    }
}
