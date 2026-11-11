# Speedscope profiles

`excimer-*.json` are shaped as `ExcimerLog::getSpeedscopeData()` writes them (Excimer 1.2): frames are `{name, file}`
deduplicated by both, `unit` is `nanoseconds`, and a weight is the number of timer events times the period.
They are made by hand after Excimer's `excimer_log.c` and its tests: `excimer-cpu.json` models `tests/cpu.phpt`
and `excimer-max-depth.json` models `tests/maxDepth.phpt` (https://github.com/wikimedia/mediawiki-php-excimer).

- `excimer-cpu.json` — closures named by file and line, a method, a file-scope root; 0.1 s period.
- `excimer-max-depth.json` — the whole stack is over the max depth: the outer frames are one `excimer_truncated` frame.
- `excimer-truncated-mixed.json` — max depth 4: two full stacks, two truncated ones (one of 2 events), so two roots.
- `excimer-overrun.json` — samples of 1, 250, 1 and 3 timer events: a long internal call delays the sample.
- `excimer-recursion.json` — direct (`fib`) and mutual (`isEven`, `isOdd`) recursion.
- `excimer-real-world-names.json` — eval'd code, a closure in it, anonymous classes, unicode, a 280-character name,
  an included file.
- `excimer-shutdown-roots.json` — samples of a shutdown function and a destructor: three roots.
- `excimer-anonymous-class-root.json` — a single root that is a method of an anonymous class.
- `excimer-command-line-root.json` — `php -r`: Excimer names the code `Command_line_code`.
- `excimer-empty-stack.json` — a sample with no user frames on the stack, of 2 timer events.

Other exporters' shapes, made by hand:

- `evented-nested-unclosed.json` — evented, recursion, a frame closed and another opened at the same time,
  a frame left open.
- `sampled-bytes.json` — weights in `bytes`.
- `sampled-zero-weight.json` — samples of zero weight.
- `same-name-different-files.json` — two `render` frames of different files.

`upstream/simple.speedscope.json` is a sample of speedscope (https://github.com/jlfwong/speedscope,
`sample/profiles/speedscope/0.0.1/simple.speedscope.json`), MIT license: see `upstream/LICENSE-speedscope-MIT`.
