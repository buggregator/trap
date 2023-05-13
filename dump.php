<?php

declare(strict_types=1);

namespace Buggregator\Client;

include __DIR__ . '/vendor/autoload.php';

$_SERVER['VAR_DUMPER_FORMAT'] = 'server';
$_SERVER['VAR_DUMPER_SERVER'] = '127.0.0.1:9912';

\dump(\getenv('VAR_DUMPER_FORMAT'));
\dump(['foo']);
