<?php

declare(strict_types=1);

namespace Buggregator\Trap\Command;

use Buggregator\Trap\Info;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
#[AsCommand(
    name: 'joke',
    description: 'Print a joke',
)]
final class Joke extends Command
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $jokes = \file(
            filename: Info::TRAP_ROOT . '/resources/registry/jokes.txt',
            flags: \FILE_IGNORE_NEW_LINES | \FILE_IGNORE_NEW_LINES,
        );
        $joke = \str_replace('%s', 'Buggregator', \base64_decode($jokes[\array_rand($jokes)]));

        trap($joke);

        return Command::SUCCESS;
    }
}
