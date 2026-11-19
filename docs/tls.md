# TLS

Every port Trap listens on accepts TLS next to plain traffic: a connection that opens with a TLS handshake is
decrypted and then recognized like any other. There is nothing to turn on and no port to add, so a client
configured for `https://`, `smtps://` or `tls://` talks to the same port as a plain one:

- HTTP over TLS: Sentry DSNs, OTLP exporters, Ray, profilers and HTTP dumps with an `https://` URL;
- the [web UI](web-ui.md) at `https://127.0.0.1:8000`, its WebSocket over `wss://`;
- SMTP with implicit TLS (`smtps://`), and `STARTTLS` with [your own certificate](#starttls);
- Monolog sockets and [`trap()::logger()`](functions.md#traplogger) with a `tls://` address.

Dumps of Symfony VarDumper and `trap()` stay plain: VarDumper writes with `stream_socket_sendto()`, which PHP sends
past TLS, so a `tls://` `VAR_DUMPER_SERVER` loses every dump without an error.

TLS needs ext-openssl in the PHP that runs Trap. Without it Trap takes plain traffic only and says so in the log
with `-v`. The [binaries](installation.md) are built with it.

## Certificate

By default Trap generates a self-signed certificate for `localhost`, `127.0.0.1` and `::1` and prints its path at
startup:

```text
 TLS  Self-signed certificate: /tmp/trap-tls-1000/localhost.pem
```

The file holds the certificate and its key. It lies in a directory of the system temporary directory, one per
user, and is reused by the next runs until it is about to expire; it is valid for 397 days. Clients don't trust it,
so either turn off the certificate check in the client, add this certificate to the trusted ones, or give Trap a
certificate they trust:

| Variable        | `trap.xml`        | Meaning                                                              |
|-----------------|-------------------|----------------------------------------------------------------------|
| `TRAP_TLS_CERT` | `/trap/tls/@cert` | PEM certificate. Without it the self-signed one is used.             |
| `TRAP_TLS_KEY`  | `/trap/tls/@key`  | PEM private key of the certificate, when it is not in the same file. |

A missing file, or a key that doesn't match the certificate, turns TLS off with a warning.

[mkcert](https://github.com/FiloSottile/mkcert) makes a certificate that browsers, curl and PHP trust on your
machine:

```bash
mkcert -install
mkcert -cert-file trap.pem -key-file trap-key.pem localhost 127.0.0.1 ::1
TRAP_TLS_CERT=trap.pem TRAP_TLS_KEY=trap-key.pem vendor/bin/trap
```

## Clients

```bash
# Sentry: needs a trusted certificate, or sentry-php with 'http_ssl_verify_peer' => false
SENTRY_DSN=https://key@127.0.0.1:8000/1

# Symfony Mailer: implicit TLS without checking the self-signed certificate
MAILER_DSN=smtps://127.0.0.1:1025?verify_peer=0

# trap()::logger() over TLS, see functions.md
TRAP_MONOLOG_HOST=tls://127.0.0.1
```

Most clients check the certificate by default: with the self-signed one, turn the check off in the client or
trust the certificate.

## STARTTLS

The SMTP server offers `STARTTLS` only with a certificate set by `TRAP_TLS_CERT` or `trap.xml`. Mailers such as Symfony Mailer
and PHPMailer upgrade to TLS on their own once it is offered and check the certificate, so with the self-signed one
every plain mail would fail. Implicit TLS (`smtps://`) works with any certificate: the client asks for it.

## Limitations

- TLS 1.2 only. With TLS 1.3 the server sends session tickets after the handshake, and a client that writes
  and closes the connection without reading, as log and dump handlers do, resets the connection: its data is lost.
- HTTP/1.1 only: the server offers no HTTP/2.
- No client certificates (mutual TLS).
- A handshake that doesn't complete in 10 seconds is dropped.
