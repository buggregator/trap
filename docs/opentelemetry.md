# OpenTelemetry traces

Trap receives traces exported over OTLP/HTTP (`POST /v1/traces`) on any of its ports
and prints them in the console as a tree of spans.

## Receive traces

Point the exporter of the OpenTelemetry SDK at Trap:

```bash
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
```

Logs and metrics are not supported yet: Trap answers `/v1/logs` and `/v1/metrics` with success and drops them.
To send only traces, set `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://127.0.0.1:4318/v1/traces` instead of the
common endpoint, or turn the other signals off with `OTEL_LOGS_EXPORTER=none` and `OTEL_METRICS_EXPORTER=none`.

### Protobuf

The `http/protobuf` protocol needs the suggested packages:

```bash
composer require --dev open-telemetry/gen-otlp-protobuf google/protobuf
```

The Phar, the standalone binary and the Docker image do not include them, so they accept only `http/json`.

## Memory limits

Spans of one trace often arrive in separate requests (a simple span processor exports each span on its own),
so Trap keeps received spans in memory to print them together. The memory is limited by:

- `TRAP_TRACES_MAX_SPANS` - the maximum number of kept spans; the least recently updated traces are dropped
  above it, and a single trace above it keeps only its most recent spans (default: `10000`)
- `TRAP_TRACES_TTL` - seconds a trace is kept after its last received span (default: `600`)

The same limits can be set in [`trap.xml`](configuration.md#trapxml): `<traces maxSpans="10000" ttl="600"/>`.
Non-positive or non-numeric values fall back to the defaults.

## Saving to a file

With the [`file` sender](senders.md#file), each export request is saved as one JSON line:
`{"type":"otlp.traces","resourceSpans":[...]}`.

## Notes

- Traces are shown in the console only; the [web UI](web-ui.md) doesn't show them.
- Requests with `Transfer-Encoding: chunked` are not supported by the HTTP parser of Trap;
  OpenTelemetry SDK exporters send `Content-Length`.
- Exporters running in a browser are not supported: Trap does not answer CORS preflight requests.
- One export request may carry up to 10 MB (after decompression) and up to 10 000 spans; a bigger one gets `413`.
  SDK batch processors export 512 spans at a time by default.
- Port `4318` is also the default port of an OpenTelemetry Collector or Jaeger. On Linux and macOS, when it is
  taken, Trap keeps retrying to listen on it silently (run with `-vv` to see the errors) and works on the other ports.
  On Windows, Trap can listen on `127.0.0.1:4318` even while another process, such as a Collector or Jaeger in
  Docker Desktop, listens on `0.0.0.0:4318`, and then Trap receives the local traffic meant for that process.
  To leave the port to it, list the ports without `4318`:
  `vendor/bin/trap -p1025 -p8000 -p9912 -p9913` or `TRAP_TCP_PORTS=1025,8000,9912,9913`.
