# CSP reports

Trap receives the security reports browsers send: Content Security Policy violations and the other reports of the
Reporting API, such as `deprecation`, `intervention`, `crash` and `network-error`. Point a policy of your
application at Trap, open its pages, and every resource the policy would block shows up in the console, on the
CSP reports page of the [web UI](web-ui.md) and in the [MCP tools](mcp.md#csp-reports).

Your application only sends a header: there is no client to install. Use `Content-Security-Policy-Report-Only` while
you debug a policy: the browser reports what the policy would block and blocks nothing.

## Receive reports

Send the reports to `/csp` or `/csp/{project}` on an HTTP port of Trap, `8000` by default:

```text
http://127.0.0.1:8000/csp/my-app
```

Trap takes a report from a `POST`:

- with the `application/csp-report` content type, of the `report-uri` directive, to any path;
- with the `application/reports+json` content type, of the `report-to` directive, to any path;
- to `/csp` or `/csp/{project}` with any content type: a JSON list is read as `reports+json`, an object as
  `csp-report`;
- marked `csp` with the `X-Buggregator-Event` header or the user of Basic authorization, to any path with any
  content type.

So an existing report URL of your application works too when its host and port point at Trap. A request marked for
another kind of event, with the `X-Buggregator-Event` header or the user of Basic authorization, is left to it.

The project of the reports is the `{project}` of the path, else the password of the `csp` user of Basic
authorization (`http://csp:my-app@127.0.0.1:8000/`), else the `X-Buggregator-Project` header, else `default`.
A browser sends no credentials from the userinfo of a report URL and adds no headers of yours, so for browsers the
project works only as `/csp/{project}`. Basic authorization and the header are for a proxy or another client that
forwards the reports.

Trap answers a report with `204` and an empty body, a body that is not a report with `400`, and a body larger than
1 MiB with `413`. Any `OPTIONS` request to `/csp` or `/csp/...` gets a CORS preflight answer for any origin:
Chrome sends Reporting API reports to another origin only after one.

## Set up the policy

The samples send the policy only in development: take it out of the application when you are done.

### `report-uri`

`report-uri` sends a `csp-report` right away, one request per violation, from HTTP and HTTPS pages alike.

```php
if (getenv('APP_ENV') === 'dev') {
    header("Content-Security-Policy-Report-Only: default-src 'self'; report-uri http://127.0.0.1:8000/csp/my-app");
}
```

### `report-to` and `Reporting-Endpoints`

`report-to` names an endpoint of the `Reporting-Endpoints` header and sends `reports+json`. The endpoint named
`default` also gets the other reports of the page: deprecations, interventions, crashes.

```php
if (getenv('APP_ENV') === 'dev') {
    header('Reporting-Endpoints: trap="https://127.0.0.1:8000/csp/my-app", default="https://127.0.0.1:8000/csp/my-app"');
    header("Content-Security-Policy-Report-Only: default-src 'self'; report-to trap; report-uri https://127.0.0.1:8000/csp/my-app");
}
```

- Chrome sends Reporting API reports only from an HTTPS page to an HTTPS endpoint, so the endpoint is `https://`:
  Trap takes TLS on the same port, and the browser must trust its certificate, see [TLS](tls.md#certificate).
- A browser that knows `report-to` ignores `report-uri` in the same policy; keep both for the other browsers.
- The browser batches the reports and sends them with a delay of up to a minute. Keep the page open meanwhile.
- Send the reports of another origin to `/csp` or `/csp/{project}`: only these paths answer the preflight.

`network-error` reports come from Network Error Logging, which a `NEL` header turns on. It names a group of the
older `Report-To` header: Chrome takes the endpoints of Network Error Logging from it, not from `Reporting-Endpoints`.

```php
header('Report-To: {"group": "trap", "max_age": 3600, "endpoints": [{"url": "https://127.0.0.1:8000/csp/my-app"}]}');
header('NEL: {"report_to": "trap", "max_age": 3600, "failure_fraction": 1.0}');
```

The console and the MCP text show the failure of a `network-error` report (its `type`, such as `tcp.refused`), the
phase, the HTTP status, the elapsed time and the server IP, and the reason of a `crash` report.

### Laravel

[spatie/laravel-csp](https://github.com/spatie/laravel-csp) adds `report-uri` from `report_uri` of
`config/csp.php`, which reads `CSP_REPORT_URI`. Set it in the `.env` of development:

```dotenv
CSP_REPORT_URI=http://127.0.0.1:8000/csp/my-app
```

Put the policy you debug into the report-only one: `report_only_presets` in version 3 of the package,
`report_only_policy` in version 2.

### Symfony

[NelmioSecurityBundle](https://github.com/nelmio/NelmioSecurityBundle) sends the `report` policy as
`Content-Security-Policy-Report-Only`:

```yaml
# config/packages/nelmio_security.yaml
when@dev:
    nelmio_security:
        csp:
            report:
                default-src: ['self']
                report-uri: ['http://127.0.0.1:8000/csp/my-app']
```

## HTTPS pages

An HTTPS page sends no report to an `http://` URL of a host other than `127.0.0.1` or `localhost`: the browser
blocks it as mixed content. When Trap runs on another host, for example in Docker, report to `https://` on its
port, with a certificate the browser trusts: see [TLS](tls.md#certificate).

## What Trap shows

The console prints a block per report: what was blocked or reported, the report type, the page, the directive, what
was blocked, the disposition (`enforce`: blocked; `report`: Report-Only, not blocked), the source file with its line
and column, the code sample, the message of a report of another type, the policy and the user agent.

One request is one event of the `csp` type: a `report-uri` request carries one report, a `report-to` batch may
carry several. The web UI lists the events on its CSP reports page; the [MCP tools](mcp.md#csp-reports) list,
filter and read them.

`vendor/bin/trap test` sends a report of each format.
