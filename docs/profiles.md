# Receiving profiles

Trap receives profiles of XHProf, Xdebug, Excimer (Speedscope), SPX and pprof, over HTTP or from a directory,
and shows them in the console and the [web UI](web-ui.md).
To profile your own code, see [Profiling code](profiling.md).

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
- `TRAP_XDEBUG_PATH` - Xdebug cachegrind files, by default from `xdebug.output_dir` while `xdebug.mode`
  includes `profile`
- `TRAP_SPX_PATH` - SPX full reports, by default from `spx.data_dir` when ext-spx is loaded in Trap's PHP;
  see [SPX](spx.md#watch-the-data-directory)

The defaults are the INI settings of the PHP that runs Trap.

## Compaction

Large profiles are compacted to the memory Trap has (see `TRAP_MEMORY_LIMIT` in
[Configuration](configuration.md#server-environment-variables)): calls of the same function
are merged, deep recursion is collapsed and the coldest calls are folded into `(other)` nodes;
the profile metadata tells how much was folded. One profile is built at a time.
