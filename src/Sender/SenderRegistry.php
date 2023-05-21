<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender;

use Buggregator\Client\Bootstrap;
use Buggregator\Client\Sender;
use Buggregator\Client\Sender\Console\ConsoleRenderer;
use Buggregator\Client\Sender\Console\Renderer;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\HtmlRenderer;
use Termwind\Termwind;

final class SenderRegistry
{
    /** @var array<non-empty-string, Sender> */
    private array $senders = [];

    public function __construct(
        private readonly OutputInterface $output,
    ) {
    }

    /**
     * @param non-empty-string $name
     */
    public function register(string $name, Sender $sender): void
    {
        $this->senders[$name] = $sender;
    }

    /**
     * @param non-empty-string[] $types
     * @return Sender[]
     */
    public function getSenders(array $types): array
    {
        /** @var array<non-empty-string, Sender> $available */
        $available = [
            'server' => new Sender\SaasSender(host: '127.0.0.1', port: 9099, clientVersion: Bootstrap::VERSION),
            'file' => new Sender\FileSender(),
            'console' => $this->createConsoleSender(),
        ];

        foreach ($this->senders as $name => $sender) {
            $available[$name] = $sender;
        }

        $senders = [];
        foreach ($types as $type) {
            if (!isset($available[$type])) {
                throw new \InvalidArgumentException(\sprintf('Unknown sender type "%s"', $type));
            }

            $senders[] = $available[$type];
        }

        return $senders;
    }


    private function createConsoleSender(): Sender\ConsoleSender
    {
        Termwind::renderUsing($this->output);

        $htmlRenderer = new HtmlRenderer();

        // Configure renderer
        $renderer = new ConsoleRenderer($this->output);
        $renderer->register(new Renderer\VarDumper());
        $renderer->register(new Renderer\Monolog($htmlRenderer));
        $renderer->register(new Renderer\Http());
        $renderer->register(new Renderer\Smtp());
        $renderer->register(new Renderer\Plain($htmlRenderer));

        return new Sender\ConsoleSender($renderer);
    }
}
