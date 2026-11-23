# CSP reports

Browsers report what a Content Security Policy blocks, and Trap catches the reports as `csp` events: one event per
request of the browser, holding one report (`report-uri`) or a batch (`report-to`). The application only sends a
header. The full reference: <https://github.com/buggregator/trap/blob/master/docs/csp.md>.

## Fix a policy

1. **Add a Report-Only policy** in the dev config of the application, pointing at Trap:
   `Content-Security-Policy-Report-Only: <the policy>; report-uri http://127.0.0.1:8000/csp/<project>`.
   Report-Only blocks nothing, so the page keeps working while it reports. Done when a response of the page carries
   the header: check with `curl -sI <page-url>`.
2. **Open the page**: take a `cursor` with `list_events` `{"order": "newest", "limit": 1}`, then open the page in a
   browser you drive (Playwright, a headless Chrome), or ask the user to open it and click through the parts to
   check.
3. **Read the violations**: `list_events` with `{"since": "<cursor>", "type": "csp", "include": "text"}`. The text
   gives a block per report: the page, the directive, what was blocked (a URL, or `inline`, `eval`), the
   disposition, the source file with line and column, the code sample. Done when every violation is either fixed
   in the code (a nonce or hash, a moved script, a source added to the policy) or reported to the user as intended.
4. **Check again**: reload, wait with `wait_for_events` `{"since": "<cursor>", "type": "csp", "timeout": 10}`;
   done when `timed_out: true` comes back with no events.
5. **Remove the debug config** with the rest of step 5 of the skill: the Report-Only header and its `report-uri`.
   Keep only the policy changes the user wants.

## Setting the header

- Plain PHP: `header("Content-Security-Policy-Report-Only: default-src 'self'; report-uri http://127.0.0.1:8000/csp/my-app");`
- Laravel, `spatie/laravel-csp`: `CSP_REPORT_URI=http://127.0.0.1:8000/csp/my-app` in `.env`; the policy under test in
  `report_only_presets` (v3) or `report_only_policy` (v2) of `config/csp.php`.
- Symfony, NelmioSecurityBundle: `nelmio_security.csp.report` is the Report-Only policy; add
  `report-uri: ['http://127.0.0.1:8000/csp/my-app']` to it under `when@dev`.

## Gotchas

- Any `POST` with `application/csp-report` or `application/reports+json` is caught on any path, so an existing
  report URL works when its host and port point at Trap. The project is the `<project>` of `/csp/<project>`, else
  the password of the Basic user `csp`, else `X-Buggregator-Project`, else `default`. A request with
  `X-Buggregator-Event` or a Basic user of another type goes to that module.
- `report-to` with `Reporting-Endpoints` (`reports+json`) is slower and stricter: Chrome sends it only from an HTTPS
  page to an `https://` endpoint the browser trusts (Trap takes TLS on the same port, see
  [server.md](server.md#tls)), batches with a delay of up to a minute, and needs the endpoint on `/csp` or
  `/csp/<project>` for another origin. Prefer `report-uri` to debug: it reports at once from HTTP pages too.
- An HTTPS page can't report to `http://` on a host other than `127.0.0.1` or `localhost`: mixed content. For
  Trap in Docker or on another host use `https://`.
- Filter with `where`: `report_type` (`csp-violation`, `deprecation`, `intervention`…), `directive`, `blocked`,
  `disposition` (`enforce` blocked it, `report` only reported), `page`, `source_file`.
- Trap answers a report `204`; a body that is no report gets `400`, one over 1 MiB `413`.
