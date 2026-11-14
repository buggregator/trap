# SPX reports recorded with ext-spx

Full reports (`<name>.json` and `<name>.txt.gz`) of the scripts in `../docker/scripts`, recorded with SPX 0.4.22
on PHP 8.3 (`php:8.3-cli`, Debian) by `../docker/record.sh`, and named by what they show rather than by their key:

| Report      | Script         | Settings besides `SPX_ENABLED=1 SPX_REPORT=full`                        |
|-------------|----------------|-------------------------------------------------------------------------|
| `default`   | `calls.php`    | none: the `wt` and `zm` metrics                                         |
| `metrics`   | `calls.php`    | `SPX_METRICS=wt,ct,zm,zgr,zif,io SPX_BUILTINS=1`: several roots         |
| `depth`     | `calls.php`    | `SPX_DEPTH=2`                                                           |
| `span`      | `span.php`     | `SPX_AUTO_START=0 SPX_BUILTINS=1`, `spx_profiler_start()` and `_stop()` |
| `shutdown`  | `shutdown.php` | `SPX_BUILTINS=1`: a shutdown function under `::php_request_shutdown`    |
| `sampling`  | `sampling.php` | `SPX_SAMPLING_PERIOD=500`                                               |
| `http`      | `http.php`     | a `POST` to `php -S`, SPX enabled by the cookies; raw custom metadata   |
| `truncated` | -              | the first half of the `default` trace, its metadata as is               |

What they tell about the format, beside `../../../src/Support/Spx/TraceReader.php`:

- the values are running totals: the wall and CPU time in nanoseconds, `zm` in bytes relative to the start;
- `wall_time_ms` is the wall time at the end in microseconds, despite the name;
- SPX writes a negative integer less 0.0001, as `-935.9999`, and escapes only `\b \f \n \r \t` in the metadata;
- `custom_metadata_str` is `null` when none is set;
- a span started from code opens the calls it starts in: the file of the script and `spx_profiler_start()`.

To record them again, from the repository root:

```sh
docker build -t trap-spx-recorder tests/Stub/spx/docker
docker run --rm \
    -v "$PWD/tests/Stub/spx/docker/scripts:/work:ro" \
    -v "$PWD/tests/Stub/spx/docker/record.sh:/record.sh:ro" \
    -v "$PWD/tests/Stub/spx/real:/out" \
    trap-spx-recorder sh /record.sh
```

The times, PIDs and host names differ on each recording: the tests take them from the metadata, and check the call
trees and the totals against it.
