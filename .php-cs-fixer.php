<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRules([
        '@PER-CS2.0' => true,
        '@PHP81Migration' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder(
        (new Finder())
            ->files()
            ->name('*.php')
            ->in([__DIR__ . '/src'])
        ->exclude([__DIR__ . '/src/Test']),
    )
    ->setCacheFile('.cache/.php-cs-fixer.cache');
