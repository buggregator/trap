# Profiling code

Profile a piece of code with `trap()::profile()`. One API covers the profilers, and the profile goes to Trap
by default. To receive profiles made by other tools, see [Receiving profiles](profiles.md).

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

## Profilers

- [Excimer](https://www.mediawiki.org/wiki/Excimer) (`ext-excimer`): a sampling profiler, cheap enough for
  production. `period` is the sampling interval in seconds, `clock` is wall or CPU time.
- XHProf (`ext-xhprof` or `ext-tideways_xhprof`): counts every call with wall time and, optionally, CPU time
  and memory. It takes over an XHProf session already running, such as one started by XHGui.
- [SPX](https://github.com/NoiseByNorthwest/php-spx) (`ext-spx`): records every call with wall and CPU time,
  memory and more. It needs settings at the process start, see [SPX](spx.md#profile-from-code).

Without a profiler given, the first available one is used: Excimer, then XHProf. SPX is used only when given.
Xdebug can't be started from code for a part of a request: profile whole requests with `xdebug.mode=profile`
and let Trap [pick up its files](profiles.md#directory-watching).

## Destinations

Add as many as needed:

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

## Errors

Profiling never breaks the profiled code. Nested profiles, a missing profiler, a profile too short to have
samples and delivery errors are logged once per process to the `trap.profiler` channel of
[`trap()::logger()`](functions.md#traplogger), which falls back to STDERR when Trap is not running;
only invalid options throw.
`->if($condition)` turns profiling off, e.g. outside a dev environment.

## Environment variables

- `TRAP_PROFILER_HOST` and `TRAP_PROFILER_PORT` - where `toTrap()` sends (default: `127.0.0.1:9912`)
- `TRAP_PROFILER_SERVER` - the default URL of `toServer()`
- `TRAP_PROFILER_APP_NAME` - the application name in the profile metadata
