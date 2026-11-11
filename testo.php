<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\FinderConfig;
use Testo\Application\Config\SuiteConfig;
use Testo\Bridge\Mockery\MockeryPlugin;

// Benchmarks take a minute: only a run that names their suite loads it, `composer test:bench`
/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
$bench = \in_array('--suite=Bench', $argv, true);

return new ApplicationConfig(
    src: new FinderConfig(
        include: ['src'],
        exclude: ['src/Test'],
    ),
    suites: [
        new SuiteConfig(
            name: 'Unit',
            location: ['tests/Unit'],
        ),
        new SuiteConfig(
            name: 'Arch',
            location: ['tests/Arch'],
        ),
        new SuiteConfig(
            name: 'Acceptance',
            location: ['tests/Acceptance'],
        ),
        ...($bench ? [new SuiteConfig(name: 'Bench', location: ['tests/Bench'])] : []),
    ],
    plugins: [
        new MockeryPlugin(),
    ],
);
