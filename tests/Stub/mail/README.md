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
- `thunderbird.eml`, `outlook.eml`, `gmail.eml` — hand-written in the structure and headers of the emails
  these clients send: Thunderbird puts a preamble before the first part and folds `Content-Type`,
  Gmail gives an attachment a `Content-ID`.
- `deeply-nested.eml` — four levels of multipart; the embedded image has a `Content-ID` only.
- `forwarded-as-attachment.eml` — a whole email attached as `message/rfc822`.
