<?php

declare(strict_types=1);

namespace Buggregator\Client;

use Buggregator\Client\Sender\ConsoleSender;
use Buggregator\Client\Sender\SocketSender;
use Buggregator\Client\Traffic\Console\ConsoleRenderer;
use Buggregator\Client\Traffic\Console\Renderer\MonologRenderer;
use Buggregator\Client\Traffic\Console\Renderer\VarDumperRenderer;
use stdClass;
use Symfony\Component\Console\Output\ConsoleOutput;
use Termwind\HtmlRenderer;
use Termwind\Termwind;

include __DIR__ . '/vendor/autoload.php';


$output = new ConsoleOutput();
Termwind::renderUsing($output);

$htmlRenderer = new HtmlRenderer();

$renderer = new ConsoleRenderer($output);
$renderer->register(new VarDumperRenderer());
$renderer->register(new MonologRenderer($htmlRenderer));

$bootstrap = new Bootstrap(
    new stdClass(),
    [
        9912 => [],
    ],
    new ConsoleSender($renderer)
    //new SocketSender('127.0.0.1', 9099),
);

while (true) {
    $bootstrap->process();
    \usleep(500);
}
