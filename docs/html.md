# HTML pages

Send Trap a page of your own markup: a report of a nightly job, a link to a tool's page, a kind of event Trap has
no module for yet. The [web UI](web-ui.md) lists the pages under HTML pages and shows each as it is, with its CSS
and scripts; the console prints its title and the start of its text, and the [MCP tools](mcp.md#html-pages) read
its markup.

## Send a page

`POST` the markup to an HTTP port of Trap, `8000` by default:

```bash
curl -X POST http://html:my-app@127.0.0.1:8000 \
  -H 'Content-Type: text/html' \
  --data '<title>Nightly report</title><h1>All green</h1>'
```

Trap takes a page from a `POST`:

- to `/html` or `/html/{project}`, with any content type;
- marked `html` with the `X-Buggregator-Event` header or the user of Basic authorization, to any path, as the
  `http://html@127.0.0.1:8000` URL above sends.

A request marked for another kind of event is left to it. Any other method goes on too: `GET /html/{uuid}` is the
page of the event in the web UI.

The body is the markup, or JSON with `Content-Type: application/json`:

```bash
curl -X POST http://127.0.0.1:8000/html/my-app \
  -H 'Content-Type: application/json' \
  --data '{"title": "Nightly report", "html": "<h1>All green</h1>"}'
```

- The title is the JSON `title`, else the text of the page's `<title>`, on one line and cut to 300 characters.
  A page may have none.
- The markup is converted to UTF-8 from its byte order mark (UTF-8, UTF-16LE or UTF-16BE), else from the `charset`
  of the content type, else from a `<meta charset>` in the first 1024 bytes of the page, else read as UTF-8; markup
  that is not valid UTF-8 and declares no charset is read as windows-1252, as browsers do.
- The project is the `{project}` of the path, else the password of the `html` user of Basic authorization
  (`http://html:my-app@127.0.0.1:8000`), else the `X-Buggregator-Project` header, else `default`.

From PHP:

```php
file_get_contents('http://127.0.0.1:8000/html/my-app', context: stream_context_create(['http' => [
    'method' => 'POST',
    'header' => 'Content-Type: text/html; charset=utf-8',
    'content' => $html,
]]));
```

Trap answers a page with `204` and an empty body. It answers `400` with the reason in plain text to empty markup
or JSON other than `{"title": "…", "html": "…"}`, and `413` to a body larger than 1 MiB.

## What Trap shows

One request is one event of the `html` type. The web UI shows the page in a sandboxed frame: its CSS applies, its
scripts run on the page of the event and not in the feed. The frame has no origin, so its scripts can't reach the
UI, its API or its WebSocket, see [Web UI](web-ui.md). Links open in a new tab.

The console prints the title, the size and the project, and the first 200 characters of the visible text: the page
without its head, scripts, styles and tags.

The feed list `/api/events/preview` and the live feed carry the title, the size and the first 300 characters of the
visible text, and the markup only of a page up to 32 KiB: the card of a larger page shows its text, and the page of
the event loads the markup from `/api/event/{uuid}`.

The UI search finds a page by its title and the first 65536 characters of its visible text. In the [MCP tools](mcp.md#html-pages) the summary
is the title and size, and `get_event` gives the title, the size and the markup.

[Binary data](binary.md) shows as `html` events too: a page has the `kind` field `page` in the MCP tools, binary data
`binary`.

`vendor/bin/trap test` sends a page.
