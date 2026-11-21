# Web UI

The `--ui` option of [`run`](cli.md#run) starts the web interface of the Buggregator Server inside Trap:
the build of [buggregator/frontend](https://github.com/buggregator/frontend) 1.39.0. The CLI marks it experimental.

```bash
vendor/bin/trap --ui
```

Then open <http://127.0.0.1:8000> in the browser.

![trap-ui](https://github.com/buggregator/trap/assets/4152481/1ccc2c85-2f81-4b62-8ae7-49ee76380674)

## Port

The UI port is `8000` by default. Change it with `--ui=<port>`, `TRAP_UI_PORT` or [`trap.xml`](configuration.md#trapxml);
the environment variable wins.

- When the UI port is one of the TCP ports (`8000` is), the UI shares it with the rest of the traffic.
  The port listens on `TRAP_TCP_HOST`.
- Otherwise the UI gets a port of its own, which serves only the UI and listens on `TRAP_UI_HOST`
  (default `127.0.0.1`). The setup guide of the UI still puts that port in the HTTP addresses, as DSNs: send HTTP
  to a TCP port instead, `8000` by default.

On a shared port the UI takes only its own requests, and the others stay [HTTP dumps](http-dump.md):

- `/`, the files of the UI build and `/api/...` routes the UI has;
- a page of the UI opened in a browser: a `GET` that accepts `text/html` of `/settings`, `/favorites`,
  `/sentry/...`, `/{type}` or `/{type}/{id}` with an event type of the UI, as `/smtp/{uuid}`;
- any `/api/...` call made from a page of the UI (`Sec-Fetch-Site: same-origin`), answered 404 when Trap has no
  such endpoint.

The UI asks for no password or token, the [MCP token](mcp.md#security) included: anyone who reaches its port sees
the caught events. Trap warns at startup when the port listens on a host other than a loopback one.

```bash
# UI on its own port 8080, no console output
vendor/bin/trap --ui=8080 -s
```

## What it shows

Dumps, Monolog logs, Sentry events, emails, Ray payloads, HTTP dumps, profiles and
[Xdebug traces](xdebug-traces.md) with their call tree.

The UI keeps the last 200 events; `/trap/events/@maxEvents` in [`trap.xml`](configuration.md#trapxml) changes it.
The events live in the memory of Trap and are gone when it stops. The [MCP server](mcp.md) works with the same
events: an event deleted in the UI is gone for it too.

The UI also controls `ray()->pause()`: see [Ray](ray.md#notes).

The UI has pages for features of the Buggregator Server that Trap has not: Sentry traces, logs and metrics,
metrics, the mock server, Xdebug, updates. They show no data, and the menu of the mock server stays while HTTP
dumps are on: hide it in Settings › Menu.
