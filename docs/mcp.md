# MCP server

The `--mcp` option of [`run`](cli.md#run) starts a [Model Context Protocol](https://modelcontextprotocol.io) server
inside Trap, so AI agents such as Claude Code can connect to it. The CLI marks it experimental.

```bash
vendor/bin/trap --mcp
```

The server answers at <http://127.0.0.1:8000/mcp> over the Streamable HTTP transport. Add it to Claude Code:

```bash
claude mcp add --transport http trap http://127.0.0.1:8000/mcp
```

Other clients take the same URL with the `http` (Streamable HTTP) transport type.

## Tools

The tools read the events Trap has caught and the [traces](#traces) it has received. The events are the ones the
[web UI](web-ui.md) shows, kept with `--mcp` alone too. An event deleted in the UI is gone for the agent. An event
the agent deletes is gone from the UI on its next load; a page already open keeps showing it.

| Tool           | Arguments                                                                        | Result                                                                                                         |
|----------------|----------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------|
| `list_events`  | `since` (cursor), [filters](#filters), `order`, `include`, `max_length`, `limit` | `events`: `id`, `type`, `project`, `time`, `summary`, `fields`, `text` each; `cursor` to pass next; `has_more` |
| `get_event`    | `id`, `max_length`                                                               | The event as plain text: a header line, then the event as the console prints it                                |
| `clear_events` | [filters](#filters)                                                              | `removed`: the number of deleted events                                                                        |

- `list_events` returns the events added or updated after `since`, oldest first, at most `limit` of them
  (50 by default, 200 at most). `since` is the opaque `cursor` of an earlier call; without it, or with a cursor
  of an earlier run of Trap, the tool lists all the events.
- `order: newest` returns the latest `limit` matching events, newest first. Then `has_more` tells that older
  matching events were left out, and `cursor` still points at the latest change: the next call with it gets only
  the events changed after this one.
- `fields` of an event are the ones [`where`](#filters) filters by; an event without fields has no `fields`.
- `include: text` adds `text` to each event: its text as `get_event` gives it, without the header line, cut to
  `max_length` characters, 2 000 by default.
- `get_event` cuts a text longer than `max_length` characters, 50 000 by default, and says so.
- `clear_events` without arguments deletes all the events.
- A tool that returns JSON declares its `outputSchema` and sends the object as `structuredContent`,
  and as JSON text for clients that read only `content`.

### Filters

`list_events` and `clear_events` take the same filter. An event must match all the arguments given.

| Argument  | Matches                                                                                                                          |
|-----------|----------------------------------------------------------------------------------------------------------------------------------|
| `type`    | One event type or a list: `var-dump`, `smtp`, `monolog`, `ray`, `sentry`, `profiler`, `http-dump`                                |
| `project` | One project or a list; events sent without a project belong to `default`                                                         |
| `from`    | Events caught at this time or later: ISO 8601 (UTC without an offset) or a duration back from now: `30s`, `5m`, `2h`, `1d`, `1w` |
| `to`      | Events caught at this time or earlier, in the same forms                                                                         |
| `search`  | Events whose summary or text, as `get_event` gives it, contains this text in any case                                            |
| `regex`   | `true` makes `search` a PCRE pattern without delimiters, matched in any case                                                     |
| `where`   | Events whose fields match all the given ones                                                                                     |

- An ISO 8601 time stands for the whole span of its last written unit: `to: "2026-11-16"` takes the events of that
  whole day, `to: "2026-11-16T10:00"` those of 10:00 too. So the `time` of a listed event, in milliseconds, passed
  back as `from` or `to` takes that event too. A duration is exact.
- `search` looks through the first 64 KB of the text of each event: the texts are kept for the next searches,
  and a longer one would make every search render it again.
- In `where`, a string matches the whole value in any case, and `*` stands for any characters. A number matches
  an equal number. A list of values matches when any of them does. A field with a list of values, such as `to`,
  matches when any of its values does. An event without the field does not match, so a field of another type
  matches nothing. The `from` field of a mail is its sender, unrelated to the `from` time.
- A condition that takes nothing fails the call rather than being left out: an empty list of `type` or `project`,
  `where` without fields, `regex` without `search`. Otherwise `clear_events` would delete every event.
- A pattern that hits a PCRE limit, as nested quantifiers on a long text do, fails the call, and `clear_events`
  deletes nothing then.

| Type        | Fields                                                                                                                            |
|-------------|-----------------------------------------------------------------------------------------------------------------------------------|
| `var-dump`  | `label`, `value_type`, `language`, `file`                                                                                         |
| `monolog`   | `level`, `channel`                                                                                                                |
| `sentry`    | `level`, `exception` (the types of the chain), `environment`, `release`, `transaction`, `server_name`, `status` (of the response) |
| `smtp`      | `subject`, `from`, `to`, `cc`, `bcc` (the addresses, or the names without one)                                                    |
| `http-dump` | `method`, `path`, `host`                                                                                                          |
| `ray`       | `payload_type`, `exception`, `label`, `color`                                                                                     |
| `profiler`  | `name` (the application or the file), `format`                                                                                    |

The `list_events` description lists the fields too. Examples:

- Errors in the last 5 minutes: `{"from": "5m", "where": {"level": "error"}}`: the log records and the Sentry
  events of the `error` level.
- A dump containing `order_id`, with its text: `{"type": "var-dump", "search": "order_id", "include": "text"}`.
- POST requests to `/api/orders*`: `{"type": "http-dump", "where": {"method": "POST", "path": "/api/orders*"}}`.
- The latest mail to a customer: `{"where": {"to": "*@example.com"}, "order": "newest", "limit": 1}`.

### Traces

[OpenTelemetry traces](opentelemetry.md) are not events: the tools read the spans Trap keeps to join the spans of one
trace sent in separate requests, with `--mcp` alone too. The limits of that store,
[`TRAP_TRACES_MAX_SPANS` and `TRAP_TRACES_TTL`](opentelemetry.md#memory-limits), apply.

| Tool          | Arguments                                                                                                       | Result                                                                                                   |
|---------------|-----------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------|
| `list_traces` | `since` (cursor), `limit`, `errors_only`, `from`, `to`, `service`, `name`, `min_duration_ms`, `search`, `regex` | `traces`: `id`, `name`, `service`, `spans`, `duration_ms`, `error`, `updated` each; `cursor`; `has_more` |
| `get_trace`   | `id`, `max_length`                                                                                              | The trace as plain text: a header line, then the tree of all its spans as the console prints it          |

- `list_traces` returns the traces updated after `since`, least recently updated first, at most `limit` of them
  (20 by default, 100 at most). A trace is updated by every span that arrives for it, and an updated trace is listed
  again. When `has_more` is true, call again with the returned `cursor` to get the rest.
- `name` is of the root span; while it has not arrived, of the earliest span whose parent is missing.
  `service` is the `service.name` of the resource of that span; when that resource has none, of another span.
- `errors_only` lists only the traces with a span of the error status.
- `from` and `to` take the times of [the event filter](#filters) and match the time of the last update.
- `service` is one service or a list, and `name` a name; both match in any case, with `*` for any characters.
- `min_duration_ms` lists only the traces that last that long or longer.
- `search` and `regex` match as in [the event filter](#filters), over the spans: their names, status messages,
  attributes, and events, such as an exception with its message, and over the attributes of their resources,
  one `key=value` line per attribute.
- Slow traces of the last hour: `{"from": "1h", "min_duration_ms": 500}`; failed checkouts:
  `{"errors_only": true, "name": "POST /checkout*"}`.
- `get_trace` cuts a text longer than `max_length` characters, 50 000 by default, and says so.

### Example

The agent remembers where the buffer is, runs the code, and reads what the code sent:

1. `list_events` → `{"events": [...], "cursor": "5f3a9c1e:12", "has_more": false}`
2. The agent runs the tests or sends a request to the application.
3. `list_events` with `{"since": "5f3a9c1e:12"}` → only the new events:
   ```json
   {
       "events": [
           {
               "id": "0b9e4a1c-...",
               "type": "var-dump",
               "project": "default",
               "time": "2026-11-16T10:10:42.033+00:00",
               "summary": "array:2 [ \"hello\" => \"world\" \"n\" => 42 ]",
               "fields": {"value_type": "array", "file": "src/Checkout.php"}
           }
       ],
       "cursor": "5f3a9c1e:13",
       "has_more": false
   }
   ```
4. `get_event` with `{"id": "0b9e4a1c-..."}` → the full dump:
   ```
   var-dump event 0b9e4a1c-... at 2026-11-16T10:10:42.033+00:00, project default
   array:2 [ "hello" => "world" "n" => 42 ]

   DUMP

      Time: 2026-11-16 10:10:42
      File: src/Checkout.php:42

   array:2 [
     "hello" => "world"
     "n" => 42
   ]
   ```

## Port

The MCP port is `8000` by default. Change it with `--mcp=<port>`, `TRAP_MCP_PORT` or
[`trap.xml`](configuration.md#trapxml); the environment variable wins.

| Setting | Variable        | `trap.xml`        | Default     |
|---------|-----------------|-------------------|-------------|
| Port    | `TRAP_MCP_PORT` | `/trap/mcp/@port` | `8000`      |
| Host    | `TRAP_MCP_HOST` | `/trap/mcp/@host` | `127.0.0.1` |

- When the MCP port is one of the TCP ports (`8000` is), the server shares it with the rest of the traffic,
  and the port listens on `TRAP_TCP_HOST`.
- Otherwise the server gets a port of its own, which listens on `TRAP_MCP_HOST`.

The [web UI](web-ui.md) and the MCP server can share a port: `vendor/bin/trap --ui --mcp` serves both on `8000`.
On a port of their own, the one without a host set takes the host of the other; two different hosts fail the startup.

## Notes

- The server is stateless and answers with JSON only: it does not open SSE streams, and `GET /mcp` gets `405`.
- A request with an `Origin` header of any host but `localhost`, `127.0.0.1` or `[::1]` gets `403`, so a web page
  can't reach the server through DNS rebinding.
- Protocol versions `2025-11-25`, `2025-06-18` and `2025-03-26` are supported.
