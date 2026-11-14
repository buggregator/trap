# Senders

Senders decide where the server puts what it receives. The default is `console`.
Choose senders with the `-s` (`--sender`) option of [`run`](cli.md#run), as many as needed:

```bash
vendor/bin/trap -sconsole -sfile
```

| Sender         | What it does                                                                                           |
|----------------|--------------------------------------------------------------------------------------------------------|
| `console`      | Prints each event in the console.                                                                      |
| `file`         | Writes the events as JSON lines to `runtime/dump-<time>.log`.                                          |
| `file-body`    | Writes the raw body of each event to its own file in `runtime/body/`, e.g. the bytes of a binary dump. |
| `mail-to-file` | Saves each email as a JSON file in a folder per recipient in `runtime/mail/`.                          |
| `server`       | Forwards the events over TCP to `127.0.0.1:9099`.                                                      |
| `ui`           | Shows the events in the [web UI](web-ui.md). The `--ui` option adds it.                                |

Paths are relative to the working directory of Trap. Folders are created as needed.

## `mail-to-file`

Useful for testing mails. Each recipient (`To` and `RCPT TO`) gets a folder, and each message a file named by the
time it was received. For a mail sent `To: foo@example.com, bar@example2.org` it creates:

- `runtime/mail/foo@example.com/<time>.json`
- `runtime/mail/bar@example2.org/<time>.json`

## `file`

Each event becomes one JSON line. E.g. an [OpenTelemetry](opentelemetry.md) export request is saved as
`{"type":"otlp.traces","resourceSpans":[...]}`.

## `server`

Sends the events to a remote Buggregator server. The address is fixed to `127.0.0.1:9099`; Trap reconnects every
second while it is unreachable. Events that make sense only locally are not forwarded.

To send dumps from your code to a Buggregator Server instead, point the client at it, e.g.
`VAR_DUMPER_SERVER=buggregator-host:9912` (see [var-dumper](var-dumper.md)).
