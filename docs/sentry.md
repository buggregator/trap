# Sentry

Trap receives the events of [Sentry SDKs](https://docs.sentry.io/platforms/) and shows the errors with their stack
traces. Point the DSN of the SDK at Trap.

## Receive events

Use any HTTP port of Trap, e.g. `8000`. The public key and the project ID of the DSN can be anything:

```bash
SENTRY_DSN=http://sentry@127.0.0.1:8000/1
```

In Laravel, the variable is `SENTRY_LARAVEL_DSN`.

Trap takes two kinds of requests:

| Request                                                                                                        | Sent by      |
|----------------------------------------------------------------------------------------------------------------|--------------|
| `POST …/envelope/` with `Content-Type: application/x-sentry-envelope`                                          | current SDKs |
| `POST …/store/` with the `X-Sentry-Auth` header, the `sentry` user in the URL or `X-Buggregator-Event: sentry` | older SDKs   |

## Notes

- A request body may be up to 2 MB; a bigger one gets `413`.
- An event Trap can't parse is dropped, and a warning in the console tells why.
