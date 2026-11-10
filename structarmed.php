<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

$sources = ['Mailer', 'Monolog', 'Profiler', 'Ray', 'Sentry', 'Tracing', 'VarDumper'];
$channels = ['Frontend'];

$architecture = Architecture::define()
    ->layer('Client', ['src/Client/', 'src/functions.php'])
    ->layer('Support', 'src/Support/')
    ->layer('Server', 'src/Server/');

foreach ([...$sources, ...$channels] as $module) {
    $architecture->layer($module, "src/Module/{$module}/");
}

return $architecture
    ->ruleset([
        'Client' => [],
        'Support' => [],
        'Server' => ['Support'],
        ...\array_fill_keys($channels, ['Server', 'Support']),
        ...\array_fill_keys($sources, ['Server', 'Support', ...$channels]),
    ])

    // The single place that names the module manifests: the module list.
    ->skipClassViolation('Buggregator\Trap\Server\Application\Module\Modules', [
        'Buggregator\Trap\Module\Mailer\Module',
        'Buggregator\Trap\Module\Monolog\Module',
        'Buggregator\Trap\Module\Profiler\Module',
        'Buggregator\Trap\Module\Ray\Module',
        'Buggregator\Trap\Module\Sentry\Module',
        'Buggregator\Trap\Module\Tracing\Module',
        'Buggregator\Trap\Module\VarDumper\Module',
    ])
    // Registration point: the server wires the Frontend channel by hand.
    ->skipClassViolation('Buggregator\Trap\Server\Application', [
        'Buggregator\Trap\Module\Frontend\Http\Pipeline',
        'Buggregator\Trap\Module\Frontend\Http\RequestHandler',
        'Buggregator\Trap\Module\Frontend\Sender\FrontendSender',
        'Buggregator\Trap\Module\Frontend\UiChannel',
    ]);
