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

`vendor/bin/trap test` sends three test emails: a multipart one with every version of the body below, an invite and
an attached file; a cancelled all-day invite; a plain one.

Encoded subjects, names and file names, such as `=?utf-8?Q?...?=`, are shown decoded. A file without a `filename`
in `Content-Disposition` takes the `name` of its `Content-Type`, as Outlook and older mailers name files; such a name
makes a part a file. Some mailers name the message text too, so the first plain text and the first HTML part are the
message text even when named; a named one after them, such as `text/plain; name=log.txt`, is a file.
The sender is listed once when the envelope sender and the `From` header have the same address.

## Other versions and invites

An email may carry more versions of its body than the text and the HTML: the Apple Watch version
(`text/watch-html`), the AMP version (`text/x-amp-html`), a calendar invite (`text/calendar`, `application/ics`),
a delivery report (`message/delivery-status`, `message/disposition-notification`, `text/rfc822-headers`), any other
`text/*` type. Such a part is a version when it is shown with the message, that is with an `inline`
`Content-Disposition` or none, and has no file name. A part with a file name, in `Content-Disposition` or in
`Content-Type`, or with an `attachment` disposition is a file, as mail clients list it.

- The web UI shows each version in a tab of its own next to HTML and Text, an HTML one (Apple Watch, AMP) in the same
  safe frame as the HTML, where scripts don't run. The email list marks the emails with an Apple Watch version, an AMP
  version, an invite or a cancelled one.
- An invite shows as a card above the body: what, when in your time zone, where, the organizer and the guests with
  their answers; a cancelled one is struck out. The invite is the first event of the first calendar version, or of the
  first calendar attachment (`text/calendar`, `application/ics` or a `.ics` name) when there is no such version.
- The console prints each version as a body named by its kind, `Body 2 Apple Watch`, and the invite in an `Invite`
  section in place of the calendar text it is read from, with the times in the zone of the event. `get_event` of the
  [MCP server](mcp.md) shows the same text.

In the event of the web UI API the versions are the `parts` of the payload, in the order of the email, and the
invite is `calendar`; an email without them has neither field:

```json
{
  "parts": [
    {"type": "text/watch-html", "label": "Apple Watch", "content": "<b>Shipped</b>", "size": 14}
  ],
  "calendar": {
    "method": "REQUEST", "summary": "Sprint review",
    "start": "2026-12-05T10:00:00+01:00", "end": "2026-12-05T10:30:00+01:00", "tz": "Europe/Berlin",
    "location": "Room 4", "status": "CONFIRMED", "uid": "sprint-review@example.com", "sequence": "0",
    "organizer": {"name": "Ann", "email": "ann@example.com"},
    "attendees": [{"name": "Bob", "email": "bob@example.com", "status": "ACCEPTED", "role": "REQ-PARTICIPANT"}]
  }
}
```

- A part keeps 1 MB of its content, cut on a character boundary: `size` is the size before the cut and `truncated`
  is `true`. The whole email stays in `raw`.
- `start` and `end` are RFC 3339 when Trap knows the zone, with the IANA name of the zone in `tz`: a UTC time,
  a `TZID` of the IANA database such as `Europe/Berlin`, also with a leading `/` or a vendor prefix such as
  `/mozilla.org/20050126_1/Europe/Berlin`, or a Windows name of Outlook such as `W. Europe Standard Time` when
  `ext-intl` is loaded. An obsolete name tzdata keeps for compatibility, such as `Asia/Calcutta`, `US/Eastern` or
  `EST`, stands for the zone tzdata links it to (`Asia/Kolkata`, `America/New_York`, `America/Panama`), the same on
  every platform, whether or not its tzdata has these names. A current zone of a country stays itself, such as
  `Europe/Oslo`, though tzdata links it to `Europe/Berlin`. Another `TZID` gives local time such as
  `2026-12-03T14:00:00` with the `TZID` in `tz`.
  An all-day event gives dates and `all_day: true`, the end date being the day after the last day.
- `end` is `DTEND`, or `DTSTART` plus `DURATION`; an all-day event with neither, or with a duration that is not
  a positive number of whole days, lasts one day.
- `method` is the `METHOD` of the calendar; `status`, `sequence`, `rrule`, `url`, `description` come from the event.
  An invite with `METHOD:CANCEL` or `STATUS:CANCELLED` is cancelled.
- A calendar attachment is converted to UTF-8 from the `charset` of its `Content-Type`, as the versions are.
- Trap reads the first 1 MB of a calendar, the first 200 guests and 4000 characters of the description. A field the
  event lacks is left out, and so is a guest with neither a name nor an address.

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
