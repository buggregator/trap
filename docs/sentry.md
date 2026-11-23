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
| `event`                               | a `sentry` event: the console, the event feed of the web UI, the MCP server |
| `feedback`, `user_report`             | [user feedback](#user-feedback): the Sentry pages, `list_feedback`          |
| `transaction`                         | a trace: the Traces page of the web UI, the MCP trace tools                 |
| `transaction` without a trace ID      | a `sentry` event, as `event` is: no trace can hold it                       |
| `span`, `spans` (Span v2)             | spans of a trace; a segment span is the transaction of its service          |
| `log`                                 | log records on the Sentry › Logs page of the web UI                         |
| `trace_metric`                        | metric values on the Sentry › Metrics page of the web UI                    |
| sessions, client reports, attachments | nothing                                                                     |

The event feed holds the errors. A transaction is a trace, not an event, also when an older SDK sends it to
`…/store/`: the console prints one line for it, with its name, duration, number of spans (the transaction included),
the failed ones and the trace ID, and AI agents read it with [`list_traces` and `get_trace`](mcp.md#traces). Spans
sent without a transaction get the same line, named after their root span. An envelope of an error and the
transaction of its request gives the error event and the trace. A `/store` payload with an exception is an error,
whatever its type. A transaction without a trace ID fits no trace and stays a `sentry` event; the console prints
one line for it too. An envelope of only transactions, logs, metrics, spans, feedback or SDK bookkeeping makes no event. Trap
keeps the traces, logs and metrics while the [web UI](web-ui.md#sentry-pages) or the [MCP server](mcp.md) runs.

The `X-Buggregator-Project` header puts the event and the data of the request into that project of the web UI;
without it they land in `default`.

## User feedback

What users write in the Sentry feedback widget (a `feedback` item of the User Feedback API) and legacy `user_report`
items make no event, as in the Buggregator Server. The console prints who wrote one, the page, the error and the
message; the
[web UI](web-ui.md#sentry-pages) shows them on the Feedback tab of the Sentry section and on the page of the error
they are about, and AI agents read them with [`list_feedback`](mcp.md#user-feedback).

- A `feedback` item gives the name, email, message and page URL of `contexts.feedback`, the name and email of
  `user` when the form has none, and the error of `contexts.feedback.associated_event_id`. A `user_report` gives
  `name`, `email`, `comments` and the error of `event_id`.
- Feedback belongs to the project of its envelope. Clearing the Sentry section deletes it, but the feedback on a
  pinned error when the clear keeps the pinned events. Trap keeps the last 1 000; above that the oldest 10% go.

## Notes

- A request body may be up to 2 MB; a bigger one gets `413`.
- An event Trap can't parse is dropped, and a warning in the console tells why.
