<?php

declare(strict_types=1);

namespace Buggregator\Trap\Command;

use Buggregator\Trap\Application;
use Buggregator\Trap\Config\SocketServer;
use Buggregator\Trap\Cli\CliStartupSpeechBubble;
use Buggregator\Trap\Info;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Sender;
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
    name: 'joke',
    description: 'Print joke',
)]
final class Joke extends Command
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $output->write(
            "\n" . CliStartupSpeechBubble::getStartupSpeechBubble() . "\n",
            true,
            OutputInterface::OUTPUT_RAW
        );

        return Command::SUCCESS;
    }
}
