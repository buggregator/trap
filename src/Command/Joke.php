<?php

declare(strict_types=1);

namespace Buggregator\Trap\Command;

use Buggregator\Trap\Info;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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
        trap(Info::JOKES[\array_rand(Info::JOKES)]);

        return Command::SUCCESS;
    }
}
