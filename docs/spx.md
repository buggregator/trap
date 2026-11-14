# SPX

[SPX](https://github.com/NoiseByNorthwest/php-spx) writes a full report (`SPX_REPORT=full`) as two files
in its data directory, `/tmp/spx` by default: `spx-full-….json` with the metadata and `spx-full-….txt.gz`
with the trace. Trap takes them in three ways: it [watches the data directory](#watch-the-data-directory),
[takes a report over HTTP](#send-a-report), or the client [profiles your code](#profile-from-code) and sends it.

Reading SPX traces takes ext-zlib in the PHP of Trap.

## Receive reports

### Watch the data directory

Run Trap with the directory, and profile scripts as usual:

```bash
TRAP_SPX_PATH=/tmp/spx vendor/bin/trap --ui
SPX_ENABLED=1 SPX_REPORT=full php script.php
```

A report shows up two seconds after SPX has finished writing it. A report that stays unchanged for an hour,
as the trace of a killed run, is dropped until it changes, and a trace over 64 MB is skipped. SPX keeps its reports
for its own web UI, so the ones finished before Trap started are skipped. With Docker, share the directory between
the application and Trap; don't profile from code with `keepFiles: true` there, as those reports would arrive twice:

```yaml
services:
  app:
    # A PHP image with ext-spx; for web requests, see the spx.http_* settings of SPX
    volumes:
      - spx:/tmp/spx
  trap:
    image: php:8.4-cli
    working_dir: /app
    command: vendor/bin/trap --ui
    environment:
      TRAP_SPX_PATH: /tmp/spx
    ports:
      - "8000:8000"
    volumes:
      - ./:/app
      - spx:/tmp/spx

volumes:
  spx:
```

### Send a report

`POST /api/profiler/spx` takes the two files as `multipart/form-data`, or the
`application/x-tar` archive the client sends:

```bash
curl -F metadata=@/tmp/spx/spx-full-….json -F trace=@/tmp/spx/spx-full-….txt.gz \
  'http://127.0.0.1:8000/api/profiler/spx?app_name=shop'
```

### What Trap shows

Trap shows the wall time, the memory a call leaves allocated and the calls, and the CPU time when it is
recorded (`SPX_METRICS=wt,ct,zm`); the peak memory is not traced. The request or command, the custom metadata
and the totals of the other metrics, such as GC runs or I/O, are shown with the profile in the console.

## Profile from code

`trap()::profile(new Spx())` starts and stops SPX around the code, sends the report
and deletes it (`new Spx(keepFiles: true)` keeps it for the SPX web UI). See [Profiling code](profiling.md) for the
common API. SPX reads its settings when the process starts, so run the process with them; without them the code runs
unprofiled and a warning tells what is missing:

```bash
SPX_ENABLED=1 SPX_AUTO_START=0 SPX_REPORT=full php script.php
```

```php
use Buggregator\Trap\Client\Profiler\Spx;

trap()::profile(new Spx())->name('sync')->tags(['release' => '1.2.3'])->run(fn() => $job->handle());
```

The name and the tags show up in Trap, and in the report as its custom metadata (`name=sync, release=1.2.3`,
up to 4 KB), which the SPX web UI shows.

### Delivery

The trace is sent from its file in chunks; a trace over 64 MB is kept in the data directory and not sent.
Until it is delivered, the report is moved aside as `.trap-spx-full-…`, out of the SPX web UI and of a Trap observing
the directory; a report no destination took, as when Trap is down or the script dies of a fatal error, is put back,
and a warning tells where. A report that can't be moved aside is not sent but left for an observing Trap.
The report of an empty profile is deleted.

The profile goes to Trap as the SPX report itself; `->toFile('profile.spx.tar')` writes it as an archive
that SPX shows once extracted into its data directory, and the other formats are converted with ext-zlib.
Without ext-zlib, `->toServer()` refuses to send the report to a server that takes another format.

### Web requests

For web requests SPX takes the same settings from the `SPX_*` cookies, headers or query parameters of a client it
grants the access (`spx.http_enabled`, the client IP in `spx.http_ip_whitelist`, `SPX_KEY` equal to `spx.http_key`),
or else from the `spx.http_profiling_*` INI settings; its report is always full there. When SPX makes no report,
a warning lists the likely causes.

## Limitations

- SPX supports Linux, macOS and FreeBSD; Windows builds are unofficial.
- In the sampling mode (`SPX_SAMPLING_PERIOD`) SPX records only the changes of the stack at each tick, so a call
  that stays on the stack across ticks counts once: the counts are sampled calls, neither calls nor samples.
  The report doesn't tell the mode: the client passes the period along; when sending a report yourself, add
  `sampling_period` (microseconds) to label the counts so. Files picked up from a directory are taken as traced.
- The client moves its reports out of the way of a Trap watching the same directory, but a report it keeps
  (`keepFiles: true`) arrives twice.
- SPX itself stops PHP when an escaped text of the report, such as the command line of a CLI script, is over 8 KB.
