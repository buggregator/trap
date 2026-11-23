# Integrations

Point what the application already sends at Trap, then read it as events. Trap detects the protocol of each
connection, plain or TLS, so every channel works on any of its ports; the ones below are the conventional ones. A
client set up for `https://`, `smtps://` or `tls://` keeps its scheme, see [server.md](server.md#tls). Make each change
in a local `.env` or the environment of the run: it is a setting of the user's application. Check a channel by
triggering it and waiting for its event type.

## Sentry

```bash
SENTRY_DSN=http://sentry@127.0.0.1:8000/1          # Laravel: SENTRY_LARAVEL_DSN
```

The key and the project ID can be anything. Events: `sentry`; `get_event` gives the exception chain with the code
around the frames, the request and the breadcrumbs, the fastest way to an exception the application swallowed into
its error reporting. The `sentry` events are the errors: a transaction is a trace, read it with `list_traces` and
`get_trace` (in an envelope with an error, the error is still the event; a transaction without a trace ID stays a
`sentry` event). Sentry logs and trace metrics make no event
and show only on the Sentry pages of the web UI. User feedback (`feedback`, `user_report`) makes no event either: the
web UI shows it on its error and the Feedback tab, and `list_feedback` reads it, see
[mcp.md](mcp.md#user-feedback). A body over 2 MB gets `413`, and an event Trap can't parse is dropped
with a warning in its console. The `X-Buggregator-Project` header sets the `project` of the event.

## Monolog

```php
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\SocketHandler;

$handler = new SocketHandler('127.0.0.1:9913');
$handler->setFormatter(new JsonFormatter());
$logger->pushHandler($handler);
```

Laravel, in `config/logging.php`, then `LOG_CHANNEL=socket` or add it to the `stack` channel:

```php
'socket' => [
    'driver' => 'monolog',
    'handler' => \Monolog\Handler\SocketHandler::class,
    'formatter' => \Monolog\Formatter\JsonFormatter::class,
    'handler_with' => ['connectionString' => env('LOG_SOCKET_URL', '127.0.0.1:9913')],
],
```

Events: `monolog`, fields `level` and `channel`. A project without Monolog: `trap()::logger()`, see
[dumps-and-logs.md](dumps-and-logs.md#log-records).

## Ray

`spatie/ray`: `'port' => 8000` in `ray.php`; Laravel: `RAY_PORT=8000`. Or start Trap on the port the client already
uses: `-p` replaces the default ports, so list them all,
`vendor/bin/trap --mcp -p1025 -p4318 -p8000 -p9912 -p9913 -p23517`. Events: `ray`, fields `payload_type`, `label`, `color`,
`exception`. An updated Ray message, as `ray()->...` chains send, is one event listed again at its latest change.
`ray()->pause()` waits for Continue or Stop in the web UI; without `--ui` it goes on at once. The summary of its
event shows `create_lock: {"name":...}`, with `"state":"continued"` or `"state":"stopped"` once released: the event
is listed again then. A pause released before its event arrives is listed once, already with the state.

## Mail

```bash
MAILER_DSN=smtp://127.0.0.1:1025                    # Symfony Mailer
MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=1025 # Laravel
```

A username and password are optional: Trap takes any over `AUTH PLAIN` or `LOGIN`; a mailer that knows only
`CRAM-MD5` gets `504`. Implicit TLS works (`smtps://127.0.0.1:1025?verify_peer=0`); `STARTTLS` is offered only with a
certificate in `TRAP_TLS_CERT`, and drops the credentials until the client authenticates again. Events: `smtp`, fields
`subject`, `from`, `to`, `cc`, `bcc`; `get_event` gives the protocol (with `AUTH`, `USERNAME`, `PASSWORD` when the
mailer authenticated, plus `AUTHZID` when a `PLAIN` client acts for another user), the headers, the text and HTML parts
and the attachments, all decoded from quoted-printable and base64. The text and HTML are UTF-8, converted from the
declared charset by mbstring or iconv; a part in a charset neither knows keeps its raw bytes, and one with no charset is
taken as UTF-8. No mail leaves the machine.

The other versions of a body, such as Apple Watch (`text/watch-html`), AMP (`text/x-amp-html`), a calendar
(`text/calendar`) or a delivery report, are the parts shown inline (an `inline` disposition or none) and unnamed;
a part named in `Content-Disposition` or `Content-Type`, or attached, is a file. The first plain text and the first
HTML part are the message text even when named in `Content-Type`; a named one after them is a file. The summary ends
with the versions and the invite, as `...; versions: Apple Watch, AMP, Calendar; invite` (or `cancelled invite`, `invite reply`).
`get_event` prints each version as a body named by its kind, and the invite (the first event of a calendar version,
else of a calendar attachment) in an `Invite` section in place of its calendar text: summary, times in the zone of
the event (`DTEND`, or `DTSTART` plus `DURATION`; an all-day event without them, or with a duration that is not
a positive number of whole days, lasts one day), place, organizer, guests with their `PARTSTAT`. An invite with
`METHOD:CANCEL` or `STATUS:CANCELLED` is cancelled. In the web UI each version is a tab, the invite a card above the
body.

`subject` and the contact names come decoded from RFC 2047 words: `where` on `subject` and a `search` match the
plain text, such as `Привет, мир`. `from`, `to`, `cc` and `bcc` hold the addresses, so match a name with `search`.
`from` holds each address once: the envelope sender and the `From` header merged. An attachment's file name comes from
`filename` of `Content-Disposition`, else from `name` of `Content-Type`, decoded from RFC 2047 words and RFC 2231
`*=` parameters alike.

## OpenTelemetry

```bash
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
```

Read traces with `list_traces` and `get_trace`, not the event tools; they list Sentry traces too. Trap takes logs and metrics too
(`/v1/logs`, `/v1/metrics`), but shows them only in the console and the web UI: no MCP tool reads them. `http/protobuf`
needs `open-telemetry/gen-otlp-protobuf` and `google/protobuf` in the project that runs Trap. The web UI files the
data under the project of the path `/api/{project}/integration/otlp/v1/traces` (also `/logs`, `/metrics`), else of the
`X-Buggregator-Project` header (`OTEL_EXPORTER_OTLP_HEADERS=X-Buggregator-Project=shop`), else `default`. A numeric
`{project}` is the project ID of a Sentry DSN and names no project.

A request over the limits gets `413` with the reason: 10 MB of body after decompression; 10 000 spans, log records or data points;
over protobuf 240 000 elements (attributes, events, links, repeated values), 2 000 000 fields and 40 MB of text;
over JSON 520 000 objects and arrays. Without `ext-protobuf` the protobuf limits are 20 000 elements, 200 000 fields
and 16 MB of text, an SDK batch of 512 spans: on `413`, install it, switch to `http/json` or export smaller batches.
Protobuf with groups, invalid tags or nesting deeper than 64 messages gets `400`. An exporter running in a browser
can't send: Trap answers no CORS preflight.

## VarDumper

`dump()` and `dd()` of Symfony reach Trap with `VAR_DUMPER_FORMAT=server` (and `VAR_DUMPER_SERVER`, default
`127.0.0.1:9912`) in the environment or a `.env` loaded into `$_SERVER`. Symfony's DebugBundle:
`debug.dump_destination: "tcp://%env(VAR_DUMPER_SERVER)%"`. Events: `var-dump`.

## HTTP requests

Any HTTP request no other channel takes is caught whole: method, URI, headers, cookies, query, body and uploaded
files. Point a webhook, an HTTP client or a form at `http://127.0.0.1:8000/<any path>` to see what it really sends.
Trap answers `404`. Events: `http-dump`, fields `method`, `path`, `host`. With `--ui` on that port, `GET /` and the
UI's own `/api/...` routes are the UI's, see [server.md](server.md#ports): send to another path.

## Web UI: logs, filters, the request of a trace

With `--ui` the user reads Monolog records on the Logs page: a case-insensitive search over the message, channel,
context and extra, and level and channel filters. A filter bar narrows every feed by the values Trap takes from the
events; the MCP tools filter by event fields instead:

- `monolog`: channel, level, host from `extra.hostname` (Monolog's `HostnameProcessor`);
- `sentry`: host (`server_name`), environment, release, level, the logger as the channel, the user (email, username,
  ID or IP address), tags;
- `ray`: host of each payload's `origin`; `profiler`: host (`hostname`) and tags;
- `var-dump`: host of the request the dump was made in, none from the CLI; `http-dump`: host of the `Host` header.

An event joins the request view of a Sentry or OpenTelemetry trace by the trace ID: `trace_id` in `extra` or `context`
of a Monolog record, or a `sentry-trace`, `traceparent` or `X-Trace-Id` header on an HTTP dump or a mail. To show the
logs, mails and webhooks of one request beside its spans, put the trace ID there.

## Verify side effects

To check what a scenario sends out, rather than to debug it: point the channels at Trap, take a cursor, run the
scenario, then list everything since the cursor and compare with what should happen:

- One mail to the customer: `{"since": "<cursor>", "type": "smtp"}` returns exactly one event, its `to` field is the
  customer, and `get_event` shows the expected subject and links.
- No error reported: `{"since": "<cursor>", "type": ["sentry", "monolog"], "where": {"level": ["error", "critical"]}}`
  returns no events.
- The webhook went out once, with the right payload:
  `{"since": "<cursor>", "type": "http-dump", "where": {"path": "/hooks/*"}}`.

Nothing leaves the machine meanwhile: the mails, the reports and the requests stop at Trap.

## Check the wiring

`vendor/bin/trap test` sends a sample of every kind of data Trap takes to port 9912 (`-p` for another) and exits
with an error when Trap refuses a part. When its events arrive and the application's don't,
look at the settings of the application, not at Trap.
