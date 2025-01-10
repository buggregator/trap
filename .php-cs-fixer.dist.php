<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

return \Spiral\CodeStyle\Builder::create()
    ->include(__DIR__ . '/bin')
    ->include(__DIR__ . '/src')
    ->include(__DIR__ . '/tests')
    ->include(__DIR__ . '/rector.php')
    ->include(__FILE__)
    ->exclude(__DIR__ . '/src/Test/Proto')
    ->build();
