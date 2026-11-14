# SPX reports

An SPX full report (`SPX_REPORT=full`) is a pair of files in `spx.data_dir`, named by the report key:
`<key>.json` with the metadata and `<key>.txt.gz` with the trace.

## handwritten

Written by hand in the format of SPX 0.4.22, ungzipped, with the `wt`, `ct`, `zm`, `zgr` and `io` metrics:
an HTTP request to `/app/index.php` that calls `App\Kernel::handle()` twice; the first call runs `App\fib()`
recursively and then `strlen()`.

## real

Reports recorded with ext-spx, and the Docker image and scripts to record them again: see [real/README.md](real/README.md).
