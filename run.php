<?php

declare(strict_types=1);

namespace Buggregator\Client;

use stdClass;

include __DIR__ . '/vendor/autoload.php';

$bootstrap = new Bootstrap(
    new stdClass(),
    [
        ProtoType::VarDumper->value => ProtoType::VarDumper->getDefaultPort(),
    ],
);

while (true) {
    $bootstrap->process();
    \usleep(5_000);
}
