<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRules([
        '@PER-CS2.0' => true,
        '@PHP81Migration' => true,
        'new_with_parentheses' => [
            'anonymous_class' => false,
        ],
    ])
    ->setRiskyAllowed(true)
    ->setFinder(
        (new Finder())
            ->files()
            ->name('*.php')
            ->in([__DIR__ . '/src'])
            ->exclude(['Test/Proto/']),
    )
    ->setCacheFile(__DIR__ . '/.build/php-cs-fixer/php-cs-fixer.cache');
