<p align="center">
    <img alt="logo"
         src="https://github.com/buggregator/trap/blob/master/resources/payloads/logo.png?raw=true"
         style="width: 3in; display: block"
    />
</p>
<p align="center">Enough dumping. Let's trap!</p>
<h1 align="center">Buggregator Trap</h1>

<div align="center">

[![Documentation](https://img.shields.io/badge/Documentation-blue?style=for-the-badge&logo=gitbook&logoColor=white)](https://docs.buggregator.dev/trap/what-is-trap.html)
[![Sponsor](https://img.shields.io/static/v1?style=for-the-badge&label=&message=Sponsor&logo=boosty&logoColor=white&color=%23F15F2C)](https://boosty.to/roxblnfk)

[![Psalm Level](https://shepherd.dev/github/buggregator/trap/level.svg)](https://shepherd.dev/github/buggregator/trap)
[![Type Coverage](https://shepherd.dev/github/buggregator/trap/coverage.svg)](https://shepherd.dev/github/buggregator/trap)
[![Codecov](https://img.shields.io/codecov/c/github/buggregator/trap/master?style=flat&logo=codecov)](https://codecov.io/gh/buggregator/trap)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fbuggregator%2Ftrap%2Fmaster)](https://dashboard.stryker-mutator.io/reports/github.com/buggregator/trap/master)

[![Awesome PHP](https://awesome.re/mentioned-badge-flat.svg)](https://github.com/ziadoz/awesome-php#debugging-and-profiling)
[![Twitter](https://img.shields.io/badge/-Follow-black?style=flat-square&logo=X)](https://twitter.com/buggregator)
[![Discord](https://img.shields.io/static/v1?style=flat-square&label=Join&message=Discord&logo=Discord&color=%235865F2)](https://discord.gg/qF3HpXhMEP)

</div>

<br />

```diff
- dump($order);
+ trap($order);
```

`trap()` is `dump()` that:

- sends the dump to the local Trap server or to a Buggregator Server, and prints it in place when no server is
  listening;
- dumps only what you need: `->depth(3)`, `->times(5)`, `->once()`, `->if($cond)`;
- carries context with the dump: `->context(...)`, `->code('sql')`;
- fits into expressions: `->return()`, `tr()`, `td()`;
- dumps protobuf messages in a readable form.

**Trap** is a debug server and a set of debug functions for PHP. The server is a mini
[Buggregator Server](https://github.com/buggregator/server) written in PHP and made for local development: it runs
on the PHP of your project, so it reads the profiles of XHProf, Xdebug and SPX right from their directories.
Unlike the `symfony/var-dumper` server, it receives far more than dumps: Monolog logs, Sentry events, emails,
Ray payloads, HTTP requests, OpenTelemetry traces and profiles, all on the same ports. The functions work with any
Buggregator server. Use PhpStorm? Try Trap with the [Buggregator Plugin](https://github.com/buggregator/phpstorm-plugin).

**Debug long-running applications.** In a RoadRunner, Swoole or FrankenPHP worker, under Laravel Octane or in a queue
consumer, `dump()` has nowhere to go: there is no page to print into, and RoadRunner keeps STDOUT for its own
protocol. `trap()` sends dumps over a socket to Trap running next to the application, so they arrive while the worker
keeps serving. `->times()` and `->once()` keep a loop from flooding the console, and `tr()` shows the time and memory
of every iteration, so a leak shows up as a growing number.

```php
while ($request = $worker->waitRequest()) {
    tr();                                // time and memory since the previous request
    trap($request->getUri())->times(3);  // only the first three requests
    $worker->respond($app->handle($request));
}
```

Dump in detail: limit the depth, name the values, dump once or on a condition, attach context.
→ [Functions](docs/functions.md)

```php
trap($order)->depth(5);
trap(user: $user, order: $order);
trap($item)->once()->if($item->price > 1000);
trap($order)->context(request: $requestId, env: 'stage');
trap($query)->code('sql');
```

Protobuf messages come out compact and readable, on the left with Trap, on the right without:

![trap-proto-diff](https://github.com/buggregator/trap/assets/4152481/30662429-809e-422a-83c6-61d7d2788b18)

Trace the execution: `tr()` without arguments shows the time and memory since its previous call, `td()` also stops
the script. → [`tr()` and `td()`](docs/functions.md#tr-and-td)

```php
tr();                           // Trace #0  -.---  3.42M
$data = $this->prepare($input);
tr();                           // Trace #1  0.015ms  6.58M
trap()->stackTrace();
```

Profile a piece of code with Excimer, XHProf or SPX; Trap also takes Xdebug, Speedscope and pprof profiles.
→ [Profiling code](docs/profiling.md)

```php
$result = trap()::profile()->run(fn() => $app->handle($request));

trap()::profile()->toFile('checkout.speedscope.json')->run(fn() => $checkout->handle($order));
```

Log without a logging stack: `trap()::logger()` is a PSR-3 logger to pass to any library, and Trap catches its
records and shows them next to your dumps. No Monolog, ELK or Loki to set up; with no Trap running, the records go to
STDERR. → [`trap()::logger()`](docs/functions.md#traplogger)

```php
trap()::logger()->info('Order {id} paid', ['id' => $order->id]);
$client = new ApiClient(logger: trap()::logger('api'));
```

Catch what your application sends: one `vendor/bin/trap` receives it all and shows it in the console,
or in the browser with `--ui`. → [What Trap receives](#what-trap-receives)

```bash
SENTRY_DSN=http://sentry@127.0.0.1:8000/1
MAILER_DSN=smtp://127.0.0.1:1025
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
```

Everything Trap catches is rendered right in the terminal:

| symfony/var-dumper (proto)                                                                             | Binary Data                                                                                             |
|--------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------|
| ![var-dumper](https://github.com/buggregator/trap/assets/4152481/f4c855f5-87c4-4534-b72d-5b19d1aae0b0) | ![Binary Data](https://github.com/buggregator/trap/assets/4152481/cd8788ed-b10c-4b9a-b2e2-baa8912ea38d) |

| SMTP Mail Trap                                                                                   | HTTP Dump                                                                                             |
|--------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------|
| ![smtp](https://github.com/buggregator/trap/assets/4152481/b11c4a7f-072a-4e66-b11d-9bbd3177bfe2) | ![http-dump](https://github.com/buggregator/trap/assets/4152481/48201ce6-7756-4402-8954-76a27489b632) |

## Installation

Add Trap as a dev dependency with Composer:

```bash
composer require --dev buggregator/trap -W
```

[![PHP](https://img.shields.io/packagist/php-v/buggregator/trap.svg?style=flat-square&logo=php)](https://packagist.org/packages/buggregator/trap)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/buggregator/trap.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/buggregator/trap)
[![License](https://img.shields.io/packagist/l/buggregator/trap.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/buggregator/trap.svg?style=flat-square)](https://packagist.org/packages/buggregator/trap/stats)

Only need the server, or the dependencies conflict with your project? Take the standalone binary or the Phar:

```bash
# Binary, with Dload
dload get trap

# Phar, with Phive
phive install buggregator/trap
```

See [Installation](docs/installation.md) for the other ways.

## Quick start

Start the server:

```bash
vendor/bin/trap
```

Then call `trap()` in your code:

```php
trap($var);
```

The dump shows up in the terminal of the server. `tr(...)` equals `trap(...)->return()`, `td(...)` equals
`trap(...); die;`.

## What Trap receives

Trap detects the protocol of each connection, so every port takes every kind of traffic.
The ports below are the defaults of the clients.

| What                        | Point the client at it                                           | Docs                                                                              |
|-----------------------------|------------------------------------------------------------------|-----------------------------------------------------------------------------------|
| Symfony VarDumper, `trap()` | `VAR_DUMPER_FORMAT=server`, `VAR_DUMPER_SERVER=127.0.0.1:9912`   | [var-dumper](docs/var-dumper.md)                                                  |
| Monolog                     | `SocketHandler('127.0.0.1:9913')` with `JsonFormatter`           | [Monolog](docs/monolog.md)                                                        |
| Sentry                      | `SENTRY_DSN=http://sentry@127.0.0.1:8000/1`                      | [Sentry](docs/sentry.md)                                                          |
| SMTP                        | `MAILER_DSN=smtp://127.0.0.1:1025`                               | [SMTP](docs/smtp.md)                                                              |
| Ray                         | host `127.0.0.1`, port `8000` in `ray.php`                       | [Ray](docs/ray.md)                                                                |
| HTTP dumps                  | any HTTP request, e.g. `http://127.0.0.1:8000/webhook`           | [HTTP dumps](docs/http-dump.md)                                                   |
| OpenTelemetry traces        | `OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318`              | [OpenTelemetry](docs/opentelemetry.md)                                            |
| Profiles                    | `trap()::profile()`, `POST /api/profiler/*`, a watched directory | [Profiling](docs/profiling.md), [receiving](docs/profiles.md), [SPX](docs/spx.md) |
| Binary data                 | any raw bytes                                                    |                                                                                   |

## Configuration

| Option or variable               | Default                    | Meaning                                     |
|----------------------------------|----------------------------|---------------------------------------------|
| `-p`, `--port`, `TRAP_TCP_PORTS` | `1025,4318,8000,9912,9913` | Ports to listen on                          |
| `TRAP_TCP_HOST`                  | `127.0.0.1`                | Host to listen on                           |
| `-s`, `--sender`                 | `console`                  | Where events go: [senders](docs/senders.md) |
| `--ui`, `TRAP_UI_PORT`           | off, port `8000`           | The [web UI](docs/web-ui.md)                |
| `TRAP_MEMORY_LIMIT`              | `512M`                     | Memory limit of the server                  |

```bash
vendor/bin/trap -p9912 --ui=8000
vendor/bin/trap -sconsole -sfile
```

All the options and variables: [CLI](docs/cli.md) and [Configuration](docs/configuration.md).

## Documentation

- [Installation](docs/installation.md)
- [CLI](docs/cli.md)
- [Configuration](docs/configuration.md)
- [Functions](docs/functions.md)
- [Profiling code](docs/profiling.md) and [receiving profiles](docs/profiles.md), [SPX](docs/spx.md)
- Integrations: [var-dumper](docs/var-dumper.md), [Monolog](docs/monolog.md), [Sentry](docs/sentry.md),
  [SMTP](docs/smtp.md), [Ray](docs/ray.md), [HTTP dumps](docs/http-dump.md), [OpenTelemetry](docs/opentelemetry.md)
- [Senders](docs/senders.md)
- [Web UI](docs/web-ui.md)

## Contributing

- Found a bug or have an idea? [Open an issue](https://github.com/buggregator/trap/issues).
  Issues labeled [help wanted](https://github.com/buggregator/trap/issues?q=is%3Aopen+is%3Aissue+label%3A%22help+wanted%22)
  are a good place to start.
- Ask and answer questions on [Discord](https://discord.gg/qF3HpXhMEP).
- Write code or docs: see [CONTRIBUTING.md](CONTRIBUTING.md).
- Use Buggregator commercially? [Become a sponsor](https://boosty.to/roxblnfk). You can also sponsor a specific
  feature or fix; sponsored tasks carry the [`SPONSORED`](https://github.com/buggregator/trap/issues?q=label%3ASPONSORED) label.
