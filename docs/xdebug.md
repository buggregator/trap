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
- an exception, by a class name: `RuntimeException` stops on it and its subclasses, `*` on any.

Xdebug may move a line breakpoint to the next line with code: the page shows the line it took.

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

The Xdebug page and its API ask for no token, as the rest of the [web UI](web-ui.md): keep the UI on a loopback host
when the debugger is on, as eval runs code in your application.

## Web UI endpoints

The Xdebug page reads `/api/xdebug` (the settings; `enabled: false` without `--xdebug`), `/api/xdebug/sessions` and
the endpoints of a session below it: `stack`, `contexts`, `variables`, `property`, `source`, `breakpoints`,
`agent-log`, `eval`, and `run`, `step-into`, `step-over`, `step-out`, `stop`, `detach`; the saved breakpoints at
`/api/xdebug/breakpoints`, the settings at `PUT /api/xdebug/settings`, the files seen at `/api/xdebug/files`.
Errors are `{"error", "code", "state"}` with an HTTP status; the codes: `not_paused`, `ide_attached`,
`eval_disabled`, `not_found`, `xdebug_error`, `timeout`, `session_live`, `bad_request`. Changes come over the
`xdebug` WebSocket channel.
