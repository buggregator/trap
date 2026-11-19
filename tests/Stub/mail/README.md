# Raw emails

Email messages as an SMTP client sends them after `DATA`, without the final `.` line. Lines end with CRLF.
Every one carries the same 1×1 PNG (70 bytes) and PDF (77 bytes), so the tests check the decoded size.

- `symfony-*.eml` — written by `Email::toString()` of `symfony/mime` 8.1:

  ```php
  $email = (new Email())->from('Trap Sender <sender@example.com>')->to('receiver@example.com')
      ->text("Hello from the plain text part.\r\n")
      ->html('<p>Hello from the <b>HTML</b> part.</p><img src="cid:logo">')
      ->embed($png, 'logo', 'image/png')
      ->attach($pdf, 'report.pdf', 'application/pdf');
  ```

  `symfony-alternative.eml` has no embedded image and attachment, `symfony-related.eml` has no text and attachment.
- `thunderbird.eml`, `outlook.eml`, `gmail.eml`, `apple-mail.eml` — hand-written in the structure and headers
  of the emails these clients send: Thunderbird puts a preamble before the first part, folds `Content-Type`
  and percent-encodes a non-ASCII file name in `filename*=`; Gmail gives attachments a `Content-ID`;
  Apple Mail puts an attached PDF inline, with a `Content-ID`, between two HTML parts.
- `name-in-content-type.eml` — in the structure of Outlook Express and older mailers: files named only by `name`
  of `Content-Type`, plain, as an RFC 2047 word and in RFC 2231 continuations; the embedded image has no
  `Content-ID` and `Content-Disposition`, the HTML refers to it by that name.
- `calendar-invite.eml` — a Google Calendar invitation: `text/calendar` next to the text and HTML.
- `deeply-nested.eml` — four levels of multipart; the embedded image has a `Content-ID` only.
- `forwarded-as-attachment.eml` — a whole email attached as `message/rfc822`.
