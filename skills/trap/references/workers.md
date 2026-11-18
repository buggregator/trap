# Long-running workers

RoadRunner, Swoole, FrankenPHP worker mode, Laravel Octane and queue consumers keep one PHP process across many
requests or jobs, and often own its STDOUT. `trap()` sends out of band, so a dump never lands in a response or
breaks the worker protocol. The dump functions are in [dumps-and-logs.md](dumps-and-logs.md).

## Reload the workers

A worker runs the code it loaded at start: an added `trap()` does nothing, and a removed one keeps dumping, until
the workers reload. Reload after every change to the instrumentation, the cleanup too:

| Server             | Reload                                                             |
|--------------------|--------------------------------------------------------------------|
| RoadRunner         | `rr reset`, or `reload` in `.rr.yaml` for a dev setup              |
| Laravel Octane     | `php artisan octane:reload`, or `--watch` at start                 |
| Symfony Messenger  | `bin/console messenger:stop-workers`, then the supervisor restarts |
| Laravel queue      | `php artisan queue:restart`                                        |
| Swoole, FrankenPHP | Restart the server, or its own reload command or watcher           |

Ask the user before reloading a server they run themselves.

## Keep Trap running

With no Trap listening, `trap()` prints the dump in place: to the worker's STDOUT, which RoadRunner reads as its
protocol and FrankenPHP sends as the response. Start Trap before the workers dump, and keep it until the
instrumentation is gone.

## Limit the volume

A worker runs the same line for every request:

- `->once()` and `->times(n)` count per process: with 8 workers, `->once()` dumps up to 8 times.
- `->if(fn() => $request->getPathInfo() === '/checkout')` dumps for the request under study only.
- A filter on `label` reads one dump among the traffic: `{"type": "var-dump", "where": {"label": "order"}}`.

## Find a leak

`tr()` without arguments dumps the memory used and the time since the previous `tr()` of the process. One call at
the end of each request or job gives a series per worker:

```php
$worker->handle($request);
tr();                                         // summary: Trace #41 +12.3ms 48.20M { tr(): src/Worker.php:27 }
```

1. Take a cursor, send the same request many times, then read the series from the summaries:
   `{"since": "<cursor>", "type": "var-dump", "search": "Trace #", "limit": 200}`.
2. Memory that grows with every iteration under the same load is the leak; memory that levels off after warm-up is
   caches filling.
3. Bisect: add more `tr()` calls through the request to see which stage grows, or dump the size of a suspect, a
   static array, a registry, the listeners of an event dispatcher:
   `trap(listeners: \count($dispatcher->getListeners()))`.
4. `trap()::profile(new XHProf(memory: true))` around one iteration shows what allocated the memory, see
   [profiling.md](profiling.md).
