# Step debugging with Xdebug

Stop the script at a line, read the stack and the variables, step on: the `xdebug_*` tools drive a session that
Trap holds. Take it when a dump can't show enough, as for state deep in a loop or a value that changes between
two calls. Full reference: <https://github.com/buggregator/trap/blob/master/docs/xdebug.md>.

## Set up

1. Start Trap with `--xdebug` (and `--mcp`): `vendor/bin/trap --mcp --xdebug`. It listens for Xdebug on `9003`.
   `9003` taken, usually by the user's IDE → `--xdebug=9013` and `xdebug.client_port=9013` below; leave the IDE
   where it is. Once `--xdebug` is on, Xdebug may also connect to any port Trap listens on, such as `9912`.
   Without `--xdebug` the `xdebug_*` tools fail, and a script that connects runs on without a debugger.
2. The PHP that runs the code needs `ext-xdebug` and
   `-d xdebug.mode=debug -d xdebug.client_port=9003 -d xdebug.start_with_request=yes` (CLI), or
   `xdebug.start_with_request=trigger` with `XDEBUG_TRIGGER=1` in the request (web).
3. `xdebug_sessions` → `allow_control`, `allow_eval`, `mode`. In `mode: auto` (the default) an IDE answering on
   `TRAP_XDEBUG_IDE` (`127.0.0.1:9004`) gets the session (`driver: ide`): reads work, stepping and breakpoints are
   the IDE's. `mode: proxy` gives every session to the IDE; `TRAP_XDEBUG_MODE=standalone` keeps every session for
   the agent (`driver: here`). Ask the user before changing their setup.

## Debug

1. `xdebug_breakpoints` with `{"action": "set", "file": "<absolute path as PHP sees it>", "line": N}`, or
   `{"action": "set", "exception": "RuntimeException"}` (subclasses too, `*` for any). In Docker the path is the
   one in the container. A `condition` is PHP and needs `TRAP_XDEBUG_ALLOW_EVAL=1`.
2. Start the code in the background (a test, a request): it blocks at the breakpoint until it is resumed.
3. `xdebug_wait` (`timeout` 30 seconds, at most 50) → the paused session; `timed_out` means the code did not reach
   the breakpoint or Xdebug didn't connect (the Trap console says why).
4. `xdebug_location`, `xdebug_stack`, `xdebug_variables` (`name` for one value, as `$order->items`; `frame` a
   `level` of the stack; `page` for the children past 100). Without `session_id` the tools take the only paused
   session.
5. `xdebug_continue` with `command`: `step_over`, `step_into`, `step_out`, `run`; it waits `wait` seconds (10) for
   the next pause. Finish with `run`, `stop` to end the script, or `detach` to let it run on.

- `xdebug_eval` runs PHP in the script; it works only with `TRAP_XDEBUG_ALLOW_EVAL=1`.
- `TRAP_XDEBUG_ALLOW_CONTROL=0` turns `xdebug_continue` and breakpoint changes off: the agent only reads.
- A session left paused for 10 minutes is released (`TRAP_XDEBUG_IDLE_TIMEOUT`).
- The user sees every call of the agent on the Xdebug page of the web UI.
- At most 20 sessions are open at once; a script beyond them runs on without a debugger.
- Clean up: `xdebug_breakpoints` with `{"action": "remove", "id": N}` for each breakpoint you set.
