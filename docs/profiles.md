# Receiving profiles

Trap receives profiles of XHProf, Xdebug, Excimer (Speedscope), SPX and pprof, over HTTP or from a directory,
and shows them in the console and the [web UI](web-ui.md); AI agents analyze them with the [MCP tools](mcp.md#profiles).
To profile your own code, see [Profiling code](profiling.md). Xdebug function traces, every call with its
arguments, are a separate kind: see [Xdebug traces](xdebug-traces.md).

## Endpoints

Trap receives profiles on any of its ports:

| Endpoint                        | Format                                                                     |
|---------------------------------|----------------------------------------------------------------------------|
| `POST /api/profiler/store`      | XHProf, as Buggregator Server takes it                                     |
| `POST /api/profiler/xdebug`     | Xdebug cachegrind files                                                    |
| `POST /api/profiler/speedscope` | Speedscope JSON, e.g. from Excimer; also any JSON starting with `$schema`  |
| `POST /api/profiler/pprof`      | pprof, gzipped or not, from any language: Go, Java, Python, Rust and so on |
| `POST /api/profiler/spx`        | SPX full reports, see [SPX](spx.md#send-a-report)                          |

Speedscope, pprof and SPX take the metadata in the query: `name`, `app_name`, `hostname` and `tags[key]=value`;
Speedscope also takes `clock` and `period`. For example, a Go service:

```bash
curl -s http://localhost:6060/debug/pprof/profile?seconds=10 \
  | curl --data-binary @- 'http://127.0.0.1:9912/api/profiler/pprof?app_name=billing'
```

## Directory watching

Trap also picks up profiler files from a directory:

- `TRAP_XHPROF_PATH` - XHProf files, by default from `xhprof.output_dir`
- `TRAP_XDEBUG_PATH` - Xdebug cachegrind files, compressed (`xdebug.use_compression`) or not, by default from
  `xdebug.output_dir` while `xdebug.mode` includes `profile`
- `TRAP_SPX_PATH` - SPX full reports, by default from `spx.data_dir` when ext-spx is loaded in Trap's PHP;
  see [SPX](spx.md#watch-the-data-directory)

The defaults are the INI settings of the PHP that runs Trap. A file Trap can't read is skipped, and a warning
in the console tells why.

## Compaction

Large profiles are compacted to the memory Trap has (see `TRAP_MEMORY_LIMIT` in
[Configuration](configuration.md#server-environment-variables)): calls of the same function
are merged, deep recursion is collapsed and the coldest calls are folded into `(other)` nodes;
the profile metadata tells how much was folded. One profile is built at a time.

## Xdebug and fibers

Xdebug profiles have no record of the fiber a call ran in. Trap tells the fiber stacks apart by the fiber switches
in the profile and restores the call tree of each fiber; the function a fiber runs becomes a root of its own beside
`{main}`, and its time also counts in the `Fiber->start()` or `Fiber->resume()` calls that ran it.
Fibers that make the same calls for the same time can't be told apart, so their calls may be swapped between them.
The `fibers` metadata tells how many roots ended in fiber stacks and their time and memory; the percentages of the
[MCP tools](mcp.md#profiles) leave them out of the total. The count is approximate: a call left without a caller
in a fiber stack counts too.

When Trap can't find the calls a call lists, that call keeps their cost as its own, the calls left without a caller
are shown as roots, and the `unlinked` metadata counts such calls; the log warns about them. The cost of a listed
call that is in the profile then counts twice: in its caller and in its root.
