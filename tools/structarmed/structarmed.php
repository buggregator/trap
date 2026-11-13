<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;

$root = \dirname(__DIR__, 2);
$sources = ['HttpDump', 'Mailer', 'Monolog', 'Profiler', 'Ray', 'Sentry', 'Tracing', 'VarDumper'];
$channels = ['Console', 'Files', 'Frontend', 'Remote'];

$architecture = Architecture::define()
    ->layer('Client', [$root . '/src/Client/', $root . '/src/functions.php'])
    ->layer('Support', $root . '/src/Support/')
    ->layer('Server', $root . '/src/Server/');

foreach ([...$sources, ...$channels] as $module) {
    $architecture->layer($module, "{$root}/src/Module/{$module}/");
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
        'Buggregator\Trap\Module\Console\Module',
        'Buggregator\Trap\Module\Files\Module',
        'Buggregator\Trap\Module\Frontend\Module',
        'Buggregator\Trap\Module\HttpDump\Module',
        'Buggregator\Trap\Module\Mailer\Module',
        'Buggregator\Trap\Module\Monolog\Module',
        'Buggregator\Trap\Module\Profiler\Module',
        'Buggregator\Trap\Module\Ray\Module',
        'Buggregator\Trap\Module\Remote\Module',
        'Buggregator\Trap\Module\Sentry\Module',
        'Buggregator\Trap\Module\Tracing\Module',
        'Buggregator\Trap\Module\VarDumper\Module',
    ]);
