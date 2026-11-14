# Monolog

Trap receives [Monolog](https://github.com/Seldaek/monolog) records sent over TCP as JSON, one record per line.
Send them with a `SocketHandler` and a `JsonFormatter`.

## Receive logs

Trap accepts the records on any of its ports; the default one is `9913`, the port of the Monolog socket handler in
Buggregator. Trap takes a connection as Monolog when its data starts with `{"message":`, which is how
`JsonFormatter` starts a record.

```php
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\SocketHandler;
use Monolog\Logger;

$handler = new SocketHandler('127.0.0.1:9913');
$handler->setFormatter(new JsonFormatter());

$logger = new Logger('app');
$logger->pushHandler($handler);
```

In Laravel, add a channel to `config/logging.php` and select it, e.g. with `LOG_CHANNEL=socket`:

```php
'socket' => [
    'driver' => 'monolog',
    'level' => env('LOG_LEVEL', 'debug'),
    'handler' => \Monolog\Handler\SocketHandler::class,
    'formatter' => \Monolog\Formatter\JsonFormatter::class,
    'handler_with' => [
        'connectionString' => env('LOG_SOCKET_URL', '127.0.0.1:9913'),
    ],
],
```

## Without Monolog

[`trap()::logger()`](functions.md#traplogger) is a PSR-3 logger that sends records in the same format,
with no Monolog in the project.
