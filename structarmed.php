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

    // Registration point: the core wires modules by hand until modules register themselves through a contract.
    ->skipClassViolation('Buggregator\Trap\Application', [
        'Buggregator\Trap\Module\Frontend\Http\Pipeline',
        'Buggregator\Trap\Module\Frontend\Http\RequestHandler',
        'Buggregator\Trap\Module\Frontend\Sender\FrontendSender',
        'Buggregator\Trap\Module\Mailer\Traffic\Dispatcher',
        'Buggregator\Trap\Module\Monolog\Traffic\Dispatcher',
        'Buggregator\Trap\Module\Profiler\Config\SPX',
        'Buggregator\Trap\Module\Profiler\Config\XDebug',
        'Buggregator\Trap\Module\Profiler\Config\XHProf',
        'Buggregator\Trap\Module\Profiler\Http\XDebugTrap',
        'Buggregator\Trap\Module\Profiler\Http\XHProfTrap',
        'Buggregator\Trap\Module\Ray\Http\RayRequestDump',
        'Buggregator\Trap\Module\Sentry\Http\SentryTrap',
        'Buggregator\Trap\Module\Tracing\Http\OtlpTrap',
        'Buggregator\Trap\Module\VarDumper\Traffic\Dispatcher',
    ])
    // Registration point: the sender registry builds the Mailer file sender and passes the Tracing span store.
    ->skipClassViolation('Buggregator\Trap\Command\Run', [
        'Buggregator\Trap\Module\Mailer\Sender\MailToFileSender',
        'Buggregator\Trap\Module\Tracing\SpanStore',
    ])
    // Registration point: frame types of the protocol.
    ->skipClassViolation('Buggregator\Trap\Proto\Server\Version\V1', [
        'Buggregator\Trap\Module\Mailer\Frame',
        'Buggregator\Trap\Module\Monolog\Frame',
        'Buggregator\Trap\Module\Profiler\Frame',
        'Buggregator\Trap\Module\Sentry\Frame',
        'Buggregator\Trap\Module\Tracing\Frame',
        'Buggregator\Trap\Module\VarDumper\Frame',
    ])
    // Registration point: the remote sender drops OTLP frames, which protocol version 1 receivers reject.
    ->skipClassViolation('Buggregator\Trap\Sender\RemoteSender', [
        'Buggregator\Trap\Module\Tracing\Frame',
    ])
    // Registration point: console renderers of the modules.
    ->skipClassViolation('Buggregator\Trap\Sender\ConsoleSender', [
        'Buggregator\Trap\Module\Mailer\Console\Renderer',
        'Buggregator\Trap\Module\Monolog\Console\Renderer',
        'Buggregator\Trap\Module\Profiler\Console\Renderer',
        'Buggregator\Trap\Module\Sentry\Console\SentryEnvelope',
        'Buggregator\Trap\Module\Sentry\Console\SentryStore',
        'Buggregator\Trap\Module\Tracing\Console\Renderer',
        'Buggregator\Trap\Module\Tracing\SpanStore',
        'Buggregator\Trap\Module\VarDumper\Console\Renderer',
    ])
    // Registration point: the files observer contract is typed with the Profiler frame.
    ->skipClassViolation('Buggregator\Trap\Service\FilesObserver\FrameConverter', [
        'Buggregator\Trap\Module\Profiler\Frame',
    ])
    // Registration point: frame-to-UI mappers of the source modules.
    ->skipClassViolation('Buggregator\Trap\Module\Frontend\FrameMapper', [
        'Buggregator\Trap\Module\Mailer\Frame',
        'Buggregator\Trap\Module\Mailer\Frontend\Mapper',
        'Buggregator\Trap\Module\Monolog\Frame',
        'Buggregator\Trap\Module\Monolog\Frontend\Mapper',
        'Buggregator\Trap\Module\Profiler\Frame',
        'Buggregator\Trap\Module\Profiler\Frontend\Mapper',
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
    ]);
