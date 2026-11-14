# Installation

Trap needs PHP 8.2 or newer (64-bit) with `ext-sockets`.
Pick one of three ways to install it.

## Composer

Add the package as a dev dependency of your project:

```bash
composer require --dev buggregator/trap -W
```

[![PHP](https://img.shields.io/packagist/php-v/buggregator/trap.svg?style=flat-square&logo=php)](https://packagist.org/packages/buggregator/trap)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/buggregator/trap.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/buggregator/trap)
[![License](https://img.shields.io/packagist/l/buggregator/trap.svg?style=flat-square)](../LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/buggregator/trap.svg?style=flat-square)](https://packagist.org/packages/buggregator/trap/stats)

This gives you the server (`vendor/bin/trap`) and the [functions](functions.md) `trap()`, `tr()` and `td()`.
The [Symfony VarDumper extensions](var-dumper.md#protobuf-messages) work right after the install.

## Binary

The binary is a self-contained executable: it needs no PHP on the machine.
Install it globally with [Dload](https://github.com/php-internal/dload):

```bash
dload get trap
```

Or download an archive from the [releases page](https://github.com/buggregator/trap/releases).
Archives are built for Linux (amd64, arm64), macOS (amd64, arm64) and Windows (amd64),
and named `trap-<version>-<os>-<arch>.tar.gz`.

```bash
tar -xzf trap-<version>-linux-amd64.tar.gz
chmod +x trap
./trap
```

## Phar

Your project may conflict with Trap's dependencies, or you may want only the local server (e.g. to analyze local
profiler files). Then install Trap as a Phar, a self-contained PHP executable.

With wget:

```bash
wget https://github.com/buggregator/trap/releases/latest/download/trap.phar
chmod +x trap.phar
./trap.phar --version
```

With [Phive](https://phar.io/):

```bash
phive install buggregator/trap
```

## Optional extensions and packages

| Package or extension                                     | Needed for                                                                                       |
|----------------------------------------------------------|--------------------------------------------------------------------------------------------------|
| `ext-zlib`                                               | gzip-compressed HTTP request bodies and profiles, [SPX](spx.md) traces                           |
| `ext-simplexml`                                          | loading [`trap.xml`](configuration.md#trapxml)                                                   |
| `ext-excimer`, `ext-xhprof`, `ext-tideways_xhprof`       | [profiling code](profiling.md) with `trap()::profile()`                                          |
| `ext-spx`                                                | [profiling code with SPX](spx.md)                                                                |
| `open-telemetry/gen-otlp-protobuf` and `google/protobuf` | [OpenTelemetry traces](opentelemetry.md) over `http/protobuf`; the Phar and the binary lack them |

## Next steps

- Start the server: [`run`](cli.md#run) and all its options.
- Send the first dump: [`trap()`](functions.md).
