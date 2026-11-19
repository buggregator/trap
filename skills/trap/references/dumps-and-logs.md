# Dumps and logs

Add a dump or a log record where the code runs, trigger it, and read the event back: a value, the path the code
took, or a stack trace, without a debugger and without touching the output of the application. Full reference:
<https://github.com/buggregator/trap/blob/master/docs/functions.md>.

## `trap()`

`trap()` dumps its arguments to Trap at `127.0.0.1:9912`. Name the arguments: the name becomes the `label` field,
so `where.label` finds the dump among the others.

```php
trap(order: $order, total: $total);           // a var-dump event per argument, label `order` and `total`
trap(user: $user)->depth(3);                  // cut the structure at depth 3
trap()->stackTrace();                         // who called this line
trap(item: $item)->times(3);                  // only the first 3 times in a loop
trap(query: $query)->if($query->count() > 100);
$result = trap(result: $service->run())->return('result');   // dump and pass the value through
```

| Method                       | Effect                                               |
|------------------------------|------------------------------------------------------|
| `->depth(int)`               | Depth of the dumped structure; `0` is no limit       |
| `->stackTrace()`             | Adds the stack trace                                 |
| `->if(bool\|callable)`       | Dumps only when the condition holds                  |
| `->once()`, `->times(int)`   | Dumps only the first times from this line            |
| `->context(...)`             | Adds named values to the dump                        |
| `->return(int\|string $key)` | Sends the dump at once and returns one of the values |

The dump is sent when the returned handle is destroyed, at the end of the statement. Read it with
`{"type": "var-dump", "where": {"label": "order"}}`, and `get_event` for the whole structure.

## `tr()` and `td()`

- `tr(...)` dumps and returns its first argument: `$this->save(tr(order: $order))`.
- `td(...)` dumps and stops the script.
- Without arguments they dump a stack trace with the memory used and the time since the previous `tr()`, which
  traces the path and timing of a request: `tr(); … tr(); … tr();`.
- To time a block, put `tr()` before and after it: the summary of the second one carries the duration,
  `Trace #1 +2058.6ms 2.50M`. For a block that throws, put the second `tr()` in the `catch` too.

## `dump()`

Symfony's `dump()` reaches Trap when `VAR_DUMPER_FORMAT=server` is set for PHP, see
[integrations.md](integrations.md#vardumper). Prefer `trap()` in new instrumentation: it needs no setting.

## Log records

`trap()::logger()` is a PSR-3 logger that sends to Trap at `127.0.0.1:9913`, no Monolog needed. A channel of your
own keeps your records apart from those of the application:

```php
trap()::logger('agent')->info('Order {id} paid', ['id' => $order->id]);
trap()::logger('agent')->error('Payment failed', ['exception' => $e]);
```

Read them with `{"type": "monolog", "where": {"channel": "agent"}}`. Pass the logger where the code takes a
`Psr\Log\LoggerInterface` to see what a library logs. To read the application's own log records, point its logger
at Trap, see [integrations.md](integrations.md#monolog).

## Where the code runs

- `trap()` reads `VAR_DUMPER_SERVER` (default `127.0.0.1:9912`); the logger `TRAP_MONOLOG_HOST` and
  `TRAP_MONOLOG_PORT`. An application in Docker sets them to the host of Trap, see
  [server.md](server.md#another-host).
- `TRAP_MONOLOG_HOST=tls://127.0.0.1` sends the logger's records over TLS. Dumps of `trap()` go plain only: a
  `tls://` `VAR_DUMPER_SERVER` loses every dump without an error.
- With no Trap listening, `trap()` prints the dump in place and the logger writes to STDERR. A RoadRunner worker
  reads its STDOUT as the protocol, so keep Trap running before dumping there.
