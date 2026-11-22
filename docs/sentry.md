# Sentry

Trap receives the events of [Sentry SDKs](https://docs.sentry.io/platforms/) and shows the errors with their stack
traces. Point the DSN of the SDK at Trap.

## Receive events

Use any HTTP port of Trap, e.g. `8000`. The public key and the project ID of the DSN can be anything:

```bash
SENTRY_DSN=http://sentry@127.0.0.1:8000/1
```

In Laravel, the variable is `SENTRY_LARAVEL_DSN`.

Trap takes two kinds of requests:

| Request                                                                                                        | Sent by      |
|----------------------------------------------------------------------------------------------------------------|--------------|
| `POST …/envelope/` with `Content-Type: application/x-sentry-envelope`                                          | current SDKs |
| `POST …/store/` with the `X-Sentry-Auth` header, the `sentry` user in the URL or `X-Buggregator-Event: sentry` | older SDKs   |

With [`--mcp`](mcp.md#sentry-events), AI agents read the events with their exception chains and code lines.

## What an envelope gives

| Envelope item                         | Becomes                                                                     |
|---------------------------------------|-----------------------------------------------------------------------------|
| `event`, `feedback`                   | a `sentry` event: the console, the event feed of the web UI, the MCP server |
| `transaction`                         | a trace: the Traces page of the web UI, the MCP trace tools                 |
| `transaction` without a trace ID      | a `sentry` event, as `event` is: no trace can hold it                       |
| `span`, `spans` (Span v2)             | spans of a trace; a segment span is the transaction of its service          |
| `log`                                 | log records on the Logs page of the web UI                                  |
| `trace_metric`                        | metric values on the Metrics page of the web UI                             |
| sessions, client reports, attachments | nothing                                                                     |

The event feed holds the errors. A transaction is a trace, not an event, also when an older SDK sends it to
`…/store/`: the console prints one line for it, with its name, duration, number of spans (the transaction included),
the failed ones and the trace ID, and AI agents read it with [`list_traces` and `get_trace`](mcp.md#traces). Spans
sent without a transaction get the same line, named after their root span. An envelope of an error and the
transaction of its request gives the error event and the trace. A `/store` payload with an exception is an error,
whatever its type. A transaction without a trace ID fits no trace and stays a `sentry` event; the console prints
one line for it too. An envelope of only transactions, logs, metrics, spans or SDK bookkeeping makes no event. Trap
keeps the traces, logs and metrics while the [web UI](web-ui.md#sentry-pages) or the [MCP server](mcp.md) runs.

The `X-Buggregator-Project` header puts the event and the data of the request into that project of the web UI;
without it they land in `default`.

## Notes

- A request body may be up to 2 MB; a bigger one gets `413`.
- An event Trap can't parse is dropped, and a warning in the console tells why.
