# HTML pages

Trap catches a page of the sender's own markup as an `html` event: a report a job or a test tool posts, or a page
you post to show the user a table, a diff or a chart in the web UI. The full reference:
<https://github.com/buggregator/trap/blob/master/docs/html.md>.

## Send a page

- `POST` the markup to `http://127.0.0.1:8000/html/<project>`, or to any path as `http://html:<project>@127.0.0.1:8000`
  or with `X-Buggregator-Event: html`. One marked for another type goes to that module.
- The body is the markup (`Content-Type: text/html; charset=…`), or `{"title": "…", "html": "…"}` with
  `Content-Type: application/json`. Without a JSON `title` the title is the page's `<title>`; 300 characters at most.
- The project is the path's `<project>`, else the password of the Basic user `html`, else `X-Buggregator-Project`,
  else `default`.
- Trap answers `204`; empty markup or other JSON gets `400` with the reason, a body over 1 MiB `413`.
- The UI runs the page's scripts in a sandboxed frame without an origin: they can't call the UI API or its
  WebSocket. Put the data the page shows into its markup.

```bash
curl -X POST http://127.0.0.1:8000/html/my-app -H 'Content-Type: application/json' \
  --data '{"title": "Slow queries", "html": "<table><tr><td>users</td><td>120 ms</td></tr></table>"}'
```

## Read a page

- `list_events` `{"since": "<cursor>", "type": "html"}`: the summary is the title (else the start of the visible
  text) and the size.
- `get_event` gives the title, the size in bytes and the markup, cut to `max_length` (50 000 characters by default):
  raise it to read a larger page whole.
- Filter with `where`: `title`; `kind: page` leaves out [binary data](#binary-data). `search` looks through the
  markup, tags and attributes included.

## Binary data

Bytes on a TCP port that no protocol took, binary or text, become one `html` event per connection, titled
`Binary data` with the guessed format: `Binary data: TLS ClientHello for api.example.com`. It is the usual sign
of a client pointed at the wrong port or speaking another protocol: TLS to a plain port, HTTP/2 without TLS,
PostgreSQL, FastCGI (a PHP-FPM address that leads to Trap), Redis. Full reference:
<https://github.com/buggregator/trap/blob/master/docs/binary.md>.

- A connection that sends no bytes, as a port check or a TCP health check, makes no event; the console prints it.
- `list_events` `{"type": "html", "where": {"kind": "binary"}}`; `where` `{"format": "tls"}` by the guessed format
  (`tls`, `http2`, `postgres`, `fastcgi`, `redis`, `gzip`, `json`, `text`…). A page has `kind` `page`.
- `get_event` gives the size, the guessed format with a hint, the entropy and a hexdump of the first 1024 bytes.
- With the web UI on, `GET http://127.0.0.1:8000/api/binary/<id>` downloads all the bytes; the UI shows a hexdump
  card and a viewer with search, strings and statistics.
