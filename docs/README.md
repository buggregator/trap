# Buggregator Trap documentation

## Getting started

- [Installation](installation.md) - Composer, binary, Phar, optional extensions.
- [CLI](cli.md) - the `run`, `test` and `joke` commands and their options.
- [Configuration](configuration.md) - ports, environment variables, `trap.xml`.

## In your code

- [Functions](functions.md) - `trap()` and its methods, `tr()`, `td()`, `trap()::logger()`, protobuf dumps.
- [Profiling code](profiling.md) - `trap()::profile()`: profilers, destinations, formats.

## What Trap receives

- [Symfony VarDumper](var-dumper.md) - `dump()` and `trap()` dumps.
- [Monolog](monolog.md) - log records over a socket.
- [Sentry](sentry.md) - events of Sentry SDKs.
- [SMTP](smtp.md) - emails.
- [Ray](ray.md) - payloads of Ray clients.
- [HTTP dumps](http-dump.md) - any other HTTP request.
- [OpenTelemetry](opentelemetry.md) - traces over OTLP/HTTP.
- [Receiving profiles](profiles.md) - XHProf, Xdebug, Speedscope, pprof and SPX over HTTP or from a directory.
- [SPX](spx.md) - SPX full reports: directory, HTTP and profiling from code.

## Output

- [Senders](senders.md) - console, files, a remote server, the web UI.
- [Web UI](web-ui.md) - the `--ui` option.
