# Buggregator Trap documentation

## Getting started

- [Installation](installation.md) - Composer, binary, Phar, optional extensions.
- [CLI](cli.md) - the `run`, `test` and `joke` commands and their options.
- [Configuration](configuration.md) - ports, environment variables, `trap.xml`.
- [TLS](tls.md) - HTTPS, SMTPS, `STARTTLS` and `tls://` on the same ports, certificates.

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
- [CSP reports](csp.md) - Content Security Policy violations and Reporting API reports of browsers.
- [Receiving profiles](profiles.md) - XHProf, Xdebug, Speedscope, pprof and SPX over HTTP or from a directory.
- [SPX](spx.md) - SPX full reports: directory, HTTP and profiling from code.
- [Blackfire probe](blackfire.md) - profiles of the Blackfire PHP probe, taken in place of its agent.
- [Xdebug traces](xdebug-traces.md) - Xdebug function traces over HTTP or from a directory: the call tree.

## Output

- [Senders](senders.md) - console, files, a remote server, the web UI.
- [Web UI](web-ui.md) - the `--ui` option.
- [MCP server](mcp.md) - the `--mcp` option: Trap for AI agents.
- [Xdebug debugger](xdebug.md) - the `--xdebug` option: step debugging with Xdebug or PHP Debugger from the web UI and AI agents, or relayed to the IDE.
- [Agent skills](agent-skills.md) - the `trap` skill that teaches coding agents to debug and profile with Trap.
