---
name: trap
description: Debug or profile a running PHP application through Buggregator Trap and its MCP tools. Use when reading what the application sent at runtime (dumps, logs, exceptions, mails, requests, traces, profiles), pointing it at Trap, stepping through PHP code with Xdebug, calling Trap MCP tools such as `list_events`, or when code calls trap(), tr() or td().
---

# Trap

Trap is a local debug server: it catches what the application sends and keeps it in memory as **events**, which
the agent reads back through Trap's MCP tools.

## Steps

1. **Detect.** From the project root run `php <skill-dir>/scripts/detect.php`. Done when you know whether Trap is
   installed with MCP support, whether its MCP endpoint answers, which channels the project can send through, and
   which profilers PHP has. The report names the references its findings need.
2. **Start the server** when MCP doesn't answer: run `vendor/bin/trap --mcp` (or the `trap` that detect found) as a
   background process of your shell tool (`run_in_background`, `nohup … &`, `Start-Process`): it runs until
   stopped. Then run detect once more. MCP still silent, Trap missing, or its
   ports held by another process → [server.md](references/server.md); ask the user before changing their setup.
   The events live in that process only: a restart loses them.
3. **Pick the client.** With Trap's tools in your tool list (`list_events`, `get_event`…, often under a prefix such
   as `mcp__trap__`), call them. Otherwise call the same tools with `php <skill-dir>/scripts/mcp.php`, see
   [mcp.md](references/mcp.md#two-clients).
4. **Run the loop**, once per attempt:
   1. `list_events` with `{"order": "newest", "limit": 1}` → keep `cursor`;
   2. trigger the code: the test, the command, the request;
   3. `wait_for_events` with `{"since": "<cursor>", ...filter}` → the first event, at once if it is there;
   4. once the code has finished, `list_events` with `{"since": "<cursor>"}` → all the events of the run;
      `get_event` gives the full text of one.

   What to add to the code or the settings first, and how to read the result, is in the reference for the task.
5. **Clean up.** Done when a grep of the files you touched finds none of the `trap()`, `tr()`, `td()`, `dump()`,
   logger or profiling calls you added, the settings you changed to reach Trap are restored unless the user keeps
   them, and the events of your runs are deleted: `delete_event` by ID, or `clear_events` with `since` set to the
   cursor taken before your first run, and the Trap you started is stopped. The user sees the same events in the
   console and the web UI.

## References

| Reference                                         | Read when                                                                                                                         |
| ------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| [mcp.md](references/mcp.md)                       | Reading events and OpenTelemetry traces: the tools, filters, the script for agents without MCP                                    |
| [dumps-and-logs.md](references/dumps-and-logs.md) | Seeing values or the code path at runtime: `trap()`, `tr()`, `td()`, `dump()`, `trap()::logger()`                                 |
| [profiling.md](references/profiling.md)           | Finding why code is slow or takes memory: `trap()::profile()`, the profilers, reading a profile; Xdebug function traces           |
| [xdebug.md](references/xdebug.md)                 | Stopping at a line or an exception and stepping through PHP code: `--xdebug`, breakpoints, variables, `xdebug_*` tools            |
| [workers.md](references/workers.md)               | Debugging RoadRunner, Swoole, FrankenPHP, Octane or queue workers: reloads, volume, memory leaks                                  |
| [integrations.md](references/integrations.md)     | Pointing Sentry, Monolog, Ray, a mailer, OpenTelemetry or VarDumper at Trap, or a `4xx` they get; checking what a scenario sends  |
| [server.md](references/server.md)                 | Installing, starting Trap, its ports, the web UI, TLS, an application in Docker or on another host, the token, limits, `trap.xml` |

Edge cases beyond these are in the full documentation: <https://github.com/buggregator/trap/tree/master/docs>.

Events carry request bodies, headers, mails and tokens as sent: quote only what the task needs.
