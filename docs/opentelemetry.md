# OpenTelemetry

Trap receives traces, logs and metrics exported over OTLP/HTTP (`POST /v1/traces`, `/v1/logs`, `/v1/metrics`)
on any of its ports. The console prints traces as a tree of spans and logs and metrics one line per record;
the [web UI](web-ui.md#sentry-pages) shows them on its Sentry pages.

## Receive telemetry

Point the exporter of the OpenTelemetry SDK at Trap:

```bash
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
```

The paths may have a prefix: `/otlp/v1/traces` works too.

### Project

The web UI groups data by project. OTLP data lands in the `default` project unless the request names another one:

- the path `/api/{project}/integration/otlp/v1/traces` (also `/logs`, `/metrics`), as Sentry's OTLP endpoint;
- else the `X-Buggregator-Project` header: `OTEL_EXPORTER_OTLP_HEADERS=X-Buggregator-Project=shop`.

A numeric `{project}` is the project ID of a Sentry DSN and names no project.

### Protobuf

The `http/protobuf` protocol needs the suggested packages:

```bash
composer require --dev open-telemetry/gen-otlp-protobuf google/protobuf
```

The Phar, the standalone binary and the Docker image do not include them, so they accept only `http/json`.

## How the UI shows OTLP data

- Traces join Sentry traces: a trace may mix both. The entry span of each service (a span without a parent,
  with a remote parent, or a server or consumer span whose parent is in another service) is its transaction;
  the service name is the `service.name` resource attribute. The UI shows span kinds, statuses, events, links,
  the resource and the scope as `otel.*` attributes.
- Log levels come from the severity number, else from the severity text.
- Each metric data point is one value: a monotonic sum is a `counter`, a gauge or a non-monotonic sum is a `gauge`,
  and a histogram, exponential histogram or summary is a `distribution` whose value is the mean (`sum / count`),
  with `otel.count`, `otel.sum`, `otel.min`, `otel.max` attributes. Buckets and quantiles are dropped. The first
  exemplar gives the trace of the value.

## Memory limits

Spans of one trace often arrive in separate requests (a simple span processor exports each span on its own),
so Trap keeps received spans in memory to print them together. With [`--mcp`](mcp.md#traces) the MCP server
keeps a copy of its own for the agents. Each copy is limited by:

- `TRAP_TRACES_MAX_SPANS` - the maximum number of kept spans; the least recently updated traces are dropped
  above it, and a single trace above it keeps only its most recent spans (default: `10000`)
- `TRAP_TRACES_TTL` - seconds a trace is kept after its last received span (default: `600`)

The same limits can be set in [`trap.xml`](configuration.md#trapxml): `<traces maxSpans="10000" ttl="600"/>`.
Non-positive or non-numeric values fall back to the defaults.

The web UI keeps its own traces, logs and metrics, see [web UI limits](web-ui.md#sentry-pages).

## Saving to a file

With the [`file` sender](senders.md#file), each export request is saved as one JSON line:
`{"type":"otlp.traces","resourceSpans":[...]}`, `{"type":"otlp.logs",...}`, `{"type":"otlp.metrics",...}`.

## Notes

- Traces are given to AI agents by the [MCP server](mcp.md#traces); logs and metrics are not.
- Exporters running in a browser are not supported: Trap does not answer CORS preflight requests.
- One export request may carry up to 10 MB (after decompression) and up to 10 000 spans, log records or metric
  data points; a bigger one gets `413`. SDK batch processors export 512 spans at a time by default.
  A protobuf request is checked on the wire before it is parsed: one with groups, invalid tags or nesting deeper
  than 64 messages (about 20 levels of nested key-value lists in a log body) gets `400`; one with more than
  240 000 elements (records, items of any repeated field, unknown fields, every 8 packed values), 2 000 000
  protobuf fields or 40 MB of text (strings as JSON, where a control character takes 6 bytes, and bytes as
  base64) gets `413`. That is a Collector batch of 10 000 spans with 20 attributes each; a request within the
  limits measured up to about 230 MB of PHP memory.
  Without `ext-protobuf` the pure-PHP `google/protobuf` builds messages some 50 times slower, so the limits are
  20 000 elements, each packed value counting, 200 000 fields and 16 MB of text: an SDK batch of 512 spans,
  about 1 s. Install `ext-protobuf`, use `http/json` or export smaller batches to send more.
- A JSON request may hold up to 520 000 objects and arrays, the same batch; a bigger one gets `413` before it is
  decoded. A request within the limit measured up to about 210 MB of PHP memory.
- Port `4318` is also the default port of an OpenTelemetry Collector or Jaeger. On Linux and macOS, when it is
  taken, Trap keeps retrying to listen on it silently (run with `-vv` to see the errors) and works on the other ports.
  On Windows, Trap can listen on `127.0.0.1:4318` even while another process, such as a Collector or Jaeger in
  Docker Desktop, listens on `0.0.0.0:4318`, and then Trap receives the local traffic meant for that process.
  To leave the port to it, list the ports without `4318`:
  `vendor/bin/trap -p1025 -p8000 -p9912 -p9913` or `TRAP_TCP_PORTS=1025,8000,9912,9913`.
