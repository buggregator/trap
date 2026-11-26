# CLI

The `trap` executable has three commands: `run` (the default), `test` and `joke`.
Depending on how you [installed](installation.md) Trap, call it as `vendor/bin/trap`, `./trap` or `./trap.phar`.

## `run`

Starts the server. It listens on the TCP ports, detects the protocol of each connection and passes what it receives
to the senders. `run` is the default command, so `vendor/bin/trap` equals `vendor/bin/trap run`.

| Option     | Short | Default                                | Meaning                                                                                      |
| ---------- | ----- | -------------------------------------- | -------------------------------------------------------------------------------------------- |
| `--port`   | `-p`  | `1025`, `4318`, `8000`, `9912`, `9913` | A port to listen on. Repeat the option for more ports: `-p9912 -p8000`.                      |
| `--sender` | `-s`  | `console`                              | Where to send what Trap receives. Repeat the option for more [senders](senders.md).          |
| `--ui`     |       | off                                    | Turns on the [web UI](web-ui.md) (experimental). Takes an optional port: `--ui=8080`.        |
| `--mcp`    |       | off                                    | Turns on the [MCP server](mcp.md) (experimental). Takes an optional port: `--mcp=8080`.      |
| `--xdebug` |       | off                                    | Turns on the [Xdebug debugger](xdebug.md) (experimental) on port `9003`, or `--xdebug=9013`. |

The `-v` option prints more messages of the server, and `-vv` also prints its debug messages and errors, e.g. a port
it can't listen on. The other options are the standard ones of Symfony Console: `--help`, `--quiet`, `--version`,
`--ansi`/`--no-ansi`, `--no-interaction`.

Examples:

```bash
# Default ports, console output
vendor/bin/trap

# Only port 9912, web UI on port 8000
vendor/bin/trap -p9912 --ui=8000

# Console and file output
vendor/bin/trap -sconsole -sfile

# Web UI on port 8080 without console output
vendor/bin/trap --ui=8080 -s

# Web UI and MCP server, both on port 8000
vendor/bin/trap --ui --mcp

# Web UI and the Xdebug debugger on port 9003
vendor/bin/trap --ui --xdebug
```

`-s` without a value removes the default `console` sender. `--ui` adds the `ui` sender to the list, `--mcp` the `mcp` one,
`--xdebug` the `xdebug` one; `XDEBUG_PROXY_ENABLED=true`, as in Buggregator Server, adds it too.

Environment variables and [`trap.xml`](configuration.md#trapxml) configure the same settings and more;
see [Configuration](configuration.md). An environment variable takes precedence over the option:
`TRAP_TCP_PORTS` over `--port`, `TRAP_UI_PORT` over the port of `--ui`, `TRAP_MCP_PORT` over the port of `--mcp`,
`TRAP_XDEBUG_PORT` over the port of `--xdebug`.

Stop the server with `Ctrl+C`. A second `Ctrl+C` exits at once.

## `test`

Sends a sample of every kind of data Trap receives to a running Trap, so that one run fills the console and every
page of the [web UI](web-ui.md), Sentry pages included:

- profiles: XHProf, Xdebug, SPX and Speedscope (Excimer);
- an [Xdebug function trace](xdebug-traces.md);
- `trap()` dumps and a protobuf message;
- three emails over SMTP: a multipart one with Apple Watch and AMP versions, an invite and an attached file,
  a cancelled invite, and a plain one;
- [Monolog](monolog.md) records of several levels and channels;
- [Ray](ray.md) payloads: a log with a color and a label, a table, a caller, HTML and an exception;
- two [HTTP dumps](http-dump.md): a `GET` with a query and cookies, and a form that uploads a file;
- [Sentry](sentry.md) events of older SDKs, and the envelopes of a failed request: the error, the transaction of its
  trace with database, cache and HTTP spans, logs and trace metrics;
- [cron check-ins](sentry.md#crons): a Sentry SDK job that started and finished, a failed one of an interval
  schedule, and a ping URL job that started and failed with its output;
- an [OpenTelemetry](opentelemetry.md) trace across two services, with logs and metrics of each type;
- a Sentry error with logs of a second project, `billing`;
- [CSP reports](csp.md) of each format: a `report-uri` violation, and a Reporting API batch of a violation and a
  deprecation;
- an image as binary data.

The times of the logs, traces and metrics are those of the run, so the period filters of the UI show them.
Use the command to check that Trap is up and renders everything. It reports each part Trap refuses and then exits
with an error; a Trap without ext-zlib refuses the gzipped SPX profile and Xdebug trace.

| Option   | Short | Default     | Meaning                 |
| -------- | ----- | ----------- | ----------------------- |
| `--port` | `-p`  | `9912`      | Port of the Trap server |
| `--host` |       | `127.0.0.1` | Host of the Trap server |

```bash
# In one terminal
vendor/bin/trap

# In another one
vendor/bin/trap test
```

## `joke`

Sends a random joke to Trap with `trap()`. A quick way to see that the dumps reach the server.

```bash
vendor/bin/trap joke
```
