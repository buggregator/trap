<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\FinderConfig;
use Testo\Application\Config\SuiteConfig;
use Testo\Bridge\Mockery\MockeryPlugin;

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
    ],
    plugins: [
        new MockeryPlugin(),
    ],
);
