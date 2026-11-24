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
server of buggregator/frontend, can't call its API. An `OPTIONS` request is an HTTP dump like any other, but one to
`/csp` or `/csp/...`: it gets the CORS preflight answer [CSP reports](csp.md) need.

The UI asks for no password or token, the [MCP token](mcp.md#security) included: anyone who reaches its port sees
the caught events. Trap warns at startup when the port listens on a host other than a loopback one.

```bash
# UI on its own port 8080, no console output
vendor/bin/trap --ui=8080 -s
```

## What it shows

Dumps, Monolog logs, Sentry events, emails with their
[other versions and invites](smtp.md#other-versions-and-invites), Ray payloads, HTTP dumps, profiles,
[Xdebug traces](xdebug-traces.md) with their call tree, and [CSP reports](csp.md) on the CSP reports page.

The UI keeps the last 200 events; `/trap/events/@maxEvents` in [`trap.xml`](configuration.md#trapxml) changes it.
A pinned event is pushed out last: only when all the kept events are pinned. A clear keeps the pinned events when
the UI asks so; [`clear_events`](mcp.md#tools) of the MCP server keeps them unless told otherwise. Another browser
tab shows a pin made in this one when it loads the event list again, e.g. on a reload.
The events live in the memory of Trap and are gone when it stops. The [MCP server](mcp.md) works with the same
events: an event deleted in the UI is gone for it too.

The UI also controls `ray()->pause()`, and every tab shows whether a pause was continued or stopped: see
[Ray](ray.md#notes). With `--xdebug` its Xdebug page lists the debug
sessions and steps through the ones Trap drives, and its setup guide shows the debugger port: see
[Xdebug debugger](xdebug.md).

The UI has pages for features of the Buggregator Server that Trap has not: metrics, the mock server, updates.
They show no data, and the menu of the mock server stays while HTTP dumps are on: hide it in Settings › Menu.
Other features of the server stay hidden, as Trap answers 404 to their endpoints: the Broadcast page, the «Send to
inbox» button of an email (Settings › Email relay says the server can't send email), sign-in and the audit log.

## Logs and feed filters

The Logs page lists the Monolog records with a full-text search over the message, the channel and the keys and
values of context and extra, case-insensitive, the level and channel filters and a chart of the records over time:
by minute, in wider bars over a long period.

The filter bar above a feed narrows it by the values Trap takes from the events; other events have none:

- Monolog: the channel, the level and the host in `extra.hostname`, which a `HostnameProcessor` adds;
- Sentry: the host (`server_name`), environment, release, level, the logger as the channel, the user (its email,
  username, ID or IP address) and the tags;
- Ray: the host of each payload's `origin`;
- profiles: the host (`hostname`) and the tags;
- dumps: the host of the request the dump was made in; a CLI dump has none;
- HTTP dumps: the host of the `Host` header.

The request view of a trace on the Sentry pages adds the events of the request: Monolog records with its trace ID
in `extra.trace_id` or `context.trace_id`, and HTTP dumps and emails with a `sentry-trace`, `traceparent` or
`X-Trace-Id` header of it.

## Sentry pages

The Sentry section has a page for each kind of data:

- Exceptions: the [Sentry](sentry.md) error events, one by one or grouped by type and message.
- Traces: the transactions and spans of [Sentry SDKs](sentry.md#what-an-envelope-gives) and
  [OpenTelemetry](opentelemetry.md#how-the-ui-shows-otlp-data) exporters, one trace even when it crosses both,
  with the waterfall, the call sequence between services, the related errors and logs. The request view of a
  trace marks [repeated queries](#repeated-queries-n1) with an N+1 hint and groups their spans.
- Service map: the services and what they call (other services, databases, caches, queues) over the period,
  with requests, errors and durations per service and per call, and the traces through each call. A call is a span
  with a peer: a database or cache address, an HTTP host, a queue; it leads to the service that served it when
  that service sends its data to Trap too.
- Logs and Metrics: Sentry `log` and `trace_metric` items, OTLP logs and metrics.
- Feedback: the [user feedback](sentry.md#user-feedback) of the project, each with a link to its error; the page of
  an error shows the feedback on it. The tab shows once the period has feedback.
- Queues: the [queue jobs](sentry.md#queue-jobs) whose last attempt ended in the period, the latest first, with
  their status, queue, attempts and failures, and the jobs, failures, retried jobs and average duration of the
  period above. The page of a job shows its attempts on a time scale with the pauses between them, the time each
  waited in the queue, their errors and traces, and the request that queued the job. The tab shows once the
  period has a job.
- Crons: the [cron jobs](sentry.md#crons) of the project, whatever the period, with the last run, the next expected
  one and the recent runs, failed, timed out and missed ones first; the page of a job shows its runs with their
  durations, output and traces, and the ping URLs of a job that pings. The tab is always there; with no job it shows
  how to send the first check-in. A job page opened on a [port of the UI's own](#port) shows ping URLs of that
  port, which takes no check-ins.

Traces, logs, metrics, feedback and cron runs make no events in the feed: a Sentry transaction is a trace, and the
feed holds the errors. Trap keeps them in memory while the UI or the [MCP server](mcp.md) runs, and the UI asks for
them by project and period. The MCP tools [`list_traces` and `get_trace`](mcp.md#traces) read the same traces,
[`list_queue_jobs`](mcp.md#queue-jobs) the same jobs, [`list_feedback`](mcp.md#user-feedback) the same feedback
and [`list_crons`](mcp.md#cron-jobs) the same cron jobs. "Clear all" in the Sentry section deletes them with the
Sentry events; with the pinned events kept, the feedback on a pinned error stays too. The cron jobs stay, without
their runs. The project list has the projects of cron jobs too, as a ping URL may name a project no event has.

Memory is bounded by these limits; values below 1 fall back to the defaults:

| Environment variable         | `trap.xml`                   | Default | Above it                                                       |
|------------------------------|------------------------------|---------|----------------------------------------------------------------|
| `TRAP_TELEMETRY_MAX_SPANS`   | `/trap/telemetry/@maxSpans`  | `10000` | the least recently updated traces are dropped                  |
| `TRAP_TELEMETRY_MAX_LOGS`    | `/trap/telemetry/@maxLogs`   | `5000`  | the oldest log records are dropped                             |
| `TRAP_TELEMETRY_MAX_METRICS` | `/trap/telemetry/@maxMetrics`| `5000`  | the oldest metric values are dropped                           |

`maxSpans` counts transactions and spans together; a single trace above it keeps its most recent 90%.
Feedback has a fixed limit of 1 000 entries, cron jobs one of 200 jobs with 100 runs each and 8 MB of ping output
in all, the oldest runs losing theirs first.
These limits are apart from the [OpenTelemetry limits](opentelemetry.md#memory-limits) of the console.

### Repeated queries (N+1)

A trace reports a query that one transaction runs 5 times or more, up to its values: the N+1 pattern of a loop
that loads a relation row by row. The trace page and the MCP tool [`get_trace`](mcp.md#traces) show the same
findings:

- Only database spans count: a Sentry span of op `db` or `db.*`, an OpenTelemetry span with `db.system` or
  `db.system.name`. The query is the `db.query.text` attribute, else `db.statement`, else the span description,
  where Sentry SDKs put it (an OpenTelemetry span name is a short summary such as `SELECT shop.products`).
- A span belongs to the transaction it came in, else to the one up its parents: OpenTelemetry and Sentry span
  streams send no transaction ID. Spans whose transaction has not arrived count together under their topmost span.
- Two queries are the same when they differ only in values: string literals, numbers, `TRUE`, `FALSE` and the
  placeholders `?`, `$1`, `:name` become `?`; an `IN (…)` list or the rows of `VALUES (…), (…)` of any length
  become one `(?)`; comments go; keywords are upper-cased and spacing is made uniform. Names stay as written, with
  their digits (`table2`), and so do `"quoted"` names and `::type` casts. A `?` or a quote inside a string literal
  stays in the literal.
- Each finding has the query with its values as `?`, the span count, their summed duration, and the place in the
  code, `code.filepath:code.lineno` (or `code.file.path:code.line.number`) of the first span that has it.
  sentry-laravel sends it only for queries slower than `SENTRY_TRACE_SQL_ORIGIN_THRESHOLD_MS`, 100 ms by default.
- Trap finds them when a trace is read and keeps them until the trace changes, so a trace that grows as its spans
  arrive is checked again.
