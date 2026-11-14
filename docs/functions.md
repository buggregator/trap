# Functions

Trap adds three global functions for your code: `trap()`, `tr()` and `td()`.
They work with Trap and with any Buggregator server.

- [`trap()`](#trap)
- [`tr()` and `td()`](#tr-and-td)
- [`trap()::logger()`](#traplogger)
- [`trap()::profile()`](#trapprofile)
- [Protobuf messages](#protobuf-messages)

## `trap()`

```php
function trap(mixed ...$values): TrapHandle
```

`trap()` works almost the same as Symfony's `dump()`, but sends the dump to the local Trap server
(`127.0.0.1:9912`), unless you configure another destination. It takes any number of positional or named arguments.

```php
// Dump a variable
trap($user);

// Dump several variables
trap($request, $response, $config);

// Dump a named variables sequence
trap(user: $user, order: $order);
```

The dump is sent when the returned `TrapHandle` is destroyed, so the methods below apply to it.

### Methods

| Method                                         | What it does                                                                                                                         |
|------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------|
| `->depth(int $depth)`                          | Limits the depth of the dumped structure; `0` means no limit.                                                                        |
| `->stackTrace()`                               | Adds the stack trace to the dump. `trap()->stackTrace()` dumps the current stack trace.                                              |
| `->if(bool\|callable $condition)`              | Dumps only if the condition is true. The check runs at once; if the callable throws, the exception is added to the dump.             |
| `->once()`                                     | Dumps only once from this line; equals `->times(1)`.                                                                                 |
| `->times(int $times, bool $fullStack = false)` | Dumps only `$times` times from this line; with `$fullStack` the counter is per stack trace, not per line. Skipped dumps don't count. |
| `->return(int\|string $key = 0)`               | Sends the dump and returns a dumped value: by position or by the name of a named argument.                                           |
| `->context(mixed ...$values)`                  | Adds context to the dump, as named arguments or an array.                                                                            |
| `->code(string $syntax)`                       | Marks the data as source code in the given language, so Buggregator highlights it. Equals `->context(language: $syntax)`.            |

```php
// Limit the depth
trap($veryDeepArray)->depth(3);

foreach ($veryLargeArray as $item) {
    // We don't need more than 3 items
    trap($item)->times(3);
}

// Dump once if the condition is true
trap($animal)->once()->if($animal instanceof Animal\Cat);
trap($query)->if(fn() => $query->count() > 100);

// Dump a variable and return it
$responder->respond(trap($response)->return());
$name = trap(first: $firstName, last: $lastName)->return('last');

// Context and code highlighting
trap($data)->context(['environment' => 'stage', 'user_id' => 42]);
trap(index: $indexCode, controller: $controllerCode)->code('php');
```

### Where dumps go

`trap()` uses Symfony VarDumper. When `$_SERVER['VAR_DUMPER_FORMAT']` and `$_SERVER['VAR_DUMPER_SERVER']` are not
both set, it sets them from the environment variables of the same names, defaulting to `server` and
`127.0.0.1:9912`. So `trap()` reaches a local Trap with no setup. When no server listens there, the dump is printed
in place: to the console in CLI, as HTML otherwise. In a RoadRunner worker that fallback writes to STDOUT, which
RoadRunner reads as its protocol, so keep Trap running while you dump there. The same variables point `trap()`
elsewhere:

```bash
# A Buggregator Server on another host
VAR_DUMPER_SERVER=192.168.1.10:9912
# Print dumps in place, without a server
VAR_DUMPER_FORMAT=cli
```

See [var-dumper](var-dumper.md) for the formats.

## `tr()` and `td()`

```php
function tr(mixed ...$values): mixed
function td(mixed ...$values): never
```

- `tr(...)` equals `trap(...)->return()`.
- `td(...)` equals `trap(...); die;`.

Called without arguments, they dump a short stack trace, the memory used and the time since the previous
`tr()` call:

```php
function handle($input) {
    tr(); // Trace #0  -.---  3.42M

    $data = $this->prepareData($input);

    tr(); // Trace #1  0.015ms  6.58M

    $this->processor->process(tr(data: $data));

    td(); // exit with output: Trace #2  1.15ms  7.73M
}
```

## `trap()::logger()`

```php
public static function logger(string $channel = 'trap'): Psr\Log\LoggerInterface
```

Returns a PSR-3 logger that sends records to Trap in the Monolog JSON format, so they show up as
[Monolog](monolog.md) logs. The channel names the records.

```php
trap()::logger()->info('Order {id} paid', ['id' => $order->id]);
trap()::logger('billing')->error('Payment failed', ['exception' => $e]);
```

It connects to `TRAP_MONOLOG_HOST`:`TRAP_MONOLOG_PORT` (default `127.0.0.1:9913`) with a 0.5 s timeout, and
writes the record to STDERR when Trap is not reachable.

## `trap()::profile()`

Profiles a piece of code and sends the profile to Trap. See [Profiling code](profiling.md).

```php
$result = trap()::profile()->run(fn() => $app->handle($request));
```

## Protobuf messages

With Trap installed, Symfony VarDumper dumps `google/protobuf` messages, repeated fields, maps and enums in a compact
and informative form, in `trap()`, in `dump()` and in the Buggregator Server interface.
On the left with Trap, on the right without:

![trap-proto-diff](https://github.com/buggregator/trap/assets/4152481/30662429-809e-422a-83c6-61d7d2788b18)
