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

The tools read the events Trap has caught, the [traces](#traces) it has received and the call trees of
[Xdebug traces](#xdebug-traces). The events are the ones the
[web UI](web-ui.md) shows, kept with `--mcp` alone too. An event deleted in the UI is gone for the agent. An event
the agent deletes is gone from the UI on its next load; a page already open keeps showing it.

| Tool              | Arguments                                                                          | Result                                                                                                         |
| ----------------- | ---------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| `list_events`     | `since` (cursor), [filters](#filters), `order`, `include`, `max_length`, `limit`   | `events`: `id`, `type`, `project`, `time`, `summary`, `fields`, `text` each; `cursor` to pass next; `has_more` |
| `wait_for_events` | `since` (cursor), [filters](#filters), `count`, `timeout`, `include`, `max_length` | As `list_events`, and `timed_out`; see [Waiting for events](#waiting-for-events)                               |
| `get_event`       | `id`, `max_length`                                                                 | The event as plain text: a header line, then the text of its module, as for a Sentry event, or the console one |
| `delete_event`    | `id`, or `ids` (200 at most)                                                       | `deleted`: the number of deleted events; `not_found`: the IDs of no stored event, when some are                |
| `clear_events`    | `since` (cursor), [filters](#filters)                                              | `removed`: the number of deleted events; `incomplete`, `cursor` when the call [ran out of time](#time-limit)   |

- `list_events` returns the events added or updated after `since`, oldest first, at most `limit` of them
  (50 by default, 200 at most). `since` is the opaque `cursor` of an earlier call; without it, or with a cursor
  of an earlier run of Trap, the tool lists all the events.
- `order: newest` returns the latest `limit` matching events, newest first. Then `has_more` tells that older
  matching events were left out, and `cursor` still points at the latest change: the next call with it gets only
  the events changed after this one.
- `fields` of an event are the ones [`where`](#filters) filters by; an event without fields has no `fields`.
- `include: text` adds `text` to each event: its text as `get_event` gives it, without the header line, cut to
  `max_length` characters, 2 000 by default and 50 000 at most. The texts of one response take 500 000 characters
  at most: the call stops before the event that would exceed that, with `has_more: true`. With `order: oldest`
  the returned `cursor` goes on from that event; with `order: newest` it still points at the latest change, and
  the events left out are the older ones, as past `limit`.
- `get_event` cuts a text longer than `max_length` characters, 50 000 by default, and says so. A Sentry event has
  [a text of its own](#sentry-events).
- `delete_event` deletes the events of the given IDs. The store keeps no trace of a deleted event, so an ID deleted
  before, pushed out by newer events or mistyped is the same: it is listed in `not_found`, and a call that finds none
  of its IDs fails. A repeated call deletes nothing more, so the tool is marked idempotent.
- `clear_events` without arguments deletes all the events, the ones pinned in the web UI too. A malformed `since` is an error, so a typo doesn't
  turn "the newer ones" into all of them.
- An argument a tool doesn't declare, such as a misspelt `sinse`, fails the call with the list of the arguments
  the tool takes, and nothing runs. Ignored, it would widen the call: `clear_events` would delete every event.
- A tool that returns JSON declares its `outputSchema` and sends the object as `structuredContent`,
  and as JSON text for clients that read only `content`.

### Filters

`list_events`, `wait_for_events` and `clear_events` take the same filter. An event must match all the arguments given.

| Argument  | Matches                                                                                                                          |
| --------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `type`    | One event type or a list: `var-dump`, `smtp`, `monolog`, `ray`, `sentry`, `profiler`, `http-dump`, `xdebug-trace`                |
| `project` | One project or a list; events sent without a project belong to `default`                                                         |
| `from`    | Events caught at this time or later: ISO 8601 (UTC without an offset) or a duration back from now: `30s`, `5m`, `2h`, `1d`, `1w` |
| `to`      | Events caught at this time or earlier, in the same forms                                                                         |
| `search`  | Events whose summary or text, as `get_event` gives it without the header line, contains this text in any case                    |
| `regex`   | `true` makes `search` a PCRE pattern without delimiters, matched in any case                                                     |
| `where`   | Events whose fields match all the given ones                                                                                     |

- An ISO 8601 time stands for the whole span of its last written unit: `to: "2026-11-16"` takes the events of that
  whole day, `to: "2026-11-16T10:00"` those of 10:00 too. So the `time` of a listed event, in milliseconds, passed
  back as `from` or `to` takes that event too. A duration is exact.
- `search` looks through the summary and only the first 64 KB of the text of each event: the texts are kept for
  the next searches, and a longer one would make every search render it again. The cache holds that much for
  every event the buffer keeps ([`maxEvents`](configuration.md#trapxml)), but at least 16 MB and at most 256 MB.
  While an agent uses the tools, and for 5 minutes after its last call, the texts of new events are rendered in
  the background, so a search usually finds them ready. Events larger than 256 KB are left out of that: rendering
  one stalls the server for a noticeable time, so a search renders it when it gets to it.
- In `where`, a string matches the whole value in any case, and `*` stands for any characters. A number matches
  an equal number. A list of values matches when any of them does. A field with a list of values, such as `to`,
  matches when any of its values does. An event without the field does not match, so a field of another type
  matches nothing; a field no type has fails the call, and so does a boolean value. The `from` field of a mail is
  its sender, unrelated to the `from` time.
- A duration takes its unit in lower case: `5M` fails the call rather than meaning 5 minutes. A duration longer
  than 100 years fails the call too.
- A condition that takes nothing fails the call rather than being left out: an empty list of `type` or `project`,
  `where` without fields, `regex` without `search`. Otherwise `clear_events` would delete every event.
- A pattern that hits a PCRE limit, as nested quantifiers on a long text do, fails the call, and `clear_events`
  deletes nothing then.

#### Time limit

Rendering the text of a large dump for `search` takes milliseconds, so a search over many large events can take
seconds. Trap catches events and answers other requests meanwhile, and a call stops checking events after 5 seconds:

- `list_events` returns the matching events found so far with `has_more: true`, even when they are fewer than
  `limit`. With `order: oldest` the returned `cursor` goes on from the first event left unchecked: call again with
  it as `since`. With `order: newest` the cursor still points at the latest change, so call again without it:
  the texts rendered so far are kept, and the next search gets further.
- `clear_events` deletes the matching events found so far and returns `incomplete: true` with a `cursor`: call
  again with the same filter and that cursor as `since` to delete the rest.
- An event deleted or changed while the call runs is left out: the changed one comes with the next call that
  passes the cursor, and `clear_events` deletes only the events that have not changed since they matched.
- A call goes on until it is done or out of time even when the client disconnects or cancels it;
  only [a wait](#waiting-for-events) stops then, in the middle of a check too.
- Rendering the summaries counts too: a plain `list_events` over many very large events not rendered
  yet may return fewer than `limit` events with `has_more: true`.

| Type        | Fields                                                                                                                            |
| ----------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `var-dump`  | `label`, `value_type`, `language`, `file`                                                                                         |
| `monolog`   | `level`, `channel`                                                                                                                |
| `sentry`    | `level`, `exception` (the types of the chain), `environment`, `release`, `transaction`, `server_name`, `status` (of the response) |
| `smtp`      | `subject`, `from`, `to`, `cc`, `bcc` (the addresses, or the names without one)                                                    |
| `http-dump` | `method`, `path`, `host`                                                                                                          |
| `ray`       | `payload_type`, `exception`, `label`, `color`                                                                                     |
| `profiler`  | `name` (the application or the file), `format`                                                                                    |

An `xdebug-trace` event has no fields: its summary starts with the URL or the name of the trace, so `search` finds it.
The `list_events` description lists the fields too. Examples:

- Errors in the last 5 minutes: `{"from": "5m", "where": {"level": "error"}}`: the log records and the Sentry
  events of the `error` level.
- A dump containing `order_id`, with its text: `{"type": "var-dump", "search": "order_id", "include": "text"}`.
- POST requests to `/api/orders*`: `{"type": "http-dump", "where": {"method": "POST", "path": "/api/orders*"}}`.
- The latest mail to a customer: `{"where": {"to": "*@example.com"}, "order": "newest", "limit": 1}`.

### Waiting for events

`wait_for_events` saves the agent a loop of `list_events` calls while the code it has triggered runs. The result
is what `list_events` with the same arguments, `order: oldest` and `limit: count` returns once it holds `count`
events (1 by default, 50 at most), plus `timed_out: false`. After `timeout` seconds (30 by default, 50 at most)
the result is what that call returns then, possibly no events, with `timed_out: true`: it is not an error.

- Without `since` the wait counts only the events changed after it starts. The agent calls it after the command
  that triggers the code, so the events sent before that are missed. Take a `cursor` with `list_events` before
  triggering the code, and pass it as `since`: the events that came in between count, and a wait for the ones
  already there returns at once.
- `cursor` and `has_more` are those of `list_events`: pass the returned `cursor` to `list_events` or to the next
  wait, and no event is missed.
- An event updated while the call waits, as a Ray message updated by the application, is listed once, at its
  latest change. An event deleted, or updated so that it no longer matches, is not listed and doesn't count.
- When the texts of `include: text` fill the result before `count` events, the call returns at once with
  `has_more: true`, as more events would not fit.
- With a short `timeout`, or `0`, the events already there after an older `since` still get the full
  [time limit](#time-limit) to be checked, as with `list_events`. A check that runs out of time goes on at once,
  getting further each time, as the events already checked are not checked again.
- The durations of `from` and `to`, such as `5m`, count back from the start of the wait.
- A wait inside a JSON-RPC batch fails: the batch is answered once all its calls are done, so a wait would hold
  up the rest. Send each wait as a request of its own.
- The MCP TypeScript SDK and Codex give up on a call after 60 seconds by default, so the timeout stays below that.
  To wait longer, wait again with the returned cursor.
- A waiting call costs nothing until an event arrives: Trap catches events and answers other calls meanwhile,
  other waits too. Only a change gets the events checked again, and then only the new and changed ones go
  through the filter; a `search` over large events checks them a slice at a time, as under the
  [time limit](#time-limit).
- The wait stops when the client closes the connection, as clients do on cancelling a call, even in the middle
  of a check, and returns nothing to it. A client that sends only `notifications/cancelled` and keeps the
  connection open gets the wait running until it is done or out of time: the stateless server does not match the
  notification to the call.

The agent runs the tests and waits for the error they log:

1. `list_events` with `{"order": "newest", "limit": 1}` → `{"events": [...], "cursor": "5f3a9c1e:12", ...}`.
2. The agent starts the tests.
3. `wait_for_events` with `{"since": "5f3a9c1e:12", "type": "monolog", "where": {"level": "error"}}`
   → as soon as the record arrives, or at once if it already has:

   ```json
   {
       "events": [
           {
               "id": "7c1d2e3f-...",
               "type": "monolog",
               "project": "default",
               "time": "2026-11-16T10:10:42.033+00:00",
               "summary": "ERROR app: Payment declined",
               "fields": {"level": "ERROR", "channel": "app"}
           }
       ],
       "cursor": "5f3a9c1e:14",
       "has_more": false,
       "timed_out": false
   }
   ```

4. To catch what the tests send next, the agent waits again with `{"since": "5f3a9c1e:14", ...}`.

### Sentry events

`get_event` gives a [Sentry](sentry.md) event as a compact text to fix the error from, rather than as the console
prints it:

- The metadata, one `Name: value` line each: event ID, type when not `event`, level, message, transaction and its
  duration, environment, release, server, platform, logger, SDK, fingerprint, tags and user. The message is
  `logentry.formatted`, else `message`, else `logentry.message`.
- The exception chain: the thrown exception first, then the ones that caused it down to the root cause, each with
  its type, value and mechanism (`handled` or `unhandled`). An exception without frames of its own, as native SDKs
  send it, gets those of its thread, or of the crashed one. An event without exceptions gets the stack trace of the
  event, or of its crashed or current thread.
- The stack frames, innermost first and numbered from `#0`: file and line, the function. The innermost frame and the
  application frames come with the lines around their line, the absolute path and the local variables when the SDK
  sends them. Frames of libraries are those with `in_app: false` and those under a `vendor`, `node_modules` or
  `site-packages` directory, as some SDKs mark every frame in-app; a run of two or more of them is one line,
  `… 23 vendor frames #2-#24 …`. When all the frames are of libraries, none are collapsed.
- The outermost frames a cause shares with the exception above it are one line, `… 20 frames in common with the
  exception above …`, and a frame already shown with its code is not shown with it again.
- The request: method, URL, query, headers without cookies and the headers of the browser (`sec-*`, `accept-encoding`,
  `accept-language`, `connection` and the like), body. The values of the headers and query parameters (of the URL
  and its fragment too, at any depth of a query map) whose names contain `authorization` (`authorisation`),
  `authentication`, `token`, `secret`, `password` (`passwd`), `api-key` (`api_key`, `api key`, `apikey`) or `cookie`
  in any case, and the password of a URL, are `[Filtered]`. A password with a raw `/`, `?` or `#` in it, not
  percent-encoded, may still show in part. The headers `Referer`, `Location`, `Origin` and `Content-Location` are
  filtered as URLs, and so are URLs in query values, encoded or not, as of a redirect, and in the strings of a query
  map or of a URL sent as a list at any depth; a value nested in more than 5 such URLs, or 32 levels of a query map,
  is `[Filtered]` whole. Of such a map or list, and of a header sent as a list, the items past 20 of a level or past
  the first 2400 bytes are counted. The first 4 KB of a URL or a query string are filtered, more than its line shows.
  The body and the other sections are shown as sent. The query is one line of up to 300 characters; the request is
  at most 16 KB with its body, and the headers past that are left out and counted.
- The contexts, such as `runtime`, `os` and `trace` with its IDs, one line each; the latest breadcrumbs, oldest first;
  the extra data; the spans of a transaction with their durations.
- An envelope of several items shows each of them under `--- Item 2 of 3: attachment ---`: an attachment by its
  name, type and size; an item that is not an event, such as `session`, `client_report` or `check_in`, by its
  fields in one line. The web UI shows the first item only. A search looks through the first 64 KB of the whole
  envelope, so the details of later items may not be found.

The sections come in this order: the metadata, the exception chain, the request, the contexts, the breadcrumbs, the
extra data and the spans. A [search](#filters) looks through the first 64 KB of the text, so the causes that would
take the chain past 32 KB are left out and counted; past less than 32 KB when the metadata and the request are
large, so that the contexts and the breadcrumbs start within the 64 KB. A thrown exception larger than that is shown
without the code and variables of its frames, and then with fewer frame lines; so is the stack trace of an event
without exceptions.

Bounds; what is left out is counted, as `… 5 more …`:

| What                                            | At most                                  |
| ----------------------------------------------- | ---------------------------------------- |
| Exceptions of a chain                           | 10 in 32 KB or less, see above           |
| Frame lines of a stack trace                    | 30                                       |
| Frames shown with their code                    | 3 per stack trace                        |
| Lines of code around the line of a frame        | 3 before, 3 after                        |
| Variables of a frame                            | 10                                       |
| Request headers                                 | 15 in 16 KB of the request with its body |
| Breadcrumbs                                     | the last 10                              |
| Tags, user fields, contexts, extra entries      | 20                                       |
| Spans                                           | 20                                       |
| Items of an envelope                            | 20                                       |
| Characters of an exception value, message, body | 2 000                                    |
| Characters of another value                     | 300; of a code line, 200                 |

The modules (installed packages) are left out. The fields of a Sentry event for `where` are in [Filters](#filters).
A Sentry event of a shape the text fails on is shown as its summary and the error, not as its payload, which would
show the secrets; a search looks through that text only.

```text
sentry event 0b9e4a1c-... at 2026-11-16T10:10:42.033+00:00, project default
RuntimeException: Error when getting value.

Event ID: 8beb0492c70349d28242468e7028cfa6
Level: error
Environment: production
Platform: php
SDK: sentry.php 4.10.0

Exception chain of 2, the thrown one first, then what caused it; stack frames innermost first:
RuntimeException: Error when getting value.
  Mechanism: generic, unhandled
  #0 src/Console/DoNothing.php:57 in App\Console\DoNothing::getValue
     path: /app/src/Console/DoNothing.php
     54 |     try {
     55 |         $_SERVER['VAR_DUMPER_FORMAT'];
     56 |     } catch (\Throwable $e) {
   > 57 |         throw new \RuntimeException('Error when getting value.', 69, previous: $e);
     58 |     }
  #1 src/Console/DoNothing.php:41 in App\Console\DoNothing::__invoke
     ...
  … 23 vendor frames #2-#24 …
  #25 app.php:33
Caused by ErrorException: Warning: Undefined array key "VAR_DUMPER_FORMAT"
  ...

Contexts:
  runtime: name=php, version=8.4.1
  trace: trace_id=f6214e4aba254e41b6688c9b3a9d88b7, span_id=5da59e19760b4293
```

### Traces

[OpenTelemetry traces](opentelemetry.md) are not events: the tools read the spans Trap keeps to join the spans of one
trace sent in separate requests, with `--mcp` alone too. The limits of that store,
[`TRAP_TRACES_MAX_SPANS` and `TRAP_TRACES_TTL`](opentelemetry.md#memory-limits), apply.

| Tool          | Arguments                                                                                                       | Result                                                                                                   |
| ------------- | --------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
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

### Profiles

The [profiles](profiles.md) are `profiler` events. `get_event` gives one as the console prints it; these tools let
an agent dig into it without reading the whole call graph.

| Tool                 | Arguments                                              | Result                                                                                                        |
| -------------------- | ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------- |
| `profile_summary`    | `id`                                                   | `format`, `metrics`, `not_measured`, `peaks`, `functions`, `edges`, the `top` 10 functions and the `hot_path` |
| `profile_top`        | `id`, `metric`, `sort`, `filter`, `limit`              | `functions`: `function`, `calls`, `inclusive`, `exclusive` and their percentages each; `matched`, `has_more`  |
| `profile_calls`      | `id`, `function`, `metric`, `depth`, `limit`           | The function's row, its `callers` and `callees` with the calls and costs between them                         |
| `profile_call_graph` | `id`, `metric`, `threshold`, `max_depth`, `max_length` | The call tree as plain text, only the calls of at least `threshold` percent                                   |

The metrics are the ones the console shows; a profiler that doesn't measure one leaves it out, and a tool asked
for it fails with the metrics the profile has:

| Metric | Measures                           | Formats                                                                            |
| ------ | ---------------------------------- | ---------------------------------------------------------------------------------- |
| `wt`   | Wall time, µs                      | XHProf, Xdebug, SPX, pprof and Speedscope of a CPU or time profile                 |
| `cpu`  | CPU time, µs                       | XHProf and SPX when enabled, pprof of a CPU profile, Speedscope with the CPU clock |
| `mu`   | Memory, bytes                      | XHProf and SPX when enabled, Xdebug, pprof and Speedscope of a memory profile      |
| `pmu`  | Peak memory, bytes                 | XHProf when enabled                                                                |
| `ct`   | Calls, or samples of a sampled one | All; `count_type` of `profile_summary` tells what it counts                        |

- Without `metric` the tools take the first the profile has of `wt`, `cpu`, `mu`, `ct`, as the console ranks by.
- A function's values are summed over all its calls. `inclusive` takes the callees in, `exclusive` is the function
  itself. The percentages are of the `peaks`, the largest values of one caller and callee pair as the console shows
  them: the root's wall time, CPU time and memory. A profile without `main()` gets a root that sums the times of
  its calls and takes the most memory of them, and its `peaks` are the root's values. With several roots, of the call
  graph or of a recorded call tree, the peak is the sum of the roots when larger. A percentage is at most 100, even
  when the sum over the callers passes the peak. `ct` has no percentages: its peak is the most calls or samples of
  one pair, while a function sums the calls from all its callers. XHProf's `mu` is the memory a call allocated less
  what it freed: a negative value is shown as zero, and an `exclusive` one as at most the `inclusive` one.
- `profile_top` ranks by the `exclusive` value (`sort: inclusive` for the other), 20 functions by default and 200
  at most. `filter` keeps the functions whose names contain the text in any case; with `*`, it matches the whole
  name: `App\*` keeps the application code.
- `profile_calls` takes the exact name, as `profile_top` gives it; an unknown name fails with the close ones.
  A caller row tells how much the function cost in the calls from that caller, a callee row how much the callee
  cost in the calls from the function. The `exclusive` value of a function called from several places is split
  by their shares of its `inclusive` one. `depth` (1 to 3) adds the callers of the callers and the callees of the
  callees, each with `via`: past the first level the values are of all the calls between the two functions.
- The hot path follows the heaviest call from the root down to a leaf; a path longer than 30 calls keeps its first
  5 and last 25, and `depth` tells where each one is.
- `profile_call_graph` shows calls of at least 1 percent by default, 30 levels deep, and cuts a text longer than
  `max_length` characters, 20 000 by default and 200 000 at most. The smaller calls of a caller are summed in one
  line; the roots are shown whatever their share. XHProf sums the calls by caller and callee, so its tree is
  unfolded from the call graph: a function called from several places has its callees split by their shares, and
  the text says so; a tree that would unfold into too many calls is cut, and the text says so: it keeps the calls
  in the order they were made up to a limit and may leave out later ones, heavy ones too. The hot path of
  `profile_summary` and the callers and callees of `profile_calls` work on the call graph, so they show the
  heaviest calls of such a profile too.
- The functions of the 4 profiles asked about last are kept: summing those of a large profile takes a moment, and
  Trap catches events and answers other calls meanwhile.

The agent profiles a slow request and finds what makes it slow:

1. `list_events` with `{"order": "newest", "limit": 1}` → the `cursor`; the agent sends the request with
   XHProf on.
2. `wait_for_events` with `{"since": "5f3a9c1e:12", "type": "profiler"}` → the profile event, `"id": "9d0e..."`.
3. `profile_summary` with `{"id": "9d0e..."}` → wall time `"peaks": {"wt": 45310, ...}`, the top functions and the
   hot path, ending in `App\Repository\OrderRepository::findAll`.
4. `profile_top` with `{"id": "9d0e...", "metric": "wt", "filter": "App\\*"}` → the application functions by their
   own wall time:

   ```json
   {
       "metric": "wt",
       "unit": "microseconds",
       "peak": 45310,
       "sort": "exclusive",
       "matched": 41,
       "functions": [
           {
               "function": "App\\Repository\\OrderRepository::hydrate",
               "calls": 1200,
               "inclusive": 18200,
               "inclusive_percent": 40.168,
               "exclusive": 15400,
               "exclusive_percent": 33.988
           }
       ],
       "has_more": true
   }
   ```

5. `profile_calls` with `{"id": "9d0e...", "function": "App\\Repository\\OrderRepository::hydrate"}` → it is
   called 1 200 times from `OrderRepository::findAll`, once per row: the agent batches the hydration.

### Xdebug traces

The [Xdebug traces](xdebug-traces.md) are `xdebug-trace` events: list them with `list_events` and `type:
xdebug-trace`. These tools read the call tree from the trace file. Times are seconds, memory bytes.

| Tool                   | Arguments                                  | Result                                                                                                       |
| ---------------------- | ------------------------------------------ | ------------------------------------------------------------------------------------------------------------ |
| `xdebug_trace_summary` | `uuid`                                     | `name`, `url`, `size` and the `summary`: totals, the `top` 20 functions by self time, the 20 `slowest` calls |
| `xdebug_trace_calls`   | `uuid`, `parent`, `from`, `query`, `limit` | `nodes`: one level of the call tree, or the calls matching `query` with their `path`; `next`; `truncated`    |
| `xdebug_trace_call`    | `uuid`, `offset`                           | `call`: one call with its arguments and return value; its first 50 `callees`; `from` for the rest            |

- A call is known by its `offset` in the trace. Without `parent`, `xdebug_trace_calls` lists the top-level calls;
  with it, the direct callees of that call. A non-null `next` means more: call again with `from` set to it.
- `query` matches the function name or the file, case-insensitively; each match has `path`, its callers from the top.
- `limit` is 50 by default and 200 at most. Arguments and return values are cut to 512 bytes.
- A trace pushed out by newer ones (`TRAP_XDEBUG_TRACE_KEEP`) has no file any more: the tools say so.

### Xdebug debugger

With [`--xdebug`](xdebug.md), these tools read and drive the step-debugging sessions; without it they fail and say
how to turn it on. Each call on a session goes to its agent log, which the Xdebug page of the UI shows with the
name the MCP client gave in `initialize`.

| Tool                 | Arguments                                                | Result                                                                                        |
| -------------------- | -------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| `xdebug_sessions`    | `live_only`                                              | `sessions`: `id`, `state`, `driver`, the request or command, where it is paused; the settings |
| `xdebug_wait`        | `timeout`                                                | The first paused session, or `timed_out`                                                      |
| `xdebug_location`    | `session_id`, `context_lines`                            | `file`, `line`, the function and the source around the line                                   |
| `xdebug_stack`       | `session_id`                                             | `frames`: `level`, `where`, `file`, `line`                                                    |
| `xdebug_variables`   | `session_id`, `name`, `frame`, `context`, `page`         | The variables of a frame, or the value of `name` with its children                            |
| `xdebug_eval`        | `session_id`, `expression`                               | The value; only with `TRAP_XDEBUG_ALLOW_EVAL`                                                 |
| `xdebug_continue`    | `session_id`, `command`, `wait`                          | `step_into`, `step_over`, `step_out`, `run`, `stop` or `detach`, then the next pause          |
| `xdebug_breakpoints` | `action`, `file`, `line`, `condition`, `exception`, `id` | The saved breakpoints; `action: set` or `remove` changes them                                 |

- Without `session_id`, the tools take the only paused session.
- `xdebug_continue` and changing breakpoints work on the sessions Trap drives (`driver: here`); an IDE steps its own.
  `TRAP_XDEBUG_ALLOW_CONTROL=0` turns them off for agents.
- A usual run: `xdebug_breakpoints` with `{"action": "set", "file": "/app/src/Checkout.php", "line": 42}`, start
  the request, `xdebug_wait`, then `xdebug_location`, `xdebug_variables` and `xdebug_continue`.

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

   ```text
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
| ------- | --------------- | ----------------- | ----------- |
| Port    | `TRAP_MCP_PORT` | `/trap/mcp/@port` | `8000`      |
| Host    | `TRAP_MCP_HOST` | `/trap/mcp/@host` | `127.0.0.1` |

- When the MCP port is one of the TCP ports (`8000` is), the server shares it with the rest of the traffic,
  and the port listens on `TRAP_TCP_HOST`.
- Otherwise the server gets a port of its own, which listens on `TRAP_MCP_HOST`.

The [web UI](web-ui.md) and the MCP server can share a port: `vendor/bin/trap --ui --mcp` serves both on `8000`.
On a port of their own, the one without a host set takes the host of the other; two different hosts fail the startup.

## Security

The tools read everything Trap has caught, mails and request bodies with their secrets too, and delete events.

- By default the server listens on `127.0.0.1`, so only the local machine reaches it.
- A request with an `Origin` header of any host but `localhost`, `127.0.0.1` or `[::1]` gets `403`, so a web page
  can't reach the server through DNS rebinding.
- To reach Trap from a Docker container, WSL or another machine, the port has to listen on another host:
  `TRAP_MCP_HOST=0.0.0.0`, or `TRAP_TCP_HOST=0.0.0.0` when the MCP port is a TCP one (see [Port](#port)).
  Then set a token too: anyone who reaches the port can call the tools otherwise. At startup Trap warns when the
  server listens on a host other than a loopback one (`127.0.0.0/8`, `::1` and `::ffff:127.0.0.0/104`,
  `localhost`) without a token.
- The token guards `/mcp` only. The [web UI](web-ui.md) on the same port, its `/api/...` and its WebSocket, asks
  for none and shows the same events: on a host beyond the loopback, anyone who reaches the port reads them there.
  Trap warns of the UI at startup too, token or not. Keep the UI on a loopback port of its own (`TRAP_UI_PORT`,
  `TRAP_UI_HOST`), or leave it off.

| Setting | Variable         | `trap.xml`         | Default |
| ------- | ---------------- | ------------------ | ------- |
| Token   | `TRAP_MCP_TOKEN` | `/trap/mcp/@token` | none    |

There is no CLI option for it, as the command line shows in the process list. With a token set, every request
to `/mcp` must carry it:

```http
Authorization: Bearer <token>
```

```bash
export TRAP_MCP_TOKEN="$(openssl rand -hex 32)"
TRAP_MCP_HOST=0.0.0.0 vendor/bin/trap --mcp=8001

claude mcp add --transport http trap http://192.168.1.10:8001/mcp --header "Authorization: Bearer $TRAP_MCP_TOKEN"
```

Other clients take the header in their server settings, as `"headers": {"Authorization": "Bearer ..."}`.

- A request without the token or with another scheme gets `401` with `WWW-Authenticate: Bearer` and no JSON-RPC
  body; one with a wrong token gets `WWW-Authenticate: Bearer error="invalid_token"`. The `Bearer` scheme is matched
  in any case. This check goes first: a request without the token gets `401` for a foreign `Origin`, `GET` or
  a broken body too, so it learns nothing of the server. With the token, the `Origin` check and the others follow.
- There is no OAuth: the server publishes no authorization metadata for a client to discover, and the token is set
  by hand on both sides.
- The path is matched loosely: `//mcp`, `/MCP` and `/mcp/` are `/mcp` too.
- Any other HTTP/1.x request of a standard method (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS`,
  `TRACE`, `CONNECT`) to a Trap HTTP port, traffic ports included, that carries the token in a header name or value
  (whatever the header or scheme) or in the path or query (raw or percent-encoded) is never caught: it gets `404`
  (or `400` if its target or headers can't be parsed) before any module sees it, unless the [web UI](web-ui.md)
  answers it (its pages, `/api/...`, its WebSocket) on a port it shares. So a mistyped URL or port never has
  the token dumped to the console, the UI, the files or the MCP tools. This holds with the MCP server off too, as
  long as a token is set. The first such request of a run is logged as a warning with its method and path, a part
  that holds the token left out, the next ones only with `-vv`.
- The token is matched as a plain substring, so pick a long random one: with a short or common token, ordinary
  requests that happen to contain it are answered `404` too. Trap warns at startup about a token shorter than
  16 characters.
- Other streams on a traffic port, such as an HTTP/2 connection, a lower-case or another method, are not read
  as HTTP: they are printed raw to the console, and written by the `file-body` sender, token included.
- The request body is not searched: a token sent in a body is caught with the request as any other data.
- An empty `TRAP_MCP_TOKEN` asks for no token, even when `trap.xml` sets one: the environment variable wins.
- Over plain HTTP the token is sent in plain text: on a network you don't trust, connect over [HTTPS](tls.md) or an
  SSH tunnel.

## Notes

- The server is stateless and answers with JSON only: it does not open SSE streams, and `GET /mcp` gets `405`.
- Protocol versions `2025-11-25`, `2025-06-18` and `2025-03-26` are supported.
