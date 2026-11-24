# Profiling

Profile the slow code, read the profile with the `profile_*` tools down to the functions that cost the most, fix,
and profile again to compare. Full reference: <https://github.com/buggregator/trap/blob/master/docs/profiling.md>,
<https://github.com/buggregator/trap/blob/master/docs/profiles.md>.

## Pick the profiler

`detect.php` lists the profiler extensions of the PHP CLI. Code run by a web server may have another PHP: check
its `phpinfo()` or profile the same code from a CLI entry point, such as a test.

| Profiler                                 | Measures                                                   | Take it for                            |
|------------------------------------------|------------------------------------------------------------|----------------------------------------|
| Excimer (`ext-excimer`)                  | Samples the stack every `period` seconds, wall or CPU time | Where time goes; cheap, any code size  |
| XHProf (`ext-xhprof`, `tideways_xhprof`) | Every call: count, wall time; CPU and memory on request    | Call counts (an N+1), memory per call  |
| SPX (`ext-spx`)                          | Every call, wall and CPU time, memory                      | Only when set up already: INI at start |
| Xdebug (`xdebug.mode=profile`)           | Every call of the whole process, slowly                    | No other profiler; a whole CLI run     |
| Blackfire probe (`ext-blackfire`)        | Every call: count, wall and CPU time, memory               | Only when installed already            |

With none of them, tell the user which to install; Excimer is `pecl install excimer`.

## Profile from code

`trap()::profile()` runs the code under the profiler and sends the profile to Trap as a `profiler` event. Without a
profiler given, it takes Excimer, then XHProf.

```php
use Buggregator\Trap\Client\Profiler\Clock;
use Buggregator\Trap\Client\Profiler\Excimer;
use Buggregator\Trap\Client\Profiler\XHProf;

$result = trap()::profile()->name('checkout')->run(fn() => $checkout->handle($order));

trap()::profile(new XHProf(memory: true, cpu: true))->name('import')->run(fn() => $importer->run($file));

// Around code that a callable can't wrap; the session also stops when destroyed
$session = trap()::profile(new Excimer(period: 0.0005, clock: Clock::Cpu))->start();
$kernel->handle($request);
$session->stop();
```

- `name()` becomes the `name` field: wait with `{"type": "profiler", "where": {"name": "checkout"}}`.
- Profiling never throws into the profiled code. A profile that doesn't arrive logs why to the `trap.profiler`
  channel: `{"type": "monolog", "where": {"channel": "trap.profiler"}}`. A run shorter than the Excimer `period`
  (1 ms by default) has no samples: lower the period or profile more work.
- One profile at a time: a nested `trap()::profile()` is skipped, and so is one while the Blackfire probe profiles
  the request; the log tells which.
- Run with `XDEBUG_MODE=off`: Xdebug loaded in another mode slows the code and distorts the profile.

## Profile from outside

- Xdebug, a whole CLI run: start Trap with `TRAP_XDEBUG_PATH=<dir>` set, then
  `php -d xdebug.mode=profile -d xdebug.output_dir=<dir> script.php`. Trap picks up the cachegrind files in `<dir>`
  and warns in its output of a file it can't import. POST such a file to `/api/profiler/xdebug` to get the error in
  the answer.
  - `xdebug.mode=profile` alone: `coverage`, `develop` or `debug` beside it slow every line and distort the times.
    Turn off the coverage of the test runner too, such as `--no-coverage`.
  - Every call of the process is recorded, so a profile of a whole test suite is large and takes Trap seconds to
    import: narrow the run to the code in question.
  - Code that runs Fibers needs nothing special, see [Code with Fibers](#code-with-fibers).
  - The query of the endpoint names no Xdebug profile: find its event by `type: profiler` and the cursor.
- XHProf files: `TRAP_XHPROF_PATH=<dir>` for the files another tool writes, such as XHGui.
- Blackfire probe: Trap takes its profiles in place of the Blackfire agent, with no account. Point
  `blackfire.agent_socket` at any Trap port and pass a query; Trap doesn't check its signature, so this one works as
  it is. `profile_title` becomes the `name` tag, the `format` is `Blackfire`.

  ```bash
  BLACKFIRE_QUERY='expires=4102444800&signature=trap&profile_title=import' \
    php -d blackfire.agent_socket=tcp://127.0.0.1:9912 bin/console app:import
  curl -H 'X-Blackfire-Query: expires=4102444800&signature=trap' http://localhost/checkout
  ```

  A web request needs `blackfire.agent_socket` in the INI of its PHP-FPM or web server.
  - A web request without a query makes the probe ask for monitoring (`file-format: BlackfireApm`): Trap closes the
    connection unanswered, the probe logs `Locking APM for 300 seconds` and stops asking for 5 minutes;
    `blackfire.apm_enabled=0` turns it off. Not an error.
  - A profile over 64 MB, data that is not a profile, or a profile coming while four others wait to be built is
    dropped with a warning in Trap's console; the probe doesn't send it again.
- Any language, as pprof, Speedscope JSON or XHProf JSON: POST it to Trap.

```bash
curl -s 'http://localhost:6060/debug/pprof/profile?seconds=10' \
  | curl --data-binary @- 'http://127.0.0.1:8000/api/profiler/pprof?name=billing'
```

| Endpoint                        | Format                                 |
|---------------------------------|----------------------------------------|
| `POST /api/profiler/pprof`      | pprof, gzipped or not                  |
| `POST /api/profiler/speedscope` | Speedscope JSON                        |
| `POST /api/profiler/store`      | XHProf, as Buggregator Server takes it |
| `POST /api/profiler/xdebug`     | Xdebug cachegrind                      |
| `POST /api/profiler/spx`        | SPX full report                        |

## Read the profile

1. `profile_summary` with `{"id": "<event id>"}`: the metrics the profile has, `peaks` (the total of each), the top
   10 functions and the `hot_path`, the heaviest chain of calls from the root down.
2. `profile_top` with `{"id": ..., "filter": "App\\*"}`: the application functions by their own (`exclusive`) cost;
   `sort: inclusive` ranks by the cost with their callees.
3. `profile_calls` with `{"id": ..., "function": "<exact name from profile_top>"}`: who calls it, how many times,
   and what it calls. 1 200 calls from one caller, once per row, is an N+1.
4. `profile_call_graph` with `{"id": ..., "threshold": 5}` when the shape of the tree matters: the calls of at least
   5 percent.

| Metric | Measures                                     |
|--------|----------------------------------------------|
| `wt`   | Wall time, µs                                |
| `cpu`  | CPU time, µs                                 |
| `mu`   | Memory, bytes                                |
| `pmu`  | Peak memory, bytes (XHProf, Blackfire probe) |
| `ct`   | Calls, or samples of Excimer                 |

- Without `metric` the tools take `wt`, else the first the profile has; a missing one fails with those it has.
- Wall time far above CPU time is waiting: I/O, the database, a sleep; look at the callees that wait. Xdebug
  and Excimer with the wall clock measure no CPU time: look for `usleep`, `curl_exec`, `PDOStatement->execute` and the
  like at the top of `profile_top`.
- The `hot_path` keeps the first 5 and the last 25 calls of a longer path: `depth` jumps where it is cut. Its values,
  as those of `profile_top`, sum all the calls of a function, so a callee may show more than its caller on the path.
- A sampled profile counts samples, not calls: a function absent from it took less than the period.
- After the fix, profile the same input again and compare the `peaks` of the two summaries; the web UI compares two
  profiles side by side for the user.

## Trace the calls

An Xdebug function trace records every call in order with its arguments, return value, time and memory: take it
to see which calls a request made and with what values, where a profile only sums the costs. Full reference:
<https://github.com/buggregator/trap/blob/master/docs/xdebug-traces.md>.

1. Record with `xdebug.mode=trace` and `xdebug.trace_format=1` (other formats are rejected);
   `xdebug.collect_return=1` adds return values. A CLI run:
   `php -d xdebug.mode=trace -d xdebug.start_with_request=yes -d xdebug.trace_format=1 -d xdebug.collect_return=1 -d xdebug.output_dir=<dir> script.php`.
2. Send it to Trap: start Trap with `TRAP_XDEBUG_TRACE_PATH=<dir>` and it picks up `*.xt` and `*.xt.gz` files
   written after its start, or POST the file to a TCP port of Trap with the header `X-Buggregator-Event: xdebug-trace`
   (`400` names the problem, `413` a file over `TRAP_XDEBUG_TRACE_MAX_UPLOAD`, 100 MB). A `.xt.gz` needs ext-zlib in
   Trap's PHP (`501` without it); a file without `TRACE END` is taken over HTTP only.
3. Find the event: `{"type": "xdebug-trace"}`. It has no `where` fields: `X-Buggregator-Trace-Url` or
   `X-Buggregator-Trace-Name` titles its summary, so `search` finds it.
4. `xdebug_trace_summary` with `{"uuid": "<event id>"}`: totals, the 20 functions with the most self time, the 20
   slowest calls, each with its `offset`.
5. `xdebug_trace_calls` with `{"uuid": ..., "parent": <offset>}` lists the direct callees of a call, without
   `parent` the top level; `{"uuid": ..., "query": "Repository"}` finds the calls by function or file, each with
   its `path` of callers. A non-null `next` → call again with `from` set to it. `xdebug_trace_call` with
   `{"uuid": ..., "offset": <offset>}` shows one call and its first callees.

- Times are seconds, memory bytes; arguments and return values are cut to 512 bytes.
- `truncated: true`: the trace hit the size limit (`TRAP_XDEBUG_TRACE_MAX_SIZE`, 256 MB uncompressed) or the
  script died before `TRACE END`.
- Trap keeps the last 20 traces (`TRAP_XDEBUG_TRACE_KEEP`); an older one has no file and the tools say so.
- A trace records every call: narrow the run, or start it with `xdebug.start_with_request=trigger` and
  `XDEBUG_TRIGGER=1` for one request.

## Code with Fibers

Xdebug writes the calls of every fiber into one log in the order they end, so the calls of fibers that switch
interleave there. Trap tells them apart by their times: profile code with Fibers (amphp, Revolt, fiber tests) as
any other.

- The function a fiber runs is a root of its own beside `{main}`. Its time also counts in the `Fiber->start()` and
  `Fiber->resume()` calls that ran it.
- Many fibers resumed in turns that suspend inside nested functions, as in an event loop, may leave calls Trap
  can't link to their callers. Trap warns with their number; such calls are roots of their own. When many are
  left, narrow the run to fewer fibers at a time.
