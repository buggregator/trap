# Receiving profiles

Trap receives profiles of XHProf, Xdebug, Excimer (Speedscope), SPX and pprof, over HTTP or from a directory,
the stack samples of [phpspy and reli-prof](#phpspy-and-reli-prof) and collapsed stacks over HTTP,
and of the [Blackfire probe](blackfire.md) in place of its agent, and shows them in the console and the [web UI](web-ui.md), which also compares two profiles; AI agents analyze them with the [MCP tools](mcp.md#profiles).
To profile your own code, see [Profiling code](profiling.md). Xdebug function traces, every call with its
arguments, are a separate kind: see [Xdebug traces](xdebug-traces.md).

## Endpoints

Trap receives profiles on any of its ports:

| Endpoint                        | Format                                                                          |
|---------------------------------|---------------------------------------------------------------------------------|
| `POST /api/profiler/store`      | XHProf, as Buggregator Server takes it                                          |
| `POST /api/profiler/xdebug`     | Xdebug cachegrind files                                                         |
| `POST /api/profiler/speedscope` | Speedscope JSON, e.g. from Excimer; also any JSON starting with `$schema`       |
| `POST /api/profiler/pprof`      | pprof, gzipped or not, from any language: Go, Java, Python, Rust and so on      |
| `POST /api/profiler/spx`        | SPX full reports, see [SPX](spx.md#send-a-report)                               |
| `POST /api/profiler/phpspy`     | phpspy and reli-prof traces, gzipped or not, see [below](#phpspy-and-reli-prof) |
| `POST /api/profiler/collapsed`  | Collapsed stacks `frame;frame;frame count`, gzipped or not                      |

Speedscope, pprof, SPX, phpspy and collapsed stacks take the metadata in the query: `name`, `app_name`, `hostname`
and `tags[key]=value`; Speedscope, phpspy and collapsed stacks also take `period`, and Speedscope and collapsed
stacks `clock`. For example, a Go service:

```bash
curl -s http://localhost:6060/debug/pprof/profile?seconds=10 \
  | curl --data-binary @- 'http://127.0.0.1:9912/api/profiler/pprof?app_name=billing'
```

## phpspy and reli-prof

[phpspy](https://github.com/adsr/phpspy) and [reli-prof](https://github.com/reliforp/reli-prof) sample the stack
of a running PHP process from outside, with no extension and no code change. Send their text output to
`/api/profiler/phpspy`:

```bash
phpspy -p <pid> -H 99 -o trace.txt          # or: reli inspector:trace -p <pid> -o trace.txt
curl --data-binary @trace.txt 'http://127.0.0.1:9912/api/profiler/phpspy?period=0.0101&name=worker'
```

- `ct` counts the samples a function is on the stack in. With `period`, the sampling period in seconds
  (`1 / rate`: `0.0101` for phpspy's default 99 Hz), Trap also shows the wall time: the samples times the period.
- Trap reads phpspy's default output and its single-line mode (`-1`), with the metadata phpspy adds (`-r`, `-m`,
  `-d`, `-e` and the like) skipped. A sample longer than phpspy's output buffer (`-b`, 4096 bytes by default, which the
  stack of a framework app often passes) comes in chunks, which Trap puts together; a sample with a lost chunk is
  dropped, and one that phpspy truncated keeps the frames it has, as a root of its own. A sample cut off at the end
  of the trace, as by `head -c`, is dropped too. reli-prof's `phpspy` and `phpspy_with_opcode` templates are read
  too.
- File-scope code (`<main>`) at the root of every sample is `main()`, as in XHProf; next to other roots, such as of
  truncated samples, it stays a function of its own under a synthetic `main()`. Functions of the same name in
  different files, such as closures before PHP 8.4 or the file-scope code of included files, are told apart by the
  file: `{closure} (/app/routes.php)`, `<main> (/app/index.php)`.
- A body is up to 8 MB; a gzipped one may decompress to 32 MB: `gzip -c trace.txt | curl --data-binary @- ...`.
  A trace is rejected with `400` past 10 000 frames in a sample, 500 000 samples, 2 000 000 frames in all,
  4 000 000 lines (or records in single-line mode), 100 000 frames waiting in the chunks of unfinished samples,
  or 50 000 functions; fewer functions when Trap's memory runs low, as a kept profile takes about 2 KB a function.
- The `format` of the profile in the [MCP tools](mcp.md#profiles) is `Phpspy`.

Collapsed stacks, as `stackcollapse-phpspy.pl`, Excimer and other flame graph tools write them, go to
`/api/profiler/collapsed`: a stack from the root and its count per line. The count is of samples; `period` turns it
into time, the wall time, or the CPU time with `clock=cpu`. A root named by a `.php` path, as Excimer names
file-scope code, is `main()`. The limits of lines, frames and functions are the same; the `format` is `Collapsed`.
Collapsed stacks of SPX count microseconds, not samples: send an SPX profile to `/api/profiler/spx`. reli-prof's
`converter:folded` keeps the file and line in every frame, so each line of a function would be a function of its
own: send the reli-prof trace itself to `/api/profiler/phpspy`.

## Recursion in the call graph

The Call graph of the [web UI](web-ui.md) draws a node per recursion level, as XHProf names them: `foo`, `foo@1`,
`foo@2`. The «Collapse recursion» switch, which the UI remembers, draws a node per function instead:

- A function that calls itself has an edge to itself; a call back up a cycle through other functions is drawn
  beside the nodes.
- A node costs as much as the outermost calls of the function, so a recursion does not count many times over.
  When the profile counts calls, not samples, the name adds the recursive ones: `foo (12x, 9 recursive)`.
- An edge sums the calls between two functions the same way. Profiles with a call tree (Xdebug, Speedscope, SPX,
  pprof, phpspy, collapsed stacks) give it exactly. XHProf and Blackfire profiles have only the calls between
  recursion levels, so an edge leaves out a call that went deeper in the recursion through another function.

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
