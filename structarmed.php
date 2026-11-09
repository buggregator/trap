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
    ])
    // Registration point: the sender registry builds the Mailer file sender and passes the Tracing span store.
    ->skipClassViolation('Buggregator\Trap\Command\Run', [
        'Buggregator\Trap\Module\Mailer\Sender\MailToFileSender',
        'Buggregator\Trap\Module\Tracing\SpanStore',
    ])
    // Registration point: the frame type registry maps wire types to the frame classes of the modules.
    ->skipClassViolation('Buggregator\Trap\Proto\FrameTypes', [
        'Buggregator\Trap\Module\Mailer\Frame',
        'Buggregator\Trap\Module\Monolog\Frame',
        'Buggregator\Trap\Module\Profiler\Frame',
        'Buggregator\Trap\Module\Ray\Frame',
        'Buggregator\Trap\Module\Sentry\Frame',
        'Buggregator\Trap\Module\Tracing\Frame',
        'Buggregator\Trap\Module\VarDumper\Frame',
    ])
    // Registration point: the remote sender drops OTLP and Ray frames, which protocol version 1 receivers reject.
    ->skipClassViolation('Buggregator\Trap\Sender\RemoteSender', [
        'Buggregator\Trap\Module\Ray\Frame',
        'Buggregator\Trap\Module\Tracing\Frame',
    ])
    // Registration point: console renderers of the modules.
    ->skipClassViolation('Buggregator\Trap\Sender\ConsoleSender', [
        'Buggregator\Trap\Module\Mailer\Console\Renderer',
        'Buggregator\Trap\Module\Monolog\Console\Renderer',
        'Buggregator\Trap\Module\Profiler\Console\Renderer',
        'Buggregator\Trap\Module\Ray\Console\Renderer',
        'Buggregator\Trap\Module\Sentry\Console\SentryEnvelope',
        'Buggregator\Trap\Module\Sentry\Console\SentryStore',
        'Buggregator\Trap\Module\Tracing\Console\Renderer',
        'Buggregator\Trap\Module\Tracing\SpanStore',
        'Buggregator\Trap\Module\VarDumper\Console\Renderer',
    ])
    // Registration point: frame-to-UI mappers of the source modules.
    ->skipClassViolation('Buggregator\Trap\Module\Frontend\FrameMapper', [
        'Buggregator\Trap\Module\Mailer\Frame',
        'Buggregator\Trap\Module\Mailer\Frontend\Mapper',
        'Buggregator\Trap\Module\Monolog\Frame',
        'Buggregator\Trap\Module\Monolog\Frontend\Mapper',
        'Buggregator\Trap\Module\Profiler\Frame',
        'Buggregator\Trap\Module\Profiler\Frontend\Mapper',
        'Buggregator\Trap\Module\Ray\Frame',
        'Buggregator\Trap\Module\Ray\Frontend\Mapper',
        'Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope',
        'Buggregator\Trap\Module\Sentry\Frame\SentryStore',
        'Buggregator\Trap\Module\Sentry\Frontend\EnvelopeMapper',
        'Buggregator\Trap\Module\Sentry\Frontend\StoreMapper',
        'Buggregator\Trap\Module\Tracing\Frame\OtlpTraces',
        'Buggregator\Trap\Module\VarDumper\Frame',
        'Buggregator\Trap\Module\VarDumper\Frontend\Mapper',
    ])
    // Registration point: UI endpoint controllers of the source modules.
    ->skipClassViolation('Buggregator\Trap\Module\Frontend\UiControllers', [
        'Buggregator\Trap\Module\Mailer\Frontend\ApiController',
        'Buggregator\Trap\Module\Mailer\Frontend\AssetsController',
        'Buggregator\Trap\Module\Profiler\Frontend\ApiController',
        'Buggregator\Trap\Module\Ray\Frontend\LocksController',
    ]);
