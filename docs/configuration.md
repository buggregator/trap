# Configuration

The server takes its settings from, in order of precedence:

1. environment variables,
2. [CLI options](cli.md),
3. the [`trap.xml`](#trapxml) file of the package,
4. PHP INI settings (for the profiler directories),
5. the defaults.

The first source that has a value wins. Environment variables of the client (`trap()`, `trap()::logger()`,
`trap()::profile()`) are listed [below](#client-environment-variables).

## Ports and traffic detection

Trap recognizes the protocol of each connection by its first bytes, so every port accepts every kind of traffic:
var-dumper, Monolog, SMTP and HTTP (Sentry, Ray, profilers, OpenTelemetry, HTTP dumps), plain or over [TLS](tls.md).
There is no need to open a port per protocol.

By default Trap listens on the ports of the Buggregator Server: `9912`, `9913`, `1025` and `8000`,
plus `4318`, the default OTLP/HTTP port. These are the ports the clients use by default:

| Port   | Default port of                                  |
|--------|--------------------------------------------------|
| `9912` | Symfony VarDumper server, `trap()`               |
| `9913` | Monolog socket handler, `trap()::logger()`       |
| `1025` | SMTP in Buggregator and similar mail catchers    |
| `8000` | HTTP: Sentry, Ray, profilers, HTTP dumps, web UI |
| `4318` | OTLP/HTTP                                        |

To listen on other ports, use `-p` or `TRAP_TCP_PORTS`:

```bash
vendor/bin/trap -p9912 -p8000
TRAP_TCP_PORTS=9912,8000 vendor/bin/trap
```

## Server environment variables

| Variable                      | Default                    | Meaning                                                                                                                                                                                                                                |
|-------------------------------|----------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `TRAP_TCP_PORTS`              | `1025,4318,8000,9912,9913` | Comma-separated TCP ports to listen on. Takes precedence over `-p`.                                                                                                                                                                    |
| `TRAP_TCP_HOST`               | `127.0.0.1`                | Host to listen on. Use `0.0.0.0` to accept connections from other machines or containers.                                                                                                                                              |
| `TRAP_UI_PORT`                | `8000`                     | Port of the [web UI](web-ui.md). Takes precedence over `--ui=<port>`.                                                                                                                                                                  |
| `TRAP_UI_HOST`                | `127.0.0.1`                | Host of the web UI when its port is not one of the TCP ports. Unset, it is the MCP server host on a shared port, or `127.0.0.1`.                                                                                                       |
| `TRAP_MCP_PORT`               | `8000`                     | Port of the [MCP server](mcp.md). Takes precedence over `--mcp=<port>`.                                                                                                                                                                |
| `TRAP_MCP_HOST`               | `127.0.0.1`                | Host of the MCP server when its port is not one of the TCP ports. Unset, it is the web UI host on a shared port, or `127.0.0.1`.                                                                                                       |
| `TRAP_MCP_TOKEN`              |                            | Bearer token every request to the MCP server must carry; empty asks for none. See [Security](mcp.md#security).                                                                                                                         |
| `TRAP_MEMORY_LIMIT`           | `512M`                     | Raises PHP's `memory_limit` when it is lower; `-1` for no limit, empty to keep it. Large profiles get as many call tree nodes as the memory left affords, and are compacted past that.                                                 |
| `TRAP_GC_INTERVAL`            | `30`                       | How often, in seconds, the server collects garbage cycles in place of PHP's cycle collector; `0` keeps PHP's collector.                                                                                                                |
| `TRAP_MAIN_LOOP_INTERVAL`     | `1000`                     | Longest wait, in microseconds, of an idle main loop. Data on a socket ends the wait sooner, and timers such as a buffer flush fire this much late at most. Values below `50` count as `50`. On Windows any wait lasts at least ~15 ms. |
| `TRAP_HTTP_MAX_INFLATED_SIZE` | `64M`                      | Size a gzip-compressed HTTP request body may inflate to, and a chunked one may decode to; a larger one is answered with `413`. See [the format](#size-format).                                                                         |
| `TRAP_TLS_CERT`               |                            | PEM certificate for [TLS](tls.md) on the listened ports. Without it Trap generates a self-signed one for `localhost`.                                                                                                                  |
| `TRAP_TLS_KEY`                |                            | PEM private key of `TRAP_TLS_CERT`, when it is not in the certificate file.                                                                                                                                                            |
| `TRAP_XHPROF_PATH`            | `xhprof.output_dir`        | Directory to pick up XHProf files from. See [Receiving profiles](profiles.md#directory-watching).                                                                                                                                      |
| `TRAP_XDEBUG_PATH`            | `xdebug.output_dir`        | Directory to pick up Xdebug cachegrind files from. Without it, `xdebug.output_dir` is watched while `xdebug.mode` (or `XDEBUG_MODE`) includes `profile`.                                                                               |
| `TRAP_SPX_PATH`               | `spx.data_dir`             | Directory to pick up [SPX](spx.md) full reports from; `spx.data_dir` is used when ext-spx is loaded in Trap's PHP.                                                                                                                     |
| `TRAP_XDEBUG_TRACE_PATH`      | `xdebug.output_dir`        | Directory to pick up [Xdebug traces](xdebug-traces.md) from. Without it, `xdebug.output_dir` is watched while `xdebug.mode` (or `XDEBUG_MODE`) includes `trace`.                                                                                |
| `TRAP_XDEBUG_TRACE_MAX_UPLOAD` | `100M`                     | Size of an Xdebug trace as sent; a larger one is rejected. See [the format](#size-format).                                                                                                                                             |
| `TRAP_XDEBUG_TRACE_MAX_SIZE`  | `256M`                     | Uncompressed bytes read of an Xdebug trace; a longer one is cut there.                                                                                                                                                                 |
| `TRAP_XDEBUG_TRACE_KEEP`      | `20`                       | Xdebug traces kept on disk; a new one deletes the oldest with its event.                                                                                                                                                               |
| `TRAP_XDEBUG_*`              |                            | Settings of the [Xdebug debugger](xdebug.md#settings): port, IDE address, mode, eval, agent control, idle timeout.                                                                                                                     |
| `TRAP_TRACES_MAX_SPANS`       | `10000`                    | Maximum number of kept [OpenTelemetry](opentelemetry.md#memory-limits) spans.                                                                                                                                                          |
| `TRAP_TRACES_TTL`             | `600`                      | Seconds an OpenTelemetry trace is kept after its last received span.                                                                                                                                                                   |
| `TRAP_TELEMETRY_MAX_SPANS`    | `10000`                    | Maximum number of transactions and spans the [Sentry pages](web-ui.md#sentry-pages) of the web UI keep.                                                                                                                                |
| `TRAP_TELEMETRY_MAX_LOGS`     | `5000`                     | Maximum number of log records the Sentry pages of the web UI keep.                                                                                                                                                                     |
| `TRAP_TELEMETRY_MAX_METRICS`  | `5000`                     | Maximum number of metric values the Sentry pages of the web UI keep.                                                                                                                                                                   |

The defaults in the column of the profiler directories are PHP INI settings of the PHP that runs Trap.

### Size format

`TRAP_HTTP_MAX_INFLATED_SIZE` and the Xdebug trace sizes take bytes, e.g. `67108864`, or a number with a `K`, `M` or `G` suffix,
e.g. `512K` or `1G` (binary multiples, any case). Any other value, e.g. `67_108_864`, `64MB` or `0`, falls back to
the default with a warning.

## Client environment variables

The client reads these variables from `$_ENV` or the process environment.

| Variable                 | Default          | Meaning                                                                                                                          |
|--------------------------|------------------|----------------------------------------------------------------------------------------------------------------------------------|
| `VAR_DUMPER_FORMAT`      | `server`         | Where `trap()` dumps go, as in Symfony VarDumper: `server`, `tcp://host:port`, `cli` or `html`. See [var-dumper](var-dumper.md). |
| `VAR_DUMPER_SERVER`      | `127.0.0.1:9912` | Host and port `trap()` sends dumps to with the `server` format.                                                                  |
| `TRAP_MONOLOG_HOST`      | `127.0.0.1`      | Host of [`trap()::logger()`](functions.md#traplogger). Prefix it with `tls://` to connect over TLS.                              |
| `TRAP_MONOLOG_PORT`      | `9913`           | Port of `trap()::logger()`.                                                                                                      |
| `TRAP_PROFILER_HOST`     | `127.0.0.1`      | Host `->toTrap()` of [profiling](profiling.md) sends to.                                                                         |
| `TRAP_PROFILER_PORT`     | `9912`           | Port `->toTrap()` sends to.                                                                                                      |
| `TRAP_PROFILER_SERVER`   |                  | The default URL of `->toServer()`.                                                                                               |
| `TRAP_PROFILER_APP_NAME` |                  | The application name in the profile metadata.                                                                                    |

## trap.xml

`trap.xml` lies in the root of the package and holds settings that have no environment variable:

```xml
<?xml version="1.0"?>
<trap>
    <events maxEvents="200"/>
    <frontend port="8000"/>
    <traces maxSpans="10000" ttl="600"/>
    <telemetry maxSpans="10000" maxLogs="5000" maxMetrics="5000"/>
</trap>
```

| Path                      | Meaning                                                              |
|---------------------------|----------------------------------------------------------------------|
| `/trap/events/@maxEvents` | How many events the web UI and the MCP server keep, `200` by default |
| `/trap/frontend/@port`    | Port of the web UI, as `TRAP_UI_PORT`                                |
| `/trap/frontend/@host`    | Host of the web UI, as `TRAP_UI_HOST`                                |
| `/trap/mcp/@port`         | Port of the MCP server, as `TRAP_MCP_PORT`                           |
| `/trap/mcp/@host`         | Host of the MCP server, as `TRAP_MCP_HOST`                           |
| `/trap/mcp/@token`        | Token of the MCP server, as `TRAP_MCP_TOKEN`                         |
| `/trap/tls/@cert`         | Certificate for TLS, as `TRAP_TLS_CERT`                              |
| `/trap/tls/@key`          | Key of the certificate, as `TRAP_TLS_KEY`                            |
| `/trap/traces/@maxSpans`  | As `TRAP_TRACES_MAX_SPANS`                                           |
| `/trap/traces/@ttl`       | As `TRAP_TRACES_TTL`                                                 |
| `/trap/telemetry/@maxSpans`   | As `TRAP_TELEMETRY_MAX_SPANS`                                    |
| `/trap/telemetry/@maxLogs`    | As `TRAP_TELEMETRY_MAX_LOGS`                                     |
| `/trap/telemetry/@maxMetrics` | As `TRAP_TELEMETRY_MAX_METRICS`                                  |
| `/trap/xdebugTraces/@maxUpload` | As `TRAP_XDEBUG_TRACE_MAX_UPLOAD`                              |
| `/trap/xdebugTraces/@maxSize`   | As `TRAP_XDEBUG_TRACE_MAX_SIZE`                                |
| `/trap/xdebugTraces/@keep`      | As `TRAP_XDEBUG_TRACE_KEEP`                                    |
| `/trap/xdebug/@*`             | The [Xdebug debugger](xdebug.md#settings) settings: `port`, `host`, `ide`, `mode`, `allowEval`, `allowControl`, `pauseOnFirstLine`, `idleTimeout` |

Trap reads only the `trap.xml` of its own package (e.g. `vendor/buggregator/trap/trap.xml`), and only with
`ext-simplexml`. The Phar and the binary carry their own copy.
