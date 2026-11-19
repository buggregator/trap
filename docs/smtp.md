# SMTP

Trap is an SMTP server that catches the emails your application sends, prints them in the console and shows them in
the [web UI](web-ui.md). No email leaves your machine.

![smtp](https://github.com/buggregator/trap/assets/4152481/b11c4a7f-072a-4e66-b11d-9bbd3177bfe2)

## Receive emails

Point the mailer at port `1025`, the SMTP port of Buggregator, without authentication and encryption.
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

## Save emails to files

The [`mail-to-file` sender](senders.md#mail-to-file) saves each email as JSON in a folder per recipient:

```bash
vendor/bin/trap -sconsole -smail-to-file
```

## Limitations

- Trap supports no `AUTH`: configure the mailer without a username.
- `STARTTLS` is offered only with your own certificate, implicit TLS (`smtps://`) works with any. See [TLS](tls.md).
