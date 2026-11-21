# Server

Full reference: <https://github.com/buggregator/trap/blob/master/docs/configuration.md>.

## Install

Ask the user before installing: it changes their dependencies.

- Composer, as a dev dependency: `composer require --dev buggregator/trap -W`. Gives the server
  (`vendor/bin/trap`) and the functions `trap()`, `tr()`, `td()`.
- Without touching the project: the Phar (`https://github.com/buggregator/trap/releases/latest/download/trap.phar`)
  or the self-contained binary from the releases page. They run the server only: dumps then come from Symfony
  `dump()` or the integrations, as `trap()` is not installed.

## Start

```bash
vendor/bin/trap --mcp            # console output, the MCP server on port 8000
vendor/bin/trap --mcp --ui       # plus the web UI at http://127.0.0.1:8000 for the user to watch
vendor/bin/trap --mcp -s         # no console output: the agent reads only through MCP
```

Run it as a background process and keep it for the session: events live in its memory and a restart drops them.
The buffer keeps the last 200 events (`maxEvents` of `trap.xml`), traces their own limits; an event pinned in the
web UI is pushed out last. Keep the process ID to
stop it later: `$trap = Start-Process php 'vendor/bin/trap','--mcp' -PassThru` and `Stop-Process $trap.Id` in
PowerShell, `vendor/bin/trap --mcp & echo $!` and `kill <pid>` in a POSIX shell. Other Traps may run on the machine:
stop only yours. `-vv` prints the server's debug messages and errors, such as a port it can't listen on or a profile
it can't import.

## Ports

Every port takes every protocol; the defaults are the ports the clients send to by default.

| Port   | Default port of                                         |
|--------|---------------------------------------------------------|
| `9912` | `trap()`, VarDumper server, `trap()::profile()`         |
| `9913` | `trap()::logger()`, Monolog socket handler              |
| `1025` | SMTP                                                    |
| `8000` | HTTP: Sentry, Ray, profiles, HTTP dumps, web UI and MCP |
| `4318` | OTLP/HTTP                                               |

- Other ports: `-p9912 -p8000` or `TRAP_TCP_PORTS=9912,8000`; the MCP port: `--mcp=8001` or `TRAP_MCP_PORT`.
  `mcp.php` reads `TRAP_MCP_PORT` too.
- With the web UI on a TCP port, a browser `GET` of a UI page (`/`, `/smtp`, `/sentry/...`, `/{type}/{uuid}`) and an
  `/api/...` call from a UI page go to the UI, not to HTTP dumps; a dump sent by `curl` or an app stays a dump,
  an `OPTIONS` request too. The UI sends no CORS headers: open it from Trap itself.
  The UI on a port of its own (`--ui=8080`) takes no HTTP events, though its setup guide shows that port in the
  DSNs: send HTTP to a TCP port, `8000` by default.
- A Trap of your own beside the user's: one port takes everything,
  `TRAP_TCP_PORTS=18000 TRAP_MCP_PORT=18000 vendor/bin/trap --mcp`. Point the clients at it:
  `VAR_DUMPER_SERVER=127.0.0.1:18000`, `TRAP_MONOLOG_PORT=18000`, `TRAP_PROFILER_PORT=18000`, and the URLs of the
  integrations.
- A port another process holds: Trap keeps retrying it silently and works on the others. `4318` is often held by
  an OpenTelemetry Collector or Jaeger: leave it out with `TRAP_TCP_PORTS=1025,8000,9912,9913`. On Windows Trap may
  bind `127.0.0.1:4318` while that process holds `0.0.0.0:4318`, and then takes the local traffic meant for it.
- The ports held but MCP not answering: a Trap or Buggregator Server of the user runs without `--mcp`. Ask the user
  to restart it with `--mcp`, or run yours on other ports and point the application there.

## TLS

Every port also takes TLS, so an application set up for `https://`, `smtps://` or `tls://` reaches Trap without
changes to its scheme. With ext-openssl Trap generates a self-signed certificate for `localhost`, `127.0.0.1` and
`::1` and prints its path at startup (`TLS  Self-signed certificate: …`).

- Clients check the certificate by default: turn the check off in the client (`verify_peer=0` in a Symfony Mailer
  DSN, `'http_ssl_verify_peer' => false` in sentry-php), or ask the user for a trusted one, e.g. from mkcert, and
  start Trap with `TRAP_TLS_CERT` and `TRAP_TLS_KEY`.
- TLS 1.2 only, HTTP/1.1 only. Details: <https://github.com/buggregator/trap/blob/master/docs/tls.md>.

## Another host

An application in Docker, WSL or a VM reaches Trap on the host only when Trap listens beyond the loopback:

```bash
export TRAP_MCP_TOKEN="$(openssl rand -hex 32)"
TRAP_TCP_HOST=0.0.0.0 vendor/bin/trap --mcp
```

- In the container, the host is `host.docker.internal` (Docker Desktop; on Linux add
  `--add-host=host.docker.internal:host-gateway`): `VAR_DUMPER_SERVER=host.docker.internal:9912`,
  `TRAP_MONOLOG_HOST=host.docker.internal`, a DSN with that host.
- With a token set, every MCP request carries `Authorization: Bearer <token>`; `mcp.php` sends `TRAP_MCP_TOKEN`.
  A token keeps others on the network out of the MCP tools, which read and delete every event.
- The web UI asks for no token: on a non-loopback host keep it off, or on a loopback port of its own
  (`TRAP_UI_PORT`, `TRAP_UI_HOST`).

## Settings

Environment variables win over CLI options, which win over `trap.xml` of the package
(`vendor/buggregator/trap/trap.xml`). Server variables of note: `TRAP_TCP_PORTS`, `TRAP_TCP_HOST`, `TRAP_MCP_PORT`,
`TRAP_MCP_HOST`, `TRAP_MCP_TOKEN`, `TRAP_UI_PORT`, `TRAP_TLS_CERT`, `TRAP_TLS_KEY`, `TRAP_MEMORY_LIMIT` (512M, for large profiles),
`TRAP_XDEBUG_PATH`, `TRAP_XHPROF_PATH`, `TRAP_SPX_PATH` (the directories of profiles to pick up),
`TRAP_XDEBUG_PORT`, `TRAP_XDEBUG_IDE`, `TRAP_XDEBUG_MODE`, `TRAP_XDEBUG_ALLOW_EVAL` (the debugger of `--xdebug`, see
[xdebug.md](xdebug.md)), `TRAP_XDEBUG_TRACE_PATH` and `TRAP_XDEBUG_TRACE_MAX_UPLOAD`, `_MAX_SIZE`, `_KEEP` (Xdebug traces, see
[profiling.md](profiling.md#trace-the-calls)).
