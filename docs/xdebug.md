# Xdebug debugger

With `--xdebug`, Trap takes Xdebug step-debugging sessions (DBGp). It relays a session to your IDE, or, with no IDE
listening, drives it itself: the script stops at a breakpoint, and you read the stack and the variables and step on
from the Xdebug page of the [web UI](web-ui.md), while AI agents do the same with the [MCP tools](mcp.md#xdebug-debugger).
The CLI marks it experimental.

```bash
vendor/bin/trap --ui --xdebug
```

```ini
xdebug.mode=debug
xdebug.client_host=127.0.0.1
xdebug.client_port=9003
xdebug.start_with_request=trigger
```

## Port and the IDE

Xdebug connects to its `xdebug.client_port`, `9003` by default, the port IDEs such as PhpStorm listen on. Trap
listens on `9003` only with `--xdebug`; the default ports stay as they are. Two ways to run it beside an IDE:

- Move the IDE to `9004` (in PhpStorm: Settings → PHP → Debug → Debug port). Trap relays the sessions to it and
  shows them in the UI too.
- Leave the IDE on `9003` and give Trap another port, `--xdebug=9013`, with `xdebug.client_port=9013` for the
  scripts Trap should take.

Trap recognizes DBGp on any of its ports, so `xdebug.client_port` may also point at a port Trap listens on anyway,
such as `9912`, once `--xdebug` is given. Without `--xdebug`, a script that connects runs on without a debugger,
and the console says why.

## Modes

The mode decides how a new session is driven; change it on the Xdebug page, it applies to the next sessions.

| Mode         | Session                                                                                       |
|--------------|-----------------------------------------------------------------------------------------------|
| `auto`       | Relayed to the IDE when it answers on `TRAP_XDEBUG_IDE`, driven by Trap otherwise (default)   |
| `proxy`      | Relayed to the IDE; with no IDE the script runs on without a debugger                         |
| `standalone` | Always driven by Trap                                                                         |

A relayed session is the IDE's: it steps and sets the breakpoints, and the UI and the agents read the stack and the
variables while it is paused. A session driven by Trap gets the [saved breakpoints](#breakpoints) and runs to the
first one, or stops at the first line with `TRAP_XDEBUG_PAUSE_ON_FIRST_LINE`.

A session driven by Trap and left paused for `TRAP_XDEBUG_IDLE_TIMEOUT` seconds with no command is released: the
script runs on. A closed session stays in the list for 10 minutes.

## Breakpoints

Breakpoints set on the Xdebug page or by an agent are saved in Trap's memory and sent to every session Trap drives:

- a line, by the absolute path as PHP sees it, `/var/www/src/Checkout.php` or `C:\app\src\Checkout.php`, and a line;
  an optional condition is PHP code, so it needs `TRAP_XDEBUG_ALLOW_EVAL`;
- an exception, by a class name: `RuntimeException` stops on it and its subclasses, `*` on any. `*` stops on every
  exception the framework and the libraries throw and catch inside, too: a class of your project stops where you
  look. The agents get the class and the message of the exception with the pause.

Xdebug may move a line breakpoint off a line with no code, as a blank line or the middle of a multi-line statement,
to the next line with code, at worst the closing brace of the function: the page and `xdebug_breakpoints` show the
line it took, and how often it stopped there.

At an exception break PHP has the exception in flight and opens no files, so Xdebug can't read the source. Trap
then reads the file itself, only when Xdebug connected from a loopback address (its paths are then this host's),
and only a file the session reported running (its entry file, a pause, a stack frame), by a plain local path (a
`file:///` URI without a host, no network share, no other stream wrapper), with a PHP source extension (`.php`,
`.phtml`, `.phpt`, `.inc`, `.module`), not empty and up to 4 MB, a line cut at 64 KB. A file on a mapped network
drive holds Trap up while it is read. In Docker, or for another file, step over once to see the source.

## Settings

| Variable                          | `trap.xml`                        | Default          | Meaning                                                                |
|-----------------------------------|-----------------------------------|------------------|------------------------------------------------------------------------|
| `TRAP_XDEBUG_PORT`                | `/trap/xdebug/@port`              | `9003`           | Port Xdebug connects to; takes precedence over `--xdebug=<port>`       |
| `TRAP_XDEBUG_HOST`                | `/trap/xdebug/@host`              | `TRAP_TCP_HOST`  | Host of that port, when it is not one of the TCP ports                 |
| `TRAP_XDEBUG_IDE`                 | `/trap/xdebug/@ide`               | `127.0.0.1:9004` | Where the IDE listens, `host:port`                                     |
| `TRAP_XDEBUG_MODE`                | `/trap/xdebug/@mode`              | `auto`           | `auto`, `proxy` or `standalone`                                        |
| `TRAP_XDEBUG_ALLOW_EVAL`          | `/trap/xdebug/@allowEval`         | `false`          | Eval and breakpoint conditions: both run PHP code in the script        |
| `TRAP_XDEBUG_ALLOW_CONTROL`       | `/trap/xdebug/@allowControl`      | `true`           | Agents may step, continue, stop and set breakpoints                    |
| `TRAP_XDEBUG_PAUSE_ON_FIRST_LINE` | `/trap/xdebug/@pauseOnFirstLine`  | `false`          | A session driven by Trap stops at the first line                       |
| `TRAP_XDEBUG_IDLE_TIMEOUT`        | `/trap/xdebug/@idleTimeout`       | `600`            | Seconds a paused session waits for a command; `0` waits for ever       |

At most 20 sessions are open at once; a script beyond them runs on without a debugger, with a warning in the console.
An IDE that didn't answer is not asked again for 10 seconds, so each new script doesn't wait for it.

## Security

The debugger reads the variables and the source files of your application and, with `TRAP_XDEBUG_ALLOW_EVAL`, runs
code in it. Its API asks for no token, as the rest of the [web UI](web-ui.md), so it guards against the other pages
open in your browser instead:

- it answers only the UI of the same origin: `403 cross_site` for a request the browser marks with a
  `Sec-Fetch-Site` other than `same-origin` or `none`, so also for another port of the same host;
- changes (`POST`, `PUT`, `PATCH`) take `Content-Type: application/json` only (`415` otherwise), which a page of
  another origin can't send without a preflight, and the UI answers no preflight (it sends no CORS headers);
- the WebSocket RPC of the UI can't reach it (`403 http_only`);
- `source` reads only the files a session ran (its entry file, pauses, stacks) or a breakpoint names.

Anyone who reaches the port itself, such as from another machine, isn't stopped by these: keep the UI on a
loopback host. Browsers send no `Sec-Fetch-Site` to an origin that is neither secure nor loopback, as plain HTTP
on a LAN or Docker IP, and old Safari never sends it: there the check above doesn't apply.

Known limits: the UI's WebSocket checks no `Origin`, so a page of any other origin can receive the `xdebug`
channel events (sessions, files and lines, breakpoints, the agents' calls); Trap checks no `Host` header against
DNS rebinding. Caught emails can't reach the debugger: the UI shows their HTML in a sandboxed frame, and
`/api/smtp/{uuid}/html` and their inline files come with `Content-Security-Policy: sandbox`.

## Web UI endpoints

The Xdebug page reads `/api/xdebug` (the settings; `enabled: false` without `--xdebug`), `/api/xdebug/sessions` and
the endpoints of a session below it: `stack`, `contexts`, `variables`, `property`, `source`, `breakpoints`,
`agent-log`, `eval`, and `run`, `step-into`, `step-over`, `step-out`, `stop`, `detach`; the saved breakpoints at
`/api/xdebug/breakpoints`, the settings at `PUT /api/xdebug/settings`, the files seen at `/api/xdebug/files`.
Errors are `{"error", "code", "state"}` with an HTTP status; the codes: `not_paused`, `ide_attached`,
`eval_disabled`, `eval_error` (an error of the expression, as a warning), `not_found`, `xdebug_error`,
`source_unavailable` (the source at an exception break, with no such file on Trap's host), `timeout`, `session_live`,
`bad_request`, and those of the
[guards](#security): `cross_site`, `http_only`, `unsupported_media_type`. Changes come over the
`xdebug` WebSocket channel.
