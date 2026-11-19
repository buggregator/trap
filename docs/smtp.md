# SMTP

Trap is an SMTP server that catches the emails your application sends, prints them in the console and shows them in
the [web UI](web-ui.md). No email leaves your machine.

![smtp](https://github.com/buggregator/trap/assets/4152481/b11c4a7f-072a-4e66-b11d-9bbd3177bfe2)

## Receive emails

Point the mailer at port `1025`, the SMTP port of Buggregator. A username and a password are optional, see
[Authentication](#authentication); for encryption see [TLS](tls.md).
Any port of Trap works: it takes a connection that waits for the server greeting as SMTP.

Symfony Mailer:

```bash
MAILER_DSN=smtp://127.0.0.1:1025
```

Laravel:

```bash
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
```

Trap decodes the text and HTML parts and the attached files from `quoted-printable` and `base64`. It converts the text
and HTML parts to UTF-8 from the charset they declare when `ext-mbstring` or `ext-iconv` knows that charset; a part
in a charset neither knows keeps its bytes, and a part with no charset is taken as UTF-8.

`vendor/bin/trap test` sends two test emails, a plain and a multipart one.

Encoded subjects and names, such as `=?utf-8?Q?...?=`, are shown decoded. The sender is listed once when the envelope
sender and the `From` header have the same address.

## Authentication

Trap accepts `AUTH PLAIN` and `AUTH LOGIN` with any username and password, so you can check what your application
authenticates with:

```bash
MAILER_DSN=smtp://john:secret@127.0.0.1:1025
```

The credentials hold for every email of the SMTP session and show with each of them:

- in the console and in `get_event` of the [MCP server](mcp.md), as `AUTH` (the mechanism), `USERNAME` and
  `PASSWORD` in the `Protocol` table, plus `AUTHZID` when a `PLAIN` client acts for another user;
- in the event of the web UI API, as the `auth` field of the payload:
  `{"mechanism": "LOGIN", "username": "john", "password": "secret", "authzid": null}`, or `null` for an email sent
  without `AUTH`;
- in the JSON of the [`file` and `mail-to-file` senders](senders.md), under `protocol`.

`STARTTLS` starts the session over, and the client authenticates again. Trap offers no `CRAM-MD5`: with it the
password never reaches the server. A mailer that authenticates by `CRAM-MD5` only gets `504`.

## Save emails to files

The [`mail-to-file` sender](senders.md#mail-to-file) saves each email as JSON in a folder per recipient:

```bash
vendor/bin/trap -sconsole -smail-to-file
```

## Limitations

- `STARTTLS` is offered only with your own certificate, implicit TLS (`smtps://`) works with any. See [TLS](tls.md).
- Commands other than `HELO`, `EHLO`, `MAIL`, `RCPT`, `DATA`, `RSET`, `NOOP`, `QUIT`, `AUTH`, `STARTTLS`, `VRFY` and
  `HELP` get `502`.
