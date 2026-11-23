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
| `transaction` with op `queue.process` | a trace and an attempt of a [queue job](#queue-jobs)                        |
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
one line for it too. An envelope of only transactions, logs, metrics, spans, feedback or SDK bookkeeping makes no
event. Trap keeps the traces, logs, metrics and feedback while the [web UI](web-ui.md#sentry-pages) or the
[MCP server](mcp.md) runs.

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
  pinned error when the clear keeps the pinned events. Trap keeps the last 1 000; above that the oldest 10% go,
  the feedback on a pinned error too.

## Queue jobs

The Queues tab of the [web UI](web-ui.md#sentry-pages) and the MCP tool [`list_queue_jobs`](mcp.md#queue-jobs) show
the jobs queue workers ran. They are read from the stored traces on every request, so they follow the trace limits
and "Clear all" of the Sentry section.

- An attempt is one run of a job: a transaction or span with op `queue.process`. sentry-laravel sends one per job
  while tracing is on (its `queue_job_transactions` option; `queue_jobs` for the sync driver). sentry-python 2
  sends a `queue.process` span per Celery task; sentry-python 3 wraps that span in a `queue.process` segment
  without `messaging.*`, which Trap leaves out, so the task counts once. An OpenTelemetry `CONSUMER` span with
  `messaging.system` is an attempt too, unless its `messaging.operation.type` (or the older `messaging.operation`)
  is other than `process` or `deliver`: a span that receives or settles a message runs no job.
- A job is the attempts with the same `messaging.message.id` in one project, taken by one consumer: the
  `messaging.consumer.group.name`, else the service of an OTLP span. A message delivered to several consumer
  groups or services makes a job for each. Sentry data without a group has no consumer: its `server_name` is the
  host, and retries run on any host. An attempt without a message ID is a job of its own. The name of a job is the
  transaction name or span description (the job class in Laravel), else the description of the `queue.publish`
  span of the message.
- A job's ID is its project, consumer (when it has one) and message ID, each URL-encoded, joined by `/`, as
  `default/6c54ce88-826c-45bc-94cd-52297cf7c63e`; the attempt ID for a job without a message ID. The job page
  opens by it, of any period.
- An attempt fails as Sentry's failure rate counts it: with any status but `ok`, `cancelled` and `unknown`; a
  missing status is no failure. The Traces page marks a span failed only for `internal_error`, `unknown_error` and
  `error`, so an attempt `deadline_exceeded` fails its job but shows no error on the trace. A job fails when its last
  attempt fails; Sentry doesn't send whether the worker will retry it. Running jobs don't show: an SDK sends an
  attempt when it ends.
- Attempts are ordered by their number, the `messaging.message.retry.count` plus one, so the clocks of several
  workers can't change which one is last; an attempt without a number goes by its start before the first numbered
  one that started later. An attempt shows its number, raised past the one before it when that is not higher, so
  numbers don't repeat. `attempts` counts the runs of the message: the number of the last attempt, so the stored
  attempts, or more when an attempt's number says Trap missed earlier ones. A job is retried
  when it ran more than once, failed or not. `failed_attempts` counts the stored attempts that failed.
- The queue is `messaging.destination.name` (or the older `messaging.destination`), the connection
  `messaging.destination.connection`, both of the last attempt; the job's status, duration and time in the queue
  are those of its last attempt too.
- The time in the queue is `messaging.message.receive.latency`. The Sentry conventions and sentry-python send
  milliseconds, sentry-laravel sends seconds: Trap takes seconds for Sentry data with `messaging.system`
  `laravel`, and milliseconds for every other Sentry SDK and for every OTLP span. sentry-laravel measures it from
  the first dispatch, so a retry's time in the queue includes the earlier attempts.
- An error event shows on one attempt of its project and trace, of any job:
  - the attempt its span is in, the attempt's own span or a span inside it, up to the segment of its service: a
    task one Celery task queues has its own segment, so its exception stays off the task that queued it;
  - else an attempt inside its span, in the same service: sentry-python captures a Celery task's exception in the
    segment around the `queue.process` span;
  - else, for a span Trap has not, a failed attempt that span is the parent of: sentry-laravel's sync driver runs
    the job inside a `queue.publish` span it never ends, so never sends, and reports the job's exception with it.
    The transaction that ran the job, a request or a command, keeps that span as its current one, so any error it
    reports within a second after a failed sync job goes to that job too;
  - else, for a span Trap has not, an attempt with the same parent span: sentry-laravel reports a queued job's
    exception after it ends the attempt, with such a span and the `queue.publish` span of the message as the
    parent, which tells apart the jobs one request queued.

  Out of several such attempts, the error goes to the failed one that ended nearest before its `timestamp`, up to
  a second before: every SDK captures a job's exception right after it ends the attempt and marks it failed.
  Else it goes to one that ran at its `timestamp`, a failed one first, then the latest started. An attempt that
  ended `ok` gets no error captured after it. The jobs a command queues without a sampled transaction share its
  trace and parent, and concurrent workers run them at once.
- The request that queued a job is the transaction with the `queue.publish` span of its message ID, else the one
  holding the parent span of its attempt.
- A value of the wrong type, a negative or fractional retry count or a negative latency counts as missing.

The list and the summary take the project, the period (the end of the last attempt), the queue, the job name and
the status: `ok` or `failed` by the last attempt, or `retried`. The summary's average duration is the mean of the
last attempts' durations, the Duration column of the list.

## Notes

- A request body may be up to 2 MB; a bigger one gets `413`.
- An event Trap can't parse is dropped, and a warning in the console tells why.
