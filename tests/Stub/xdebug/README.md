# XDebug profiles

Generated with Xdebug 3.4.0 on PHP 8.4.23; script paths are replaced with `/app/`.

- `*.cachegrind` — profiler output (`xdebug.mode=profile`). Xdebug writes a block per call, in the order the calls end,
  so the exact call tree can be restored from it. Recursion levels are not marked: `bar()` calling itself is `bar ==> bar`.
- `*.trace.xt` — computerized trace (`xdebug.mode=trace`, `xdebug.trace_format=1`): entry and exit records of every call.

```sh
php -d xdebug.mode=profile -d xdebug.start_with_request=yes -d xdebug.use_compression=0 \
    -d xdebug.output_dir=out -d xdebug.profiler_output_name=NAME.cachegrind NAME.php
php -d xdebug.mode=trace -d xdebug.start_with_request=yes -d xdebug.use_compression=0 -d xdebug.trace_format=1 \
    -d xdebug.output_dir=out -d xdebug.trace_output_name=NAME.trace NAME.php
```

## recursion.php

The same code as the XHProf stub in `ProfileBuilderTest`. `strlen()` of a literal is compiled into an opcode,
so it is not a call and is absent from the profile.

```php
<?php
function bar($x) { if ($x > 0) { bar($x - 1); } }
function foo() { for ($idx = 0; $idx < 2; $idx++) { bar($idx); $x = strlen("abc"); } }
foo();
```

## shared.php

`x()` is called from both `a()` and `b()`.

```php
<?php
function y() { return str_repeat('y', 1000); }
function x() { return y(); }
function a() { return x(); }
function b() { x(); x(); return x(); }
a();
b();
```
