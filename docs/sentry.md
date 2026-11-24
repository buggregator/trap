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
| `check_in`                            | a run of a [cron job](#crons): the Crons tab, `list_crons`                  |
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
one line for it too. An envelope of only transactions, logs, metrics, spans, feedback, check-ins or SDK bookkeeping
makes no event. Trap keeps the traces, logs, metrics, feedback and cron jobs while the
[web UI](web-ui.md#sentry-pages) or the [MCP server](mcp.md) runs.

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

## Crons

Trap watches scheduled jobs as [Sentry Crons](https://docs.sentry.io/product/crons/) does. A job reports its runs
with check-ins; Trap shows each job with its runs on the Crons tab of the [web UI](web-ui.md#sentry-pages), and
AI agents read them with [`list_crons`](mcp.md#cron-jobs). The console prints a line per check-in: the job, the
status, the duration, the monitor config it sent, and why Trap dropped the check-in or ignored its config.

A job sends check-ins in one of three ways, to any HTTP port of Trap:

| Way                  | Request                                                                                         |
|----------------------|-------------------------------------------------------------------------------------------------|
| Sentry SDK           | a `check_in` envelope item: `captureCheckIn()`, `withMonitor()`, `->sentryMonitor()` of Laravel |
| Sentry cron HTTP API | `GET` or `POST` `/api/{project_id}/cron/{slug}/{public_key}/`                                   |
| Ping URL, any script | `GET` or `POST` `/ping/{slug}` (finished), `/ping/{slug}/start`, `/ping/{slug}/fail`            |

```php
\Sentry\init(['dsn' => 'http://sentry@127.0.0.1:8000/1']);

$id = \Sentry\captureCheckIn(
    slug: 'reports-send',
    status: \Sentry\CheckInStatus::inProgress(),
    monitorConfig: new \Sentry\MonitorConfig(
        \Sentry\MonitorSchedule::crontab('0 3 * * *'),
        checkinMargin: 5,
        maxRuntime: 30,
        timezone: 'Europe/Berlin',
    ),
);
// the job
\Sentry\captureCheckIn(slug: 'reports-send', status: \Sentry\CheckInStatus::ok(), checkInId: $id);
```

```bash
curl -fsS "http://127.0.0.1:8000/ping/backup/start?schedule=0%203%20*%20*%20*&margin=5&max_runtime=30"
./backup.sh 2>&1 | curl -fsS --data-binary @- "http://127.0.0.1:8000/ping/backup"
```

### Monitors

The first check-in of a slug creates its job, the monitor. A check-in may carry its config, and as Sentry upserts
it, a field left out keeps its value and a field sent as `null` gets its default:

| Field            | Ping query    | Default | Meaning                                                                                              |
|------------------|---------------|---------|------------------------------------------------------------------------------------------------------|
| `schedule`       | `schedule`    | none    | `{"type": "crontab", "value": "0 3 * * *"}` or `{"type": "interval", "value": 10, "unit": "minute"}` |
| `checkin_margin` | `margin`      | `1`     | minutes a run may come late before its slot is missed, 1 to 40 320                                   |
| `max_runtime`    | `max_runtime` | `30`    | minutes a started run may take before it times out, 1 to 40 320                                      |
| `timezone`       | `tz`          | `UTC`   | an IANA zone, as `Europe/Berlin`, the crontab is written in                                          |

- A crontab has five fields with lists, ranges, steps, month and weekday names and `7` for Sunday, or is one of
  `@yearly`, `@annually`, `@monthly`, `@weekly`, `@daily`, `@midnight`, `@hourly`. When both the day of the month
  and the day of the week are restricted, a day matching either one runs the job, as in cron. `?` stands for a
  whole day field. An interval counts `minute`, `hour`, `day`, `week`, `month` or `year` units, up to 10 years;
  its slots count from the start of the newest run, and a day or longer keeps the wall time of the zone.
- A crontab runs on the wall time of the zone. A time skipped when clocks go forward has no run that day, and a time
  repeated when they go back has one run, unless the hour field starts with `*`: such a job runs in both hours.
- The slug becomes a Sentry slug: lower case letters, digits, `_` and `-`, at most 50 characters
  (`Reports Send` is `reports-send`).
- A check-in with a config Trap can't read, as an unknown zone or `@every 5m`, still counts and the config is
  ignored; the console says why. A ping URL or a call of the cron HTTP API with such a config gets `400`.
- The project is the `X-Buggregator-Project` header, and `?project=` of a ping URL before it.

### Runs

- `in_progress` starts a run; `ok` or `error` with the same `check_in_id` ends it, with the duration the SDK
  measured or the time between the two. An `ok` or `error` of its own is a run of its own, started `duration`
  seconds earlier. A run that has ended stays as it is. Another `in_progress` of a started run, a heartbeat, gives
  it its own max runtime again, the one the job had when the run started; a `max_runtime` in the heartbeat's
  `monitor_config` applies to the runs that start later.
- A ping `/start` answers the ID of its run in the `X-Check-In-Id` header; `/ping/{slug}` and `/fail` with
  `?check_in_id=` end that run, and without it the newest started ping run of the job. A ping body, the job's
  output, keeps its last 10 KB, whatever its size; a chunked one, as `curl --data-binary @-` sends from a pipe, up
  to `TRAP_HTTP_MAX_INFLATED_SIZE` (`64M`, see [Configuration](configuration.md#server-environment-variables)),
  above which it gets `413`.
- The cron HTTP API takes `status`, `check_in_id`, `environment` and `duration` (seconds) in the query, or a JSON
  body up to 1 MB with these fields and `monitor_config`, and answers `202` with `{"id": "<check-in ID>"}`. A
  `monitor_config` that is no object gets `400`; from an SDK it is ignored with a warning in the console.
- Trap keeps the first 64 characters of an environment, and a trace ID of 32 hex digits only.
- Trap expects the next run at the first slot of the schedule after the start of the newest run, or after now when
  that slot has passed; a run that started earlier and ends later does not move it back. A run that starts shortly
  before a crontab slot, as when the clock of its host runs ahead, is the run of that slot: less than a minute
  before it, and less than the margin or a quarter of the time since the previous slot. A run that comes late for
  the previous slot, or while that slot still waits for its run, stays the run of the previous slot. About every
  second Trap marks what did not come:
  - a slot with no check-in within the margin is `missed`, unless a run is in progress: a long run may cover a slot;
  - a started run with no end within its max runtime, the one the job had when the run started, is `timeout`; an
    end that comes later keeps that status;
  - the slots that fell while Trap did not run, as when the machine slept, make one `unknown` row, and the next
    slot is the first after Trap woke up.

  A job without a schedule is never missed, but its runs still time out.

### Limits

- Check-ins make no event. Trap keeps 200 jobs and the newest 100 runs of each; a new job pushes out the one
  that checked in least recently. The output of all the runs takes 8 MB at most: above it the oldest runs lose
  theirs.
- Clearing the Sentry section deletes the runs of the project. The jobs stay, with their config, and keep
  expecting their slots. `DELETE /api/crons/{slug}?project=` on the port of the web UI deletes a job and its runs.
- Trap knows one monitor per slug and project, whatever the environment of its check-ins. It raises no issue for
  failed runs, so it ignores `failure_issue_threshold` and `recovery_threshold`.
- `/ping/...` requests are taken as check-ins on any HTTP port of Trap, so they are no longer
  [HTTP dumps](http-dump.md), unless `X-Buggregator-Event` names another module.

## Notes

- An envelope or event body may be up to 2 MB; a bigger one gets `413`.
- An event Trap can't parse is dropped, and a warning in the console tells why.
