# Reading events

Trap's MCP server answers at `http://127.0.0.1:8000/mcp` while Trap runs with `--mcp`. Its tools read the events
Trap has caught and the OpenTelemetry traces it has received. The full reference:
<https://github.com/buggregator/trap/blob/master/docs/mcp.md>.

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

| Tool              | Does                                                                                      |
|-------------------|-------------------------------------------------------------------------------------------|
| `list_events`     | The events changed after `since`, oldest first; with `order: newest` the latest ones      |
| `wait_for_events` | Waits until `count` events (1 by default) match, up to `timeout` seconds (30, at most 50) |
| `get_event`       | One event as plain text: the full dump, log record, exception, mail, request              |
| `delete_event`    | Deletes events by `id` or `ids`                                                           |
| `clear_events`    | Deletes the events matching a filter; without arguments, all of them                      |
| `list_traces`     | OpenTelemetry traces, with filters by service, name, duration, errors                     |
| `get_trace`       | One trace as a tree of its spans                                                          |
| `profile_*`       | Analyze a `profiler` event, see [profiling.md](profiling.md#read-the-profile)             |
| `xdebug_trace_*`  | Walk the call tree of an `xdebug-trace` event, see [profiling.md](profiling.md#trace-the-calls) |

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

| Argument  | Matches                                                                                                |
|-----------|--------------------------------------------------------------------------------------------------------|
| `type`    | One or a list of `var-dump`, `monolog`, `sentry`, `smtp`, `http-dump`, `ray`, `profiler`               |
| `project` | One or a list; events sent without a project belong to `default`                                       |
| `from`    | Caught at this time or later: ISO 8601 in UTC, or back from now in lower case: `30s`, `5m`, `2h`, `1d` |
| `to`      | Caught at this time or earlier, in the same forms                                                      |
| `search`  | Text in the summary or the text of the event, in any case; `regex: true` makes it a PCRE pattern       |
| `where`   | Fields of the event, below                                                                             |

| Type        | Fields                                                                                                          |
|-------------|-----------------------------------------------------------------------------------------------------------------|
| `var-dump`  | `label` (the name of a named argument), `value_type`, `language`, `file`                                        |
| `monolog`   | `level`, `channel`                                                                                              |
| `sentry`    | `level`, `exception` (the types of the chain), `environment`, `release`, `transaction`, `server_name`, `status` |
| `smtp`      | `subject`, `from`, `to`, `cc`, `bcc`                                                                            |
| `http-dump` | `method`, `path`, `host`                                                                                        |
| `ray`       | `payload_type`, `exception`, `label`, `color`                                                                   |
| `profiler`  | `name`, `format`                                                                                                |

A `where` string matches the whole value in any case, with `*` for any characters; a list matches any of its
values. A field no type has fails the call.

- Errors of the last 5 minutes, logs and Sentry: `{"from": "5m", "where": {"level": "error"}}`
- POST requests to the orders API: `{"type": "http-dump", "where": {"method": "POST", "path": "/api/orders*"}}`
- The latest mail to a customer: `{"type": "smtp", "where": {"to": "*@example.com"}, "order": "newest", "limit": 1}`

## Traces

Traces are not events: `list_traces` takes its own `since` cursor, and `from`, `to`, `service`, `name`, `errors_only`,
`min_duration_ms`, `search`. A trace is listed again with every span that arrives for it. `get_trace` gives the
span tree with durations and attributes. Slow traces of the last hour: `{"from": "1h", "min_duration_ms": 500}`.
