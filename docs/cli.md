# CLI

The `trap` executable has three commands: `run` (the default), `test` and `joke`.
Depending on how you [installed](installation.md) Trap, call it as `vendor/bin/trap`, `./trap` or `./trap.phar`.

## `run`

Starts the server. It listens on the TCP ports, detects the protocol of each connection and passes what it receives
to the senders. `run` is the default command, so `vendor/bin/trap` equals `vendor/bin/trap run`.

| Option     | Short | Default                                | Meaning                                                                               |
|------------|-------|----------------------------------------|---------------------------------------------------------------------------------------|
| `--port`   | `-p`  | `1025`, `4318`, `8000`, `9912`, `9913` | A port to listen on. Repeat the option for more ports: `-p9912 -p8000`.               |
| `--sender` | `-s`  | `console`                              | Where to send what Trap receives. Repeat the option for more [senders](senders.md).   |
| `--ui`     |       | off                                    | Turns on the [web UI](web-ui.md) (experimental). Takes an optional port: `--ui=8080`. |

The `-v` option prints more messages of the server, and `-vv` also prints its debug messages and errors, e.g. a port
it can't listen on. The other options are the standard ones of Symfony Console: `--help`, `--quiet`, `--version`,
`--ansi`/`--no-ansi`, `--no-interaction`.

Examples:

```bash
# Default ports, console output
vendor/bin/trap

# Only port 9912, web UI on port 8000
vendor/bin/trap -p9912 --ui=8000

# Console and file output
vendor/bin/trap -sconsole -sfile

# Web UI on port 8080 without console output
vendor/bin/trap --ui=8080 -s
```

`-s` without a value removes the default `console` sender. `--ui` adds the `ui` sender to the list.

Environment variables and [`trap.xml`](configuration.md#trapxml) configure the same settings and more;
see [Configuration](configuration.md). An environment variable takes precedence over the option:
`TRAP_TCP_PORTS` over `--port`, `TRAP_UI_PORT` over the port of `--ui`.

Stop the server with `Ctrl+C`. A second `Ctrl+C` exits at once.

## `test`

Sends test data to a running Trap: an XHProf profile, an Xdebug profile, a few `trap()` dumps, a protobuf message,
two emails over SMTP (plain and multipart), three Sentry events (store and envelope) and an image as binary data.
Use it to check that Trap is up and renders everything.

| Option   | Short | Default     | Meaning                 |
|----------|-------|-------------|-------------------------|
| `--port` | `-p`  | `9912`      | Port of the Trap server |
| `--host` |       | `127.0.0.1` | Host of the Trap server |

```bash
# In one terminal
vendor/bin/trap

# In another one
vendor/bin/trap test
```

## `joke`

Sends a random joke to Trap with `trap()`. A quick way to see that the dumps reach the server.

```bash
vendor/bin/trap joke
```
