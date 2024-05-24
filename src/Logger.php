<?php

declare(strict_types=1);

namespace Buggregator\Trap;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console color logger
 *
 * @internal
 */
final class Logger
{
    private readonly bool $debug;

    private readonly bool $verbose;

    public function __construct(
        private readonly ?OutputInterface $output = null,
    ) {
        $this->debug = $output?->isVeryVerbose() ?? false;
        $this->verbose = $output?->isVerbose() ?? false;
    }

    public function print(string $message): void
    {
        $this->echo($message . "\n", false);
    }

    public function status(string $sender, string $message, string|int|float|bool ...$values): void
    {
        $this->echo("\033[47;1;30m " . $sender . " \033[0m " . \sprintf($message, ...$values) . "\n\n", false);
    }

    public function info(string $message, string|int|float|bool ...$values): void
    {
        $this->echo("\033[32m" . \sprintf($message, ...$values) . "\033[0m\n", !$this->verbose);
    }

    public function debug(string $message, string|int|float|bool ...$values): void
    {
        $this->echo("\033[34m" . \sprintf($message, ...$values) . "\033[0m\n");
    }

    public function error(string $message, string|int|float|bool ...$values): void
    {
        $this->echo("\033[31m" . \sprintf($message, ...$values) . "\033[0m\n");
    }

    public function exception(\Throwable $e, ?string $header = null, bool $important = false): void
    {
        $r = "----------------------\n";
        // Print bold yellow header if exists
        if ($header !== null) {
            $r .= "\033[1;33m" . $header . "\033[0m\n";
        }
        // Print exception message
        $r .= $e->getMessage() . "\n";
        // Print file and line using green color and italic font
        $r .= "In \033[3;32m" . $e->getFile() . ':' . $e->getLine() . "\033[0m\n";
        // Print stack trace using gray
        $r .= "Stack trace:\n";
        // Limit stacktrace to 5 lines
        $stack = \explode("\n", $e->getTraceAsString());
        $r .= "\033[90m" . \implode("\n", \array_slice($stack, 0, \min(5, \count($stack)))) . "\033[0m\n";
        $r .= "\n";
        $this->echo($r, !$important);
    }

    private function echo(string $message, bool $debug = true): void
    {
        if ($debug && !$this->debug) {
            return;
        }
        $this->output?->write($message);
    }
}
