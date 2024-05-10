<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRules([
        '@PER-CS2.0' => true,
        '@PhpCsFixer' => true,
        '@PHP81Migration' => true,
        'new_with_parentheses' => [
            'anonymous_class' => false,
        ],
        'global_namespace_import' => [
            'import_constants' => false,
            'import_functions' => false,
            'import_classes' => false,
        ],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_line_span' => ['const' => 'multi', 'property' => 'multi', 'method' => 'multi'],
        'phpdoc_to_comment' => false,
        'single_line_comment_style' => ['comment_types' => ['hash']],
        'multiline_comment_opening_closing' => false,
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
    ])
    ->setRiskyAllowed(true)
    ->setFinder(
        (new Finder())
            ->files()
            ->name('*.php')
            ->in([__DIR__ . '/src'])
            ->exclude(['Test/Proto/']),
    )
    ->setCacheFile('.cache/.php-cs-fixer.cache')
;
