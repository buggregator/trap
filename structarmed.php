<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

$sources = ['Mailer', 'Monolog', 'Profiler', 'Ray', 'Sentry', 'Tracing', 'VarDumper'];
$channels = ['Frontend'];

$architecture = Architecture::define()
    ->layer('Client', ['src/Client/', 'src/functions.php'])
    ->layer('Support', 'src/Support/')
    ->layer('Core', 'src/', ['src/Client/', 'src/functions.php', 'src/Support/', 'src/Module/']);

foreach ([...$sources, ...$channels] as $module) {
    $architecture->layer($module, "src/Module/{$module}/");
}

return $architecture
    ->ruleset([
        'Client' => [],
        'Support' => [],
        'Core' => ['Support'],
        ...\array_fill_keys($channels, ['Core', 'Support']),
        ...\array_fill_keys($sources, ['Core', 'Support', ...$channels]),
    ])

    // The single place that names the module manifests: the module list.
    ->skipClassViolation('Buggregator\Trap\Application\Module\Modules', [
        'Buggregator\Trap\Module\Mailer\Module',
        'Buggregator\Trap\Module\Monolog\Module',
        'Buggregator\Trap\Module\Profiler\Module',
        'Buggregator\Trap\Module\Ray\Module',
        'Buggregator\Trap\Module\Sentry\Module',
        'Buggregator\Trap\Module\Tracing\Module',
        'Buggregator\Trap\Module\VarDumper\Module',
    ])
    // Registration point: the core wires the Frontend channel by hand.
    ->skipClassViolation('Buggregator\Trap\Application', [
        'Buggregator\Trap\Module\Frontend\Http\Pipeline',
        'Buggregator\Trap\Module\Frontend\Http\RequestHandler',
        'Buggregator\Trap\Module\Frontend\Sender\FrontendSender',
        'Buggregator\Trap\Module\Frontend\UiChannel',
    ]);
