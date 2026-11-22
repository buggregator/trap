# Step debugging with Xdebug

Stop the script at a line, read the stack and the variables, step on: the `xdebug_*` tools drive a session that
Trap holds. Take it when a dump can't show enough, as for state deep in a loop or a value that changes between
two calls. Full reference: <https://github.com/buggregator/trap/blob/master/docs/xdebug.md>.

## Set up

1. Start Trap with `--xdebug` (and `--mcp`): `vendor/bin/trap --mcp --xdebug`. It listens for Xdebug on `9003`.
   `9003` taken (detect reports it), usually by the user's IDE → `--xdebug=9013` and `xdebug.client_port=9013`
   below; leave the IDE where it is. In PowerShell start it by
   [the recipe of server.md](server.md#a-trap-of-your-own-in-powershell). Once `--xdebug` is on, Xdebug may also connect to any port Trap listens on, such as `9912`.
   Without `--xdebug` the `xdebug_*` tools fail, and a script that connects runs on without a debugger.
2. The PHP that runs the code needs `ext-xdebug` and
   `-d xdebug.mode=debug -d xdebug.client_port=9003 -d xdebug.start_with_request=yes` (CLI), or
   `xdebug.start_with_request=trigger` with `XDEBUG_TRIGGER=1` in the request (web).
3. `xdebug_sessions` → `allow_control`, `allow_eval`, `mode`, `pause_on_first_line`. In `mode: auto` (the
   default) an IDE answering on `TRAP_XDEBUG_IDE` (`127.0.0.1:9004`) gets the session (`driver: ide`): reads work,
   stepping and breakpoints are the IDE's. `mode: proxy` gives every session to the IDE;
   `TRAP_XDEBUG_MODE=standalone` keeps every session for the agent (`driver: here`). `pause_on_first_line: true`
   stops every new session at its first line, with no breakpoint (`TRAP_XDEBUG_PAUSE_ON_FIRST_LINE`). Ask the user
   before changing their setup.

## Debug

1. `xdebug_breakpoints` with `{"action": "set", "file": "<absolute path as PHP sees it>", "line": N}`, or
   `{"action": "set", "exception": "App\\OrderException"}` (subclasses too). In Docker the path is the one in the
   container; on Windows `D:\app\x.php` and `D:/app/x.php` both work, and come back as `file:///D:/app/x.php`.
   A `condition` is PHP and needs `TRAP_XDEBUG_ALLOW_EVAL=1`. Name a project exception class: `*` stops at every
   exception the framework and the vendors throw and catch inside, often a dozen before the code you want.
2. Start the code in the background (a test, a request): it blocks at the breakpoint until it is resumed.
3. `xdebug_wait` (`timeout` 30 seconds, at most 50) → the paused session; `timed_out` means the code did not reach
   the breakpoint or Xdebug didn't connect (the Trap console says why).
4. `xdebug_location`, `xdebug_stack` (20 frames, `limit: 0` for all), `xdebug_variables` (`name` for one value, as
   `$order->items`; `frame` a `level` of the stack; `page` for the children past 100). Without `session_id` the
   tools take the only paused session.
5. `xdebug_continue` with `command`: `step_over`, `step_into`, `step_out`, `run`; it waits `wait` seconds (10) for
   the next pause and returns its `file`, `line`, function (`where`) and `source_line`. Finish with `run`, `stop`
   to end the script, or `detach` to let it run on.

- At an exception break `xdebug_wait`, `xdebug_continue`, `xdebug_location` and `xdebug_sessions` carry
  `exception`: `class` and `message`. For the rest of the object, as its previous exception, `step_over` into the
  `catch` and read its variable, as `$e`.
- `step_into` often enters Composer's autoloader first: `step_out` gets back. `step_over` at the last statement of
  a function lands in the caller, at its next call.
- Xdebug moves a breakpoint off a line with no code, as a blank line or the middle of a multi-line statement, to the
  next line that has some, at worst the closing brace of the function. `xdebug_breakpoints` lists each breakpoint
  with its `sessions`: `resolved_line` is where it stops, `hit_count` how often it did.
- `xdebug_eval` runs a PHP expression in the current frame (level 0), only with `TRAP_XDEBUG_ALLOW_EVAL=1`; other
  frames are read with `xdebug_variables` and `frame`. A warning of the expression, as an undefined variable,
  fails the call, and the output of the script stays clean.
- `TRAP_XDEBUG_ALLOW_CONTROL=0` turns `xdebug_continue` and breakpoint changes off: the agent only reads.
- A session left paused for 10 minutes is released (`TRAP_XDEBUG_IDLE_TIMEOUT`).
- The user sees every call of the agent on the Xdebug page of the web UI.
- At most 20 sessions are open at once; a script beyond them runs on without a debugger.
- Clean up: `xdebug_breakpoints` with `{"action": "remove", "id": N}` for each breakpoint you set.
