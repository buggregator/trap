# Web UI

The `--ui` option of [`run`](cli.md#run) starts the web interface of the Buggregator Server inside Trap.
The CLI marks it experimental.

```bash
vendor/bin/trap --ui
```

Then open <http://127.0.0.1:8000> in the browser.

![trap-ui](https://github.com/buggregator/trap/assets/4152481/1ccc2c85-2f81-4b62-8ae7-49ee76380674)

## Port

The UI port is `8000` by default. Change it with `--ui=<port>`, `TRAP_UI_PORT` or [`trap.xml`](configuration.md#trapxml);
the environment variable wins.

- When the UI port is one of the TCP ports (`8000` is), the UI shares it with the rest of the traffic.
  The port listens on `TRAP_TCP_HOST`.
- Otherwise the UI gets a port of its own, which serves only the UI and listens on `TRAP_UI_HOST`
  (default `127.0.0.1`).

The UI asks for no password or token, the [MCP token](mcp.md#security) included: anyone who reaches its port sees
the caught events. Trap warns at startup when the port listens on a host other than a loopback one.

```bash
# UI on its own port 8080, no console output
vendor/bin/trap --ui=8080 -s
```

## What it shows

Dumps, Monolog logs, Sentry events, emails, Ray payloads, HTTP dumps and profiles.

The UI keeps the last 200 events; `/trap/events/@maxEvents` in [`trap.xml`](configuration.md#trapxml) changes it.
The events live in the memory of Trap and are gone when it stops. The [MCP server](mcp.md) works with the same
events: an event deleted in the UI is gone for it too.

## Sentry pages

The Sentry section has a page for each kind of data:

- Exceptions: the [Sentry](sentry.md) error events, one by one or grouped by type and message.
- Traces: the transactions and spans of [Sentry SDKs](sentry.md#what-an-envelope-gives) and
  [OpenTelemetry](opentelemetry.md#how-the-ui-shows-otlp-data) exporters, one trace even when it crosses both,
  with the waterfall, the call sequence between services, the related errors and logs.
- Service map: the services and what they call (other services, databases, caches, queues) over the period,
  with requests, errors and durations per service and per call, and the traces through each call. A call is a span
  with a peer: a database or cache address, an HTTP host, a queue; it leads to the service that served it when
  that service sends its data to Trap too.
- Logs and Metrics: Sentry `log` and `trace_metric` items, OTLP logs and metrics.

Traces, logs and metrics make no events in the feed. Trap keeps them in memory while the UI or the
[MCP server](mcp.md) runs, and the UI asks for them by project and period. "Clear all" in the Sentry section
deletes them with the Sentry events.

Memory is bounded by these limits; values below 1 fall back to the defaults:

| Environment variable         | `trap.xml`                   | Default | Above it                                                       |
|------------------------------|------------------------------|---------|----------------------------------------------------------------|
| `TRAP_TELEMETRY_MAX_SPANS`   | `/trap/telemetry/@maxSpans`  | `10000` | the least recently updated traces are dropped                  |
| `TRAP_TELEMETRY_MAX_LOGS`    | `/trap/telemetry/@maxLogs`   | `5000`  | the oldest log records are dropped                             |
| `TRAP_TELEMETRY_MAX_METRICS` | `/trap/telemetry/@maxMetrics`| `5000`  | the oldest metric values are dropped                           |

`maxSpans` counts transactions and spans together; a single trace above it keeps its most recent 90%.
These limits are apart from the [OpenTelemetry limits](opentelemetry.md#memory-limits) of the console and the
MCP server.

The UI also controls `ray()->pause()`: see [Ray](ray.md#notes).
