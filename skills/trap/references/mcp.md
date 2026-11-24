# Reading events

Trap's MCP server answers at `http://127.0.0.1:8000/mcp` while Trap runs with `--mcp`. Its tools read the events
Trap has caught, the Sentry and OpenTelemetry traces it has received and the Xdebug sessions of `--xdebug`. The full
reference: <https://github.com/buggregator/trap/blob/master/docs/mcp.md>.

## Two clients

With Trap's tools in your tool list, call them directly. Otherwise `scripts/mcp.php` of this skill calls the same
tools over plain HTTP and prints the result: the JSON of a tool that returns an object, else its text. It exits
with `1` and the message when the call fails.

```bash
php <skill-dir>/scripts/mcp.php tools                  # the tool names
php <skill-dir>/scripts/mcp.php tools list_events      # the description and argument schema of one
php <skill-dir>/scripts/mcp.php list_events order=newest limit=1
php <skill-dir>/scripts/mcp.php wait_for_events since=5f3a9c1e:12 type=monolog where.level=error
php <skill-dir>/scripts/mcp.php get_event id=0b9e4a1c-...
echo '{"type": ["sentry", "monolog"], "from": "5m"}' | php <skill-dir>/scripts/mcp.php list_events -
```

- `key=value` takes numbers, `true`, `false` and `null` as such and anything else as a string; dots nest:
  `where.level=error`.
- `key:=<json>` takes raw JSON: a list, or a string of digits as `search:='"42"'` in bash, `'search:="42"'` in
  PowerShell.
- One JSON object as the only argument, or `-` to read it from stdin; pipe it from stdin in Windows PowerShell 5.1,
  which mangles quotes in arguments.
- An argument the tool doesn't take fails the call before it is sent, so a typo can't widen a `clear_events`.
- `TRAP_MCP_URL` (default `http://127.0.0.1:8000/mcp`), or `TRAP_MCP_PORT` alone, and `TRAP_MCP_TOKEN` select
  the server, as for Trap itself.

Any other HTTP client works too: the server is stateless and needs no `initialize` or session. POST a JSON-RPC
`tools/call` to `/mcp` and read `result.structuredContent`, or `result.content[0].text` of a tool that returns
text.

## Tools

The argument schema of each tool is in its `inputSchema` (`mcp.php tools <name>`).

| Tool              | Does                                                                                                  |
| ----------------- | ----------------------------------------------------------------------------------------------------- |
| `list_events`     | The events changed after `since`, oldest first; with `order: newest` the latest ones                  |
| `wait_for_events` | Waits until `count` events (1 by default) match, up to `timeout` seconds (30, at most 50)             |
| `get_event`       | One event as plain text: the full dump, log record, exception, mail, request, CSP report              |
| `delete_event`    | Deletes events by `id` or `ids`                                                                       |
| `clear_events`    | Deletes the events matching a filter, or all; pinned ones stay unless `include_pinned: true`          |
| `list_traces`     | Sentry and OpenTelemetry traces; filters by project, source, service, name, duration, errors          |
| `get_trace`       | One trace as a tree of its spans and transactions, under its findings such as N+1 queries             |
| `list_queue_jobs` | Queue jobs from `queue.process` attempts: retries, failures, errors, time in the queue                |
| `list_feedback`   | Sentry user feedback, newest first; by `project` or `event_id` of the error it is about               |
| `list_crons`      | Cron jobs of Sentry Crons check-ins and ping URLs with their newest runs, see [Cron jobs](#cron-jobs) |
| `profile_*`       | Analyze a `profiler` event, see [profiling.md](profiling.md#read-the-profile)                         |
| `xdebug_*`        | Step debugging with Xdebug, see [xdebug.md](xdebug.md)                                                |
| `xdebug_trace_*`  | Walk the call tree of an `xdebug-trace` event, see [profiling.md](profiling.md#trace-the-calls)       |

## Reading

- Read after the trigger with `wait_for_events` rather than `list_events`: an event reaches the buffer a moment
  after the code that sent it has finished. The wait returns at once when the events are already there. A wait
  for `count: 1` returns with the first event; once the code has finished, `list_events` with the same `since`
  gives all of them.
- A summary is one line, cut at about 160 characters, with the `{placeholders}` of a log message filled in; the
  full text is in `get_event` or `include: "text"`.
- `timed_out: true`: nothing matching arrived within `timeout`; trigger again or widen the filter. To wait past 50
  seconds, wait again with the returned cursor.
- `include: "text"` adds the texts to a list, cut to `max_length` characters (2 000 by default).
- Pass the returned `cursor` as `since` for the next run. A cursor of an earlier Trap process lists all the events.
- A Sentry event comes from `get_event` as a compact text: the exception chain with code around the innermost and the
  application frames, vendor frames collapsed, then the request, contexts and breadcrumbs.

## Filters

`list_events`, `wait_for_events` and `clear_events` take the same filter; an event matches all the arguments given.

| Argument  | Matches                                                                                                         |
| --------- | --------------------------------------------------------------------------------------------------------------- |
| `type`    | One or a list of `var-dump`, `monolog`, `sentry`, `smtp`, `http-dump`, `ray`, `profiler`, `xdebug-trace`, `csp` |
| `project` | One or a list; events sent without a project belong to `default`                                                |
| `from`    | Caught at this time or later: ISO 8601 in UTC, or back from now in lower case: `30s`, `5m`, `2h`, `1d`, `1w`    |
| `to`      | Caught at this time or earlier, in the same forms                                                               |
| `search`  | Text in the summary or the text of the event, in any case; `regex: true` makes it a PCRE pattern                |
| `where`   | Fields of the event, below                                                                                      |

| Type        | Fields                                                                                                          |
| ----------- | --------------------------------------------------------------------------------------------------------------- |
| `var-dump`  | `label` (the name of a named argument), `value_type`, `language`, `file`                                        |
| `monolog`   | `level`, `channel`                                                                                              |
| `sentry`    | `level`, `exception` (the types of the chain), `environment`, `release`, `transaction`, `server_name`, `status` |
| `smtp`      | `subject`, `from`, `to`, `cc`, `bcc`                                                                            |
| `http-dump` | `method`, `path`, `host`                                                                                        |
| `ray`       | `payload_type`, `exception`, `label`, `color`                                                                   |
| `profiler`  | `name`, `format`                                                                                                |
| `csp`       | `report_type`, `directive`, `blocked`, `disposition`, `page`, `source_file`, see [csp.md](csp.md)               |

A `where` string matches the whole value in any case, with `*` for any characters; a list matches any of its
values. A field no type has fails the call. An `xdebug-trace` has no fields: `search` finds it by its URL or name.
A mail with an invite or another version of its body has no field for it: `{"type": "smtp", "search": "invite"}`
finds it by its summary, see [integrations.md](integrations.md#mail).

- Errors of the last 5 minutes, logs and Sentry: `{"from": "5m", "where": {"level": "error"}}`
- POST requests to the orders API: `{"type": "http-dump", "where": {"method": "POST", "path": "/api/orders*"}}`
- The latest mail to a customer: `{"type": "smtp", "where": {"to": "*@example.com"}, "order": "newest", "limit": 1}`

## Traces

Traces are not events: a Sentry transaction and OpenTelemetry spans are both traces, and `list_traces` lists them
together, each with its `services`, `sources` (`sentry`, `otel`) and `projects`. It takes its own `since` cursor, and
`project`, `source`, `from`, `to`, `service`, `name`, `errors_only`, `min_duration_ms`, `search`; `service: "*"`
takes a trace without services too. A trace is listed again with every span that arrives for it. `get_trace` gives
the span tree with durations and attributes; a Sentry span is named `op: description`. The trace ID of a Sentry
error, in its `trace` context, opens the trace of the failed request. Slow traces of the last hour:
`{"from": "1h", "min_duration_ms": 500}`. The least recently updated traces are dropped once the kept spans and
transactions pass 10 000 (`TRAP_TELEMETRY_MAX_SPANS`), and clearing the Sentry pages of the web UI drops them too.
OpenTelemetry logs and metrics have no MCP tool.

`findings` in `list_traces` counts the problems `get_trace` lists above the tree, heaviest first. `n_plus_one` is
one SQL query (Redis and other NoSQL stores don't count), values shown as `?`, run 5 times or more in one
transaction: the line gives the span count, the summed ms, the transaction, `from file:line` when the spans carry
`code.filepath` (sentry-laravel sends it for slow queries only) and the first span `id:` to find in the tree. Fix it in that code: load the rows in one query
(eager loading, `WHERE id IN (…)`), then rerun and check that the finding is gone. The query is `db.query.text`,
`db.statement` or the span description; spans without a transaction count under the one up their parents, else
under the parent span that has not arrived. With `project`, only the spans of those projects count.

## Queue jobs

`list_queue_jobs` answers why a job failed or was retried: it groups the attempts (transactions and spans with op
`queue.process`: sentry-laravel, sentry-python Celery, OpenTelemetry spans that process a message) by
`messaging.message.id` into jobs of one project and consumer (`messaging.consumer.group.name`, else the OTLP
service), the latest finished first. A job fails when its last attempt fails with any status but `ok`, `cancelled`
and `unknown`; whether the worker retries it is not sent. `attempts` counts the runs, also those Trap missed when an
attempt reports earlier retries; `failed_attempts` the stored ones that failed. `latency_ms` is the time in the queue
before the last attempt, always milliseconds (sentry-laravel's seconds are converted). Each job holds
`dispatched_by`, the transaction that queued it, and its latest 10 attempts with their `trace_id` for `get_trace` and
errors with `uuid` for `get_event`; an error shows on one attempt only. Filters: `project`, `id` (a job's `id`:
`project/consumer/message-id`, without `consumer/` when it has none), `queue`, `job` (the class), `status` (`ok`,
`failed`, `retried`), `from`, `to` (the end of the last attempt). The jobs live in the stored traces: the same
limits and clearing.

## User feedback

Sentry user feedback (`feedback` and `user_report` items) is not an event either: `wait_for_events` never sees it,
an envelope of only feedback included, so read it with `list_feedback`. `event_id` takes the Sentry event ID of an
error, with or without dashes; `error_uuid` in the result is that error's `id` for `get_event`. The last 1 000
entries are kept, pinned errors or not. Clearing the Sentry pages of the web UI drops them too, but the feedback on
a pinned error when the clear keeps the pinned events.

## Cron jobs

Cron check-ins are not events either: `wait_for_events` never sees them, so read the jobs with `list_crons`
(`project`, `slug` as the job sent it or as Trap keeps it, `attention: true` for the jobs whose last run failed,
timed out or was missed, `check_ins`: the newest runs of each, 5 by default, 0 to 100). A job reports
`in_progress`, `ok` and `error`; Trap writes `missed` (no check-in within `checkin_margin` minutes of a slot),
`timeout` (started, no end within `max_runtime` minutes) and `unknown` (the slot fell while Trap was not running).
A downtime makes one `unknown` row, and the next slot is the first after Trap woke up. A run that starts less than a
minute before a crontab slot, and less than the margin or a quarter of the time since the previous slot, is the run
of that slot: the clock of its host runs ahead. A run late for the previous slot, or while it still waits for its
run, stays the run of the previous slot. A heartbeat (another `in_progress` of a started run) gives the run its
own max runtime again; a new `max_runtime` applies to the runs that start later. `schedule` reads
`crontab 0 3 * * *` or `every 10 minutes`; a job without one is never missed. A run's `output` is the last 1 024
bytes of a ping body; the web UI shows up to 10 KB. Trap keeps 200 jobs with their newest 100 runs and 8 MB of
output in all, the oldest runs losing theirs first; clearing the Sentry pages of the web UI drops the runs and keeps
the jobs.
