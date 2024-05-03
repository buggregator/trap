<?php

declare(strict_types=1);

namespace Buggregator\Trap\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print joke
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
        $jokes = \file(filename: 'resources/registry/jokes.txt', flags: \FILE_SKIP_EMPTY_LINES);
        $joke = base64_decode($jokes[\array_rand($jokes)]);
        \trap($joke);

        return Command::SUCCESS;
    }
}
