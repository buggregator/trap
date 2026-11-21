# HTTP dumps

Trap dumps any HTTP request that no other module takes: the method, the URI, the headers, the cookies, the query and
the body, with uploaded files. Use it to see what a webhook, a browser form or an HTTP client really sends.

## Receive requests

Send the request to any port of Trap, e.g. `8000`:

```bash
curl -X POST 'http://127.0.0.1:8000/webhook?source=billing' -H 'Content-Type: application/json' -d '{"paid":true}'
```

Trap answers a dumped request with `404`.

To try it in a browser, open `http://127.0.0.1:8000/_debug`: any path ending with `_debug` shows a test form,
which posts to `/_debug/bar`. That request is dumped and answered with `200`.

![http-dump](https://github.com/buggregator/trap/assets/4152481/48201ce6-7756-4402-8954-76a27489b632)

## Notes

- The requests of Sentry, Ray, the profilers and OpenTelemetry go to their modules and are not dumped.
- When the [web UI](web-ui.md) runs on the same port, it answers `/`, the paths of its files, e.g. `/favicon.ico`,
  its `/api/...` routes, e.g. `/api/events`, and a browser opening a page of the UI, e.g. `/smtp`: see
  [Port](web-ui.md#port).
- The web UI lists the files uploaded with a request and downloads them.
- A gzip-compressed body may inflate, and a chunked body may decode, up to `TRAP_HTTP_MAX_INFLATED_SIZE`
  (default `64M`); a bigger one gets `413`. See [Configuration](configuration.md#server-environment-variables).
- A body sent with `Transfer-Encoding: chunked` is dumped decoded, with `Content-Length` in place of `Transfer-Encoding`.
