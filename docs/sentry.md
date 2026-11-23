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
  and the day of the week are restricted, a day matching either one runs the job, as in cron. An interval counts
  `minute`, `hour`, `day`, `week`, `month` or `year` units; a day or longer keeps the wall time of the zone.
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
  seconds earlier. A run that has ended stays as it is. Another `in_progress` of a started run gives it its max
  runtime again.
- A ping `/start` answers the ID of its run in the `X-Check-In-Id` header; `/ping/{slug}` and `/fail` with
  `?check_in_id=` end that run, and without it the newest started ping run of the job. A ping body, the job's
  output, is kept, its last 10 KB.
- The cron HTTP API takes `status`, `check_in_id`, `environment` and `duration` (seconds) in the query, or a JSON
  body with these fields and `monitor_config`, and answers `202` with `{"id": "<check-in ID>"}`.
- Trap expects the next run at the first slot of the schedule after the start of the last run, or after now when
  that slot has passed. About every second it marks what did not come:
  - a slot with no check-in within the margin is `missed`, unless a run is in progress: a long run may cover a slot;
  - a started run with no end within the max runtime is `timeout`; an end that comes later keeps that status;
  - slots that fell while Trap did not run, as when the machine slept, make one `unknown` row.

  A job without a schedule is never missed, but its runs still time out.

### Limits

- Check-ins make no event. Trap keeps 200 jobs and the newest 100 runs of each; a new job pushes out the one
  that checked in least recently.
- Clearing the Sentry section deletes the runs of the project. The jobs stay, with their config, and keep
  expecting their slots. `DELETE /api/crons/{slug}?project=` on the port of the web UI deletes a job and its runs.
- Trap knows one monitor per slug and project, whatever the environment of its check-ins. It raises no issue for
  failed runs, so it ignores `failure_issue_threshold` and `recovery_threshold`.
- `/ping/...` requests are taken as check-ins on any HTTP port of Trap, so they are no longer
  [HTTP dumps](http-dump.md), unless `X-Buggregator-Event` names another module.

## Notes

- A request body may be up to 2 MB; a bigger one gets `413`.
- An event Trap can't parse is dropped, and a warning in the console tells why.
