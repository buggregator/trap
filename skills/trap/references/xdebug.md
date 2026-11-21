# Step debugging with Xdebug

Stop the script at a line, read the stack and the variables, step on: the `xdebug_*` tools drive a session that
Trap holds. Take it when a dump can't show enough, as for state deep in a loop or a value that changes between
two calls. Full reference: <https://github.com/buggregator/trap/blob/master/docs/xdebug.md>.

## Set up

1. Start Trap with `--xdebug` (and `--mcp`): `vendor/bin/trap --mcp --xdebug`. It listens for Xdebug on `9003`.
   `9003` taken, usually by the user's IDE → `--xdebug=9013` and `xdebug.client_port=9013` below; leave the IDE
   where it is.
2. The PHP that runs the code needs `ext-xdebug` and
   `-d xdebug.mode=debug -d xdebug.client_port=9003 -d xdebug.start_with_request=yes` (CLI), or
   `xdebug.start_with_request=trigger` with `XDEBUG_TRIGGER=1` in the request (web).
3. `xdebug_sessions` → `allow_control`, `allow_eval`, `mode`. With an IDE on `TRAP_XDEBUG_IDE` and `mode: auto`,
   the IDE gets the session (`driver: ide`): reads work, stepping is the IDE's. `TRAP_XDEBUG_MODE=standalone` keeps
   every session for the agent; ask the user before changing their setup.

## Debug

1. `xdebug_breakpoints` with `{"action": "set", "file": "<absolute path as PHP sees it>", "line": N}`, or
   `{"action": "set", "exception": "RuntimeException"}`. In Docker the path is the one in the container.
2. Start the code in the background (a test, a request): it blocks at the breakpoint until it is resumed.
3. `xdebug_wait` → the paused session; `timed_out` means the code did not reach the breakpoint or Xdebug didn't
   connect (the Trap console says why).
4. `xdebug_location`, `xdebug_stack`, `xdebug_variables` (`name` for one value, as `$order->items`).
5. `xdebug_continue` with `command`: `step_over`, `step_into`, `step_out`, `run`; it waits for the next pause.
   Finish with `run`, or `detach` to let the script run on.

- `xdebug_eval` runs PHP in the script; it works only with `TRAP_XDEBUG_ALLOW_EVAL=1`.
- A session left paused for 10 minutes is released (`TRAP_XDEBUG_IDLE_TIMEOUT`).
- The user sees every call of the agent on the Xdebug page of the web UI.
- Drive sessions with the MCP tools. The UI's `/api/xdebug` refuses browser pages of other origins and takes
  writes as `Content-Type: application/json` only; its `source` reads only files a session ran or a breakpoint names.
- At most 20 sessions are open at once; a script beyond them runs on without a debugger.
- Clean up: `xdebug_breakpoints` with `action: remove` for each breakpoint you set.
