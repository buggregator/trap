<?php

declare(strict_types=1);

namespace Buggregator\Client;

use Buggregator\Client\Sender\SocketSender;
use stdClass;

include __DIR__ . '/vendor/autoload.php';

$bootstrap = new Bootstrap(
    new stdClass(),
    [
        9912 => [],
    ],
    new SocketSender('127.0.0.1', 9099),
);

while (true) {
    $bootstrap->process();
    \usleep(500);
}
