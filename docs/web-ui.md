# Web UI

The `--ui` option of [`run`](cli.md#run) starts the web interface of the Buggregator Server inside Trap.
The CLI marks it experimental.

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
  (default `127.0.0.1`).

```bash
# UI on its own port 8080, no console output
vendor/bin/trap --ui=8080 -s
```

## What it shows

Dumps, Monolog logs, Sentry events, emails, Ray payloads, HTTP dumps and profiles.
[OpenTelemetry traces](opentelemetry.md) are shown only in the console.

The UI keeps the last 200 events; the `maxEvents` attribute in `trap.xml` changes it.
The events live in the memory of Trap and are gone when it stops.

The UI also controls `ray()->pause()`: see [Ray](ray.md#notes).
