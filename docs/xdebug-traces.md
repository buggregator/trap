# Xdebug function traces

Trap receives [Xdebug function traces](https://xdebug.org/docs/trace): every call of a request with its time,
memory, arguments and return value. The console prints a summary; the [web UI](web-ui.md) shows the call tree
level by level and searches it; AI agents walk it with the [MCP tools](mcp.md#xdebug-traces).

Traces must be in the computer-readable format: `xdebug.trace_format=1`. Other formats are rejected.

```ini
xdebug.mode=trace
xdebug.trace_format=1
xdebug.start_with_request=trigger
; optional: return values and assignments
xdebug.collect_return=1
xdebug.collect_assignments=1
```

## Send a trace

`POST` the `.xt` or `.xt.gz` file to any TCP port of Trap (not to a port of the web UI alone), to any path, with
the header
`X-Buggregator-Event: xdebug-trace`, as to Buggregator Server:

```bash
curl -H 'X-Buggregator-Event: xdebug-trace' \
     -H 'X-Buggregator-Trace-Url: GET /users' \
     --data-binary @/tmp/traces/trace.1234.xt.gz \
     http://127.0.0.1:8000/
```

`xdebug-trace` as the user of the URL works too: `http://xdebug-trace@127.0.0.1:8000/`.
Optional headers:

- `X-Buggregator-Trace-Name` - a name for the trace;
- `X-Buggregator-Trace-Url` - the request the trace was recorded for, shown as the title;
- `X-Buggregator-Project` - the project of the event.

Trap answers `200` once the trace is stored, `400` for a file that is not a trace of the format 1, `413` for
a file larger than `TRAP_XDEBUG_TRACE_MAX_UPLOAD`, and `501` for a gzipped file when ext-zlib is not loaded in
Trap. A request body sent chunked or with `Content-Encoding: gzip` is decoded first and cut at
`TRAP_HTTP_MAX_INFLATED_SIZE` (`64M`, see [Configuration](configuration.md#server-environment-variables)): send a
larger trace as a plain body, a `.xt.gz` file as it is.

## Directory watching

Trap also picks up the traces Xdebug writes, `*.xt` and `*.xt.gz` files:

- from `TRAP_XDEBUG_TRACE_PATH`,
- else from `xdebug.output_dir` while `xdebug.mode` (or `XDEBUG_MODE`) includes `trace`.

The defaults are the INI settings of the PHP that runs Trap. A trace is taken once Xdebug writes its `TRACE END`
line; files written before Trap started are skipped. The file stays where it is: Trap copies it. A trace that never
gets its `TRACE END`, as when the script died, is not taken: send it over HTTP to see it cut. Gzipped traces need
ext-zlib in Trap's PHP; without it they are skipped with a warning.

## Limits

A trace of a framework request has 10^5–10^6 lines, so Trap never holds one in memory: it keeps the file as sent in
a temporary directory of its process, `trap-xdebug-traces-*` in the system temp directory, and reads it again for each
level of the tree or search. The directory is removed when Trap stops; one left by a killed Trap is removed when
the next one starts. The watcher never takes the files of these directories, even when it watches the temp directory.

| Variable                       | `trap.xml`                      | Default | Meaning                                                                       |
|--------------------------------|---------------------------------|---------|-------------------------------------------------------------------------------|
| `TRAP_XDEBUG_TRACE_MAX_UPLOAD` | `/trap/xdebugTraces/@maxUpload` | `100M`  | Size of a trace as sent, gzipped or not; a larger one is rejected             |
| `TRAP_XDEBUG_TRACE_MAX_SIZE`   | `/trap/xdebugTraces/@maxSize`   | `256M`  | Uncompressed bytes read of a trace; a longer one is cut there                 |
| `TRAP_XDEBUG_TRACE_KEEP`       | `/trap/xdebugTraces/@keep`      | `20`    | Traces kept; a new one deletes the oldest, its event too                      |

Sizes take the [size format](configuration.md#size-format); an unreadable value falls back to the default.
A deleted event takes its file with it, and so does an event pushed out of the
[event limit](configuration.md#trapxml). A watched trace older than all the kept events makes no event; its file
stays until newer traces push it out.

A trace is marked cut (`truncated`) when it hit `TRAP_XDEBUG_TRACE_MAX_SIZE` or has calls left open without
`TRACE END`, as when the script died. A trace stopped by `xdebug_stop_trace()` is whole: the calls around the
traced part are not in it.

## Web UI endpoints

The trace page of the UI reads these endpoints; errors are `{"error": "..."}` with an HTTP status.

| Endpoint                                              | Answer                                                                    |
|-------------------------------------------------------|---------------------------------------------------------------------------|
| `GET /api/xdebug-traces/{uuid}/summary`               | `name`, `url`, `size`, `compressed` and the `summary`                     |
| `GET /api/xdebug-traces/{uuid}/tree?parent=&from=&limit=` | One level of calls: `parent`, `nodes`, `next`, `truncated`            |
| `GET /api/xdebug-traces/{uuid}/search?q=&from=&limit=`    | The calls whose function or file contains `q`, each with its `path`  |

`parent` and `from` are the `offset` of a call; without `parent`, the tree lists the top-level calls, and `from`
continues a page at its `next`. `limit` is 200 by default and 1 000 at most.
