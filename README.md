<p align="center">
    <img alt="logo"
         src="https://github.com/buggregator/trap/blob/master/resources/payloads/logo.png?raw=true"
         style="width: 3in; display: block"
    />
</p>
<p align="center">Revolutionize Your Debugging Experience with PHP</p>
<h1 align="center">Buggregator Trap</h1>

<div align="center">

[![Documentation](https://img.shields.io/badge/Documentation-blue?style=for-the-badge&logo=gitbook&logoColor=white)](https://docs.buggregator.dev/trap/what-is-trap.html)
[![Sponsor](https://img.shields.io/static/v1?style=for-the-badge&label=&message=Sponsor&logo=boosty&logoColor=white&color=%23F15F2C)](https://boosty.to/roxblnfk)

[![Psalm Level](https://shepherd.dev/github/buggregator/trap/level.svg)](https://shepherd.dev/github/buggregator/trap)
[![Type Coverage](https://shepherd.dev/github/buggregator/trap/coverage.svg)](https://shepherd.dev/github/buggregator/trap)
[![Codecov](https://img.shields.io/codecov/c/github/buggregator/trap/master?style=flat&logo=codecov)](https://codecov.io/gh/buggregator/trap)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fbuggregator%2Ftrap%2Fmaster)](https://dashboard.stryker-mutator.io/reports/github.com/buggregator/trap/master)

[![Awesome PHP](https://awesome.re/mentioned-badge-flat.svg)](https://github.com/ziadoz/awesome-php#debugging-and-profiling)
[![Twitter](https://img.shields.io/badge/-Follow-black?style=flat-square&logo=X)](https://twitter.com/buggregator)
[![Discord](https://img.shields.io/static/v1?style=flat-square&label=Join&message=Discord&logo=Discord&color=%235865F2)](https://discord.gg/qF3HpXhMEP)

</div>

<br />

**Trap** is a package designed to enhance the debugging experience in conjunction with the Buggregator Server.  
Trap includes:

- A set of functions for direct interaction with any Buggregator server.
- Extensions for Symfony VarDumper that become active immediately after installing Trap.
- A minimized version of the [Buggregator Server](https://github.com/buggregator/server) that does not require Docker
  and is intended solely for local use.
- Use PHPStorm? Consider using Trap with the [Buggregator Plugin](https://github.com/buggregator/phpstorm-plugin) for a seamless debugging experience.

**Table of content:**

- [Installation](#installation)
- [Overview](#overview)
- [Usage](#usage)
- [Contributing](#contributing)

## Installation

To install Buggregator Trap in your PHP application, add the package as a dev dependency
to your project using Composer:

```bash
composer require --dev buggregator/trap -W
```

[![PHP](https://img.shields.io/packagist/php-v/buggregator/trap.svg?style=flat-square&logo=php)](https://packagist.org/packages/buggregator/trap)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/buggregator/trap.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/buggregator/trap)
[![License](https://img.shields.io/packagist/l/buggregator/trap.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/buggregator/trap.svg?style=flat-square)](https://packagist.org/packages/buggregator/trap/stats)

And that's it. Trap is [ready to go](#usage).

### Binary

If you prefer to use Trap as a binary, you can install it globally using [Dload](https://github.com/php-internal/dload):

```bash
dload get trap
```

### Phar

Sometimes your project may conflict with Trap's dependencies, or you might be interested in using only the local
server (e.g., for analyzing local profiler files).
In this case, consider installing Trap as a Phar (a self-contained PHP executable).
Using wget:

```bash
wget https://github.com/buggregator/trap/releases/latest/download/trap.phar
chmod +x trap.phar
./trap.phar --version
```

Using [Phive](https://phar.io/):

```bash
phive install buggregator/trap
```

## Overview

Buggregator Trap provides a toolkit for use in your code. Firstly, just having Buggregator Trap in your
package enhances the capabilities of Symfony Var-Dumper.

If you've already worked with `google/protobuf`, you probably know how unpleasant it can be.
Now let's compare the dumps of protobuf-message: on the left (with trap) and on the right (without trap).

![trap-proto-diff](https://github.com/buggregator/trap/assets/4152481/30662429-809e-422a-83c6-61d7d2788b18)

This simultaneously compact and informative output format of protobuf message will be just as compact
and informative in the Buggregator Server interface. Now, working with protobuf is enjoyable.

---

Buggreagtor Trap includes a console application - a mini-server.
The application is entirely written in PHP and does not require Docker to be installed in the system.
It may seem like it's just the same as the `symfony/var-dumper` server, but it's not.
Buggregator Trap boasts a much wider range of handlers ("traps") for debug messages:

- Symfony var-dumper,
- Monolog,
- Sentry,
- OpenTelemetry traces (OTLP/HTTP),
- Profilers: XHProf, Xdebug, Excimer (Speedscope), SPX, pprof,
- SMTP,
- HTTP dumps,
- Ray,
- Any raw data

You can effortlessly visualize and analyze console information about various elements of your codebase.

Here's a sneak peek into the console output you can expect with Trap:

| symfony/var-dumper (proto)                                                                             | Binary Data                                                                                             |
|--------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------|
| ![var-dumper](https://github.com/buggregator/trap/assets/4152481/f4c855f5-87c4-4534-b72d-5b19d1aae0b0) | ![Binary Data](https://github.com/buggregator/trap/assets/4152481/cd8788ed-b10c-4b9a-b2e2-baa8912ea38d) |

| SMTP Mail Trap                                                                                   | HTTP Dump                                                                                             |
|--------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------|
| ![smtp](https://github.com/buggregator/trap/assets/4152481/b11c4a7f-072a-4e66-b11d-9bbd3177bfe2) | ![http-dump](https://github.com/buggregator/trap/assets/4152481/48201ce6-7756-4402-8954-76a27489b632) |

---

Additionally, you can manually set traps in the code. Use the `trap()` function,
which works almost the same as Symfony's `dump()`, but configures the dumper to send dumps to the local server,
unless other user settings are provided.

Also, the `trap()` has a lot of useful options:

```php
// Limit the depth of the dumped structure
trap($veryDeepArray)->depth(3);

foreach ($veryLargeArray as $item) {
    // We don't need to dump more than 3 items
    trap($item)->times(3);
}

// Dump once if the condition is true
trap($animal)->once()->if($var instanceof Animal\Cat);
```

---

> [!TIP]
> Feature in development:
> add the flag `--ui` to rise the web interface of the Buggregator Server without docker.
![trap-ui](https://github.com/buggregator/trap/assets/4152481/1ccc2c85-2f81-4b62-8ae7-49ee76380674)
---

We care about the quality of our products' codebase and strive to provide the best user experience.
Buggregator Trap has passed the Proof of Concept stage and is now an important part of the Buggregator ecosystem.
We have big plans for the development of the entire ecosystem and we would be delighted if you join us on this journey.

## Usage

After successfully [installing](#installation) Buggregator Trap, you can initiate the debugging process by using the following command:

```bash
vendor/bin/trap
```

This command will start the Trap server, ready to receive any debug messages. Once a debug message is trapped, you will see a convenient report about it right here in the terminal.

Then just call the `trap()` function in your code:

```php
// dump the current stack trace
trap()->stackTrace();

// dump a variable with a depth limit
trap($var)->depth(4);

 // dump a named variables sequence
trap($var, foo: $far, bar: $bar);

// dump a variable and return it
$responder->respond(trap($response)->return()); 
```

> **Note**:
> The `trap()` function configures `$_SERVER['REMOTE_ADDR']` and `$_SERVER['REMOTE_PORT']` automatically,
> if they are not set.

Also, there are a couple of shortcuts here:

- `tr(...)` - equivalent to `trap(...)->return()`
- `td(...)` - equivalent to `trap(...); die;`

If called without arguments, they will display a short stack trace, used memory, and time between shortcut calls.

```php
function handle($input) {
    tr(); // Trace #0  -.---  3.42M

    $data = $this->prepareData($input);

    tr(); // Trace #1  0.015ms  6.58M

    $this->processor->process(tr(data: $data));

    td(); // exit with output: Trace #2  1.15ms  7.73M
}
```

### Default port

Trap automatically recognizes the type of traffic.
Therefore, there is no need to open separate ports for different protocols.
By default, it operates on the same ports as the Buggregator Server: `9912`, `9913`, `1025`, and `8000`,
plus `4318`, the default OTLP/HTTP port.
However, if you wish to utilize a different port, you can easily make this adjustment using the `-p` option:

```bash
vendor/bin/trap -p9912 --ui=8000
```

Environment variables can also be used to set endpoints:

- `TRAP_TCP_PORTS` - for TCP traffic: `9912,9913,1025,8000,4318`
- `TRAP_TCP_HOST` - for the TCP host (default: `127.0.0.1`)
- `TRAP_UI_PORT` - for the web interface: `8080`

`TRAP_MEMORY_LIMIT` raises PHP's `memory_limit` when it is lower (default: `512M`; `-1` for no limit; empty to keep it).
Large profiles get as many call tree nodes as the memory left affords, and are compacted past that.

`TRAP_GC_INTERVAL` sets how often, in seconds, the server collects garbage cycles in place of PHP's cycle collector (default: `30`; `0` keeps PHP's collector).

### Profiling

Profile a piece of code with `trap()::profile()`. One API covers the profilers, and the profile goes to Trap
by default:

```php
use Buggregator\Trap\Client\Profiler\Clock;
use Buggregator\Trap\Client\Profiler\Excimer;
use Buggregator\Trap\Client\Profiler\Format;
use Buggregator\Trap\Client\Profiler\XHProf;

// Profile a callable and get its result
$result = trap()::profile()->run(fn() => $app->handle($request));

// Choose the profiler with its options, name and tag the profile, profile one request of ten
trap()::profile(new Excimer(period: 0.001, clock: Clock::Cpu))
    ->name('checkout')
    ->tags(['env' => 'stage'])
    ->sample(0.1)
    ->run(fn() => $checkout->handle($order));

// Or start and stop it around any code; a session stops when it is destroyed
$session = trap()::profile(new XHProf(memory: true, cpu: true))->start();
try {
    $app->run();
} finally {
    $session->stop();
}
```

Profilers:

- [Excimer](https://www.mediawiki.org/wiki/Excimer) (`ext-excimer`): a sampling profiler, cheap enough for
  production. `period` is the sampling interval in seconds, `clock` is wall or CPU time.
- XHProf (`ext-xhprof` or `ext-tideways_xhprof`): counts every call with wall time and, optionally, CPU time
  and memory. It takes over an XHProf session already running, such as one started by XHGui.
- [SPX](https://github.com/NoiseByNorthwest/php-spx) (`ext-spx`): records every call with wall and CPU time,
  memory and more. It needs settings at the process start, see [SPX](#spx) below.

Without a profiler given, the first available one is used: Excimer, then XHProf. SPX is used only when given.
Xdebug can't be started from code for a part of a request: profile whole requests with `xdebug.mode=profile`
and let Trap pick up its files (see below).

Destinations, as many as needed:

- `->toTrap(?host, ?port)` sends the profile in the profiler's own format (the default destination).
- `->toFile($path, ?Format)` writes a file. The format is taken from the name when not given:
  `*.speedscope.json`, `*.txt` or `*.folded` (collapsed stacks), `*.xhprof`, `*.pb.gz` or `*.pprof`, `*.spx.tar`.
  The file is written aside as `.trap-profile-*` next to it and moved over it, so a failed write leaves it as it
  was; a process killed while writing leaves that temporary file behind.
- `->toServer(?url, Format = Format::XHProf, headers: [])` sends the profile to any HTTP server, by default to
  a Buggregator Server at `http://127.0.0.1:8000/api/profiler/store`. Userinfo in the URL becomes Basic
  authorization.
- `->to(fn(Profile $profile) => ...)` passes the profile to your code, e.g. `$profile->encode(Format::Pprof)`.

A format is preferred, not required: Excimer and SPX convert to every format, while XHProf, which keeps no
stacks, gives XHProf in place of Speedscope, collapsed stacks or pprof.

Profiling never breaks the profiled code. Nested profiles, a missing profiler, a profile too short to have
samples and delivery errors are logged once per process to the `trap.profiler` channel of `trap()::logger()`,
which falls back to STDERR when Trap is not running; only invalid options throw.
`->if($condition)` turns profiling off, e.g. outside a dev environment.

The client takes these environment variables:

- `TRAP_PROFILER_HOST` and `TRAP_PROFILER_PORT` - where `toTrap()` sends (default: `127.0.0.1:9912`)
- `TRAP_PROFILER_SERVER` - the default URL of `toServer()`
- `TRAP_PROFILER_APP_NAME` - the application name in the profile metadata

Trap receives profiles on any of its ports:

| Endpoint                         | Format                                                                      |
|----------------------------------|-----------------------------------------------------------------------------|
| `POST /api/profiler/store`       | XHProf, as Buggregator Server takes it                                      |
| `POST /api/profiler/xdebug`      | Xdebug cachegrind files                                                     |
| `POST /api/profiler/speedscope`  | Speedscope JSON, e.g. from Excimer; also any JSON starting with `$schema`   |
| `POST /api/profiler/pprof`       | pprof, gzipped or not, from any language: Go, Java, Python, Rust and so on  |
| `POST /api/profiler/spx`         | SPX full reports, see [SPX](#spx)                                           |

Speedscope, pprof and SPX take the metadata in the query: `name`, `app_name`, `hostname` and `tags[key]=value`;
Speedscope also takes `clock` and `period`. For example, a Go service:

```bash
curl -s http://localhost:6060/debug/pprof/profile?seconds=10 \
  | curl --data-binary @- 'http://127.0.0.1:9912/api/profiler/pprof?app_name=billing'
```

Trap also picks up profiler files from a directory:

- `TRAP_XHPROF_PATH` - XHProf files, by default from `xhprof.output_dir`
- `TRAP_XDEBUG_PATH` - Xdebug cachegrind files, by default from `xdebug.output_dir` while `xdebug.mode`
  includes `profile`
- `TRAP_SPX_PATH` - SPX full reports, by default from `spx.data_dir` when ext-spx is loaded in Trap's PHP

Large profiles are compacted to the memory Trap has (see `TRAP_MEMORY_LIMIT` above): calls of the same function
are merged, deep recursion is collapsed and the coldest calls are folded into `(other)` nodes;
the profile metadata tells how much was folded. One profile is built at a time.

#### SPX

[SPX](https://github.com/NoiseByNorthwest/php-spx) writes a full report (`SPX_REPORT=full`) as two files
in its data directory, `/tmp/spx` by default: `spx-full-….json` with the metadata and `spx-full-….txt.gz`
with the trace. Trap takes them in three ways.

**Watching the data directory.** Run Trap with the directory, and profile scripts as usual:

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

**Sending a report.** `POST /api/profiler/spx` takes the two files as `multipart/form-data`, or the
`application/x-tar` archive the client sends:

```bash
curl -F metadata=@/tmp/spx/spx-full-….json -F trace=@/tmp/spx/spx-full-….txt.gz \
  'http://127.0.0.1:8000/api/profiler/spx?app_name=shop'
```

**Profiling from code.** `trap()::profile(new Spx())` starts and stops SPX around the code, sends the report
and deletes it (`new Spx(keepFiles: true)` keeps it for the SPX web UI). The trace is sent from its file
in chunks; a trace over 64 MB is kept in the data directory and not sent. Until it is delivered, the report is
moved aside as `.trap-spx-full-…`, out of the SPX web UI and of a Trap observing the directory; a report no
destination took, as when Trap is down or the script dies of a fatal error, is put back, and a warning tells where.
A report that can't be moved aside is not sent but left for an observing Trap. The report of an empty profile is
deleted. SPX reads its settings when the process starts,
so run the process with them; without them the code runs unprofiled and a warning tells what is missing:

```bash
SPX_ENABLED=1 SPX_AUTO_START=0 SPX_REPORT=full php script.php
```

```php
use Buggregator\Trap\Client\Profiler\Spx;

trap()::profile(new Spx())->name('sync')->tags(['release' => '1.2.3'])->run(fn() => $job->handle());
```

The name and the tags show up in Trap, and in the report as its custom metadata (`name=sync, release=1.2.3`,
up to 4 KB), which the SPX web UI shows.

For web requests SPX takes the same settings from the `SPX_*` cookies, headers or query parameters of a client it
grants the access (`spx.http_enabled`, the client IP in `spx.http_ip_whitelist`, `SPX_KEY` equal to `spx.http_key`),
or else from the `spx.http_profiling_*` INI settings; its report is always full there. When SPX makes no report,
a warning lists the likely causes. Reading SPX traces takes ext-zlib in the PHP of Trap.
The profile goes to Trap as the SPX report itself; `->toFile('profile.spx.tar')` writes it as an archive
that SPX shows once extracted into its data directory, and the other formats are converted with ext-zlib.
Without ext-zlib, `->toServer()` refuses to send the report to a server that takes another format.

Trap shows the wall time, the memory a call leaves allocated and the calls, and the CPU time when it is
recorded (`SPX_METRICS=wt,ct,zm`); the peak memory is not traced. The request or command, the custom metadata
and the totals of the other metrics, such as GC runs or I/O, are shown with the profile in the console.

Limitations:

- SPX supports Linux, macOS and FreeBSD; Windows builds are unofficial.
- In the sampling mode (`SPX_SAMPLING_PERIOD`) SPX records only the changes of the stack at each tick, so a call
  that stays on the stack across ticks counts once: the counts are sampled calls, neither calls nor samples.
  The report doesn't tell the mode: the client passes the period along; when sending a report yourself, add
  `sampling_period` (microseconds) to label the counts so. Files picked up from a directory are taken as traced.
- The client moves its reports out of the way of a Trap watching the same directory, but a report it keeps
  (`keepFiles: true`) arrives twice.
- SPX itself stops PHP when an escaped text of the report, such as the command line of a CLI script, is over 8 KB.

### OpenTelemetry traces

Trap receives traces exported over OTLP/HTTP (`POST /v1/traces`) on any of its ports
and prints them in the console as a tree of spans:

```bash
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
```

Logs and metrics are not supported yet: Trap answers `/v1/logs` and `/v1/metrics` with success and drops them.
To send only traces, set `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://127.0.0.1:4318/v1/traces` instead of the
common endpoint, or turn the other signals off with `OTEL_LOGS_EXPORTER=none` and `OTEL_METRICS_EXPORTER=none`.

The `http/protobuf` protocol needs the suggested packages:

```bash
composer require --dev open-telemetry/gen-otlp-protobuf google/protobuf
```

The PHAR, the standalone binary and the Docker image do not include them, so they accept only `http/json`.

Notes:

- Requests with `Transfer-Encoding: chunked` are not supported by the HTTP parser of Trap;
  OpenTelemetry SDK exporters send `Content-Length`.
- Exporters running in a browser are not supported: Trap does not answer CORS preflight requests.
- One export request may carry up to 10 MB (after decompression) and up to 10 000 spans; a bigger one gets `413`.
  SDK batch processors export 512 spans at a time by default.
- Port `4318` is also the default port of an OpenTelemetry Collector or Jaeger. On Linux and macOS, when it is
  taken, Trap keeps retrying to listen on it silently (run with `-vv` to see the errors) and works on the other ports.
  On Windows, Trap can listen on `127.0.0.1:4318` even while another process, such as a Collector or Jaeger in
  Docker Desktop, listens on `0.0.0.0:4318`, and then Trap receives the local traffic meant for that process.
  To leave the port to it, list the ports without `4318`:
  `vendor/bin/trap -p1025 -p8000 -p9912 -p9913` or `TRAP_TCP_PORTS=1025,8000,9912,9913`.

With the `file` sender, each export request is saved as one JSON line:
`{"type":"otlp.traces","resourceSpans":[...]}`.

Spans of one trace often arrive in separate requests (a simple span processor exports each span on its own),
so Trap keeps received spans in memory to print them together. The memory is limited by:

- `TRAP_TRACES_MAX_SPANS` - the maximum number of kept spans; the least recently updated traces are dropped
  above it, and a single trace above it keeps only its most recent spans (default: `10000`)
- `TRAP_TRACES_TTL` - seconds a trace is kept after its last received span (default: `600`)

The same limits can be set in `trap.xml`: `<traces maxSpans="10000" ttl="600"/>`.
Non-positive or non-numeric values fall back to the defaults.

### Choosing Your Senders

Buggregator Trap provides a variety of "senders" that dictate where the dumps will be sent. Currently, the available
sender options include:

- `console`: Shows dumps directly in the console.
- `server`: Sends dumps to a remote Buggregator server.
- `file`: Saves dumps in a file for later use.
- `mail-to-file`: Creates a folder for each recipient and saves each message as a JSON file. Useful for testing mails.
If you send a mail `To: foo@example.com, bar@example2.org`, the following folders will be created:
  - `runtime/mail/foo@example.com`
  - `runtime/mail/bar@example2.org`

By default, the Trap server is set to display dumps in the console. However, you can easily select your preferred
senders using the `-s` option.

For instance, to simultaneously use the console and file senders, you would input:

```bash
vendor/bin/trap -sconsole -sfile
```

## Contributing

We believe in the power of community-driven development. Here's how you can contribute:

- Share your experience:  
  - If you find a bug or have a feature request, please [create an issue](https://github.com/buggregator/trap/issues).
  - Help others by answering questions on [Discord](https://discord.gg/qF3HpXhMEP) or asking your own.
  - Share the love and tell others about Buggregator.
- Write code:  
  - If you want to fix a bug or add a feature, let us know in the issue tracker.
    [There are issues](https://github.com/buggregator/trap/issues?q=is%3Aopen+is%3Aissue+label%3A%22help+wanted%22) labeled "help wanted" that are a good place to start.
  - Have good documentation writing skills? We'd love your help improving our [docs](https://github.com/buggregator/docs/tree/master/docs).
- Sponsor us:  
  - If you use Buggregator in a commercial setting, consider [becoming a sponsor](https://boosty.to/roxblnfk) to help us maintain and improve the project.
  - You also can promote a specific feature or bug fix.
    We mark sponsored tasks with the honorary label [`SPONSORED`](https://github.com/buggregator/trap/issues?q=label%3ASPONSORED).
