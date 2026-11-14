# Ray

Trap receives the payloads of [Ray](https://myray.app/) clients, e.g. `spatie/ray` and `spatie/laravel-ray`.

## Receive payloads

Point the Ray client at any HTTP port of Trap, e.g. `8000`, instead of the Ray app port. With `spatie/ray`, set it in
`ray.php`:

```php
return [
    'host' => '127.0.0.1',
    'port' => 8000,
];
```

Or listen on the port the client already uses: `vendor/bin/trap -p23517` plus the other ports you need.

Trap answers the requests of a Ray client:

- `GET /_availability_check` gets an error status, which tells the client to send its payloads.
- `POST /` with the JSON payloads; Trap takes a request from a client with a `User-Agent` starting with `Ray`, or with
  the `X-Buggregator-Event: ray` header.
- `GET /locks/{name}`: the state of `ray()->pause()`.

## Notes

- `ray()->pause()` waits only while the [web UI](web-ui.md) runs: its buttons continue or stop the execution.
  Without the UI the client continues at once, and the console says so.
- A request body may be up to 10 MB; a bigger one gets `413`.
