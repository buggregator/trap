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

| Tool           | Arguments                                         | Result                                                                                       |
|----------------|---------------------------------------------------|----------------------------------------------------------------------------------------------|
| `list_events`  | `since` (cursor), `type`, `project`, `limit`      | `events`: `id`, `type`, `project`, `time`, `summary` each; `cursor` to pass next; `has_more` |
| `get_event`    | `id`, `max_length`                                | The event as plain text: a header line, then the event as the console prints it              |
| `clear_events` | `type`, `project`                                 | `removed`: the number of deleted events                                                      |

- `list_events` returns the events added or updated after `since`, oldest first, at most `limit` of them
  (50 by default, 200 at most). `since` is the opaque `cursor` of an earlier call; without it, or with a cursor
  of an earlier run of Trap, the tool lists all the events.
- `type` is one event type or a list of them: `var-dump`, `smtp`, `monolog`, `ray`, `sentry`, `profiler`,
  `http-dump`. Without `project` the tools take every project.
- `get_event` cuts a text longer than `max_length` characters, 50 000 by default, and says so.
- `clear_events` without arguments deletes all the events.
- A tool that returns JSON declares its `outputSchema` and sends the object as `structuredContent`,
  and as JSON text for clients that read only `content`.

### Traces

[OpenTelemetry traces](opentelemetry.md) are not events: the tools read the spans Trap keeps to join the spans of one
trace sent in separate requests, with `--mcp` alone too. The limits of that store,
[`TRAP_TRACES_MAX_SPANS` and `TRAP_TRACES_TTL`](opentelemetry.md#memory-limits), apply.

| Tool          | Arguments                                | Result                                                                                                   |
|---------------|------------------------------------------|----------------------------------------------------------------------------------------------------------|
| `list_traces` | `since` (cursor), `limit`, `errors_only` | `traces`: `id`, `name`, `service`, `spans`, `duration_ms`, `error`, `updated` each; `cursor`; `has_more` |
| `get_trace`   | `id`, `max_length`                       | The trace as plain text: a header line, then the tree of all its spans as the console prints it          |

- `list_traces` returns the traces updated after `since`, least recently updated first, at most `limit` of them
  (20 by default, 100 at most). A trace is updated by every span that arrives for it, and an updated trace is listed
  again. When `has_more` is true, call again with the returned `cursor` to get the rest.
- `name` is of the root span; while it has not arrived, of the earliest span whose parent is missing.
  `service` is the `service.name` of the resource of that span; when that resource has none, of another span.
- `errors_only` lists only the traces with a span of the error status.
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
               "summary": "array:2 [ \"hello\" => \"world\" \"n\" => 42 ]"
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
