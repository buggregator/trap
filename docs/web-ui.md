# Web UI

The `--ui` option of [`run`](cli.md#run) starts the web interface of the Buggregator Server inside Trap:
the build of [buggregator/frontend](https://github.com/buggregator/frontend) 1.42.0. The CLI marks it experimental.

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
  (default `127.0.0.1`). The setup guide of the UI still puts that port in the HTTP addresses, as DSNs: send HTTP
  to a TCP port instead, `8000` by default.

On a shared port the UI takes only its own requests, and the others stay [HTTP dumps](http-dump.md):

- `/`, the files of the UI build and `/api/...` routes the UI has;
- a page of the UI opened in a browser: a `GET` that accepts `text/html` of `/settings`, `/favorites`, `/logs`,
  `/sentry/...`, `/{type}` or `/{type}/{id}` with an event type of the UI, as `/smtp/{uuid}`;
- any `/api/...` call made from a page of the UI (`Sec-Fetch-Site: same-origin`), answered 404 when Trap has no
  such endpoint.

Open the UI from Trap itself: it sends no CORS headers, so a frontend served from another origin, such as a dev
server of buggregator/frontend, can't call its API. An `OPTIONS` request is an HTTP dump like any other.

The UI asks for no password or token, the [MCP token](mcp.md#security) included: anyone who reaches its port sees
the caught events. Trap warns at startup when the port listens on a host other than a loopback one.

```bash
# UI on its own port 8080, no console output
vendor/bin/trap --ui=8080 -s
```

## What it shows

Dumps, Monolog logs, Sentry events, emails, Ray payloads, HTTP dumps, profiles and
[Xdebug traces](xdebug-traces.md) with their call tree.

The UI keeps the last 200 events; `/trap/events/@maxEvents` in [`trap.xml`](configuration.md#trapxml) changes it.
A pinned event is pushed out last: only when all the kept events are pinned. A clear keeps the pinned events when
the UI asks so; [`clear_events`](mcp.md#tools) of the MCP server keeps them unless told otherwise. Another browser
tab shows a pin made in this one when it loads the event list again, e.g. on a reload.
The events live in the memory of Trap and are gone when it stops. The [MCP server](mcp.md) works with the same
events: an event deleted in the UI is gone for it too.

The UI also controls `ray()->pause()`: see [Ray](ray.md#notes). With `--xdebug` its Xdebug page lists the debug
sessions and steps through the ones Trap drives, and its setup guide shows the debugger port: see
[Xdebug debugger](xdebug.md).

The UI has pages for features of the Buggregator Server that Trap has not: metrics, the mock server, updates.
They show no data, and the menu of the mock server stays while HTTP dumps are on: hide it in Settings › Menu.
Other features of the server stay hidden, as Trap answers 404 to their endpoints: the Broadcast and CSP reports
pages, the Crons, Queues and Feedback tabs of the Sentry section (a Sentry feedback is an event of the feed), the
«Send to inbox» button of an email (Settings › Email relay says the server can't send email), the other versions
of an email and its invite card, sign-in and the audit log. A Ray pause shows its state in the tab whose button
released it; another tab keeps its buttons.

## Logs and feed filters

The Logs page lists the Monolog records with a full-text search over the message, the channel and the keys and
values of context and extra, the level and channel filters and a chart of records per minute.

The filter bar above a feed narrows it by the values Trap takes from the events; other events have none:

- Monolog: the channel, the level and the host in `extra.hostname`, which a `HostnameProcessor` adds;
- Sentry: the host (`server_name`), environment, release, level, the logger as the channel, the user (its email,
  username, ID or IP address) and the tags;
- HTTP dumps: the host of the `Host` header.

The request view of a trace on the Sentry pages adds the events of the request: Monolog records with its trace ID
in `extra.trace_id` or `context.trace_id`, and HTTP dumps and emails with a `sentry-trace`, `traceparent` or
`X-Trace-Id` header of it.

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

Traces, logs and metrics make no events in the feed: a Sentry transaction is a trace, and the feed holds the errors.
Trap keeps them in memory while the UI or the [MCP server](mcp.md) runs, and the UI asks for them by project and
period. The MCP tools [`list_traces` and `get_trace`](mcp.md#traces) read the same traces. "Clear all" in the
Sentry section deletes them with the Sentry events.

Memory is bounded by these limits; values below 1 fall back to the defaults:

| Environment variable         | `trap.xml`                   | Default | Above it                                                       |
|------------------------------|------------------------------|---------|----------------------------------------------------------------|
| `TRAP_TELEMETRY_MAX_SPANS`   | `/trap/telemetry/@maxSpans`  | `10000` | the least recently updated traces are dropped                  |
| `TRAP_TELEMETRY_MAX_LOGS`    | `/trap/telemetry/@maxLogs`   | `5000`  | the oldest log records are dropped                             |
| `TRAP_TELEMETRY_MAX_METRICS` | `/trap/telemetry/@maxMetrics`| `5000`  | the oldest metric values are dropped                           |

`maxSpans` counts transactions and spans together; a single trace above it keeps its most recent 90%.
These limits are apart from the [OpenTelemetry limits](opentelemetry.md#memory-limits) of the console.
