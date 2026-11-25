# Blackfire probe

Trap takes the profiles of the [Blackfire](https://www.blackfire.io) PHP probe (`ext-blackfire`) in place of
the agent the probe sends them to. Everything stays on your machine: no Blackfire account, agent or upload.
Install the probe yourself; Trap doesn't ship it. Trap is compatible with the probe and not affiliated
with Blackfire.

## Point the probe at Trap

Set `blackfire.agent_socket` to any port of Trap, and give the probe a query:

```bash
vendor/bin/trap --ui

BLACKFIRE_QUERY='expires=4102444800&signature=trap&profile_title=import' \
  php -d blackfire.agent_socket=tcp://127.0.0.1:9912 bin/console app:import
```

For a web request, set `blackfire.agent_socket` in the INI of PHP-FPM or the web server and send the query
in the `X-Blackfire-Query` header:

```bash
curl -H 'X-Blackfire-Query: expires=4102444800&signature=trap' http://localhost/checkout
```

The probe profiles only a request or a command with a query: `BLACKFIRE_QUERY` for a command,
`X-Blackfire-Query` for a web request. Trap doesn't check the signature, so the query above works as it is:

- `expires` - a Unix time in the future; 4102444800 is the year 2100.
- `signature` - any value after `expires`.
- `profile_title` - the `name` tag of the profile, optional.

With Docker, the probe connects to the host and port of Trap as the application container reaches them,
e.g. `tcp://trap:9912`, and Trap listens on all interfaces: `--host=0.0.0.0`.

## What Trap shows

The profile has the calls between each caller and callee, with the wall time, CPU time, memory and peak memory,
as XHProf has; the network and garbage collector costs of the probe are left out. The levels of a recursion are
merged into their function, as with XHProf.

- Details: the request and its response code, or the command; the PHP runtime and the probe version.
- Tags: `name` from `profile_title`, and `request` (`GET /checkout`) or `command`.
- The format of the profile, in the [MCP tools](mcp.md#profiles) too, is `Blackfire`.

A profile over 64 MB, data that is not a profile, or a profile that comes while four others wait to be built
(Trap builds one at a time) is dropped, and a warning in the console tells why. The probe doesn't send it again.

## Requests without a query

On a web request without a query, the probe opens a connection to ask the agent for monitoring. Trap closes it
unanswered, and the probe doesn't ask again for 5 minutes; nothing shows up in Trap.
`blackfire.apm_enabled=0` in the INI turns these connections off.

## With `trap()::profile()`

While the probe profiles a request, [`trap()::profile()`](profiling.md#errors) skips its own profile and logs
the skip: two profilers would count each other's overhead.
