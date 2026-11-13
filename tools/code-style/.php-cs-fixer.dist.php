<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$root = \dirname(__DIR__, 2);

return \Spiral\CodeStyle\Builder::create()
    ->include($root . '/bin')
    ->include($root . '/src')
    ->include($root . '/tests')
    ->include($root . '/tools/rector/rector.php')
    ->include($root . '/tools/structarmed/structarmed.php')
    ->include(__FILE__)
    ->exclude($root . '/src/Test/Proto')
    ->build();
