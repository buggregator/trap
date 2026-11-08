# XHProf profiles

`app` is a profile of a real application (taken without builtins), anonymized: every identifier is replaced
with a stable alias (`Id1`, `id2`, ...), the same alias everywhere in the profile. Kept as is: `main()`, `run_init::`,
`load::`, closures, anonymous classes, magic methods, separators, line numbers, recursion levels (`@N`) and metrics.

The same calls in the two forms trap receives:

- `app.xhprof` — `serialize()`d raw data, as `XHProfRuns_Default` saves it into `xhprof.output_dir`.
- `app.json` — payload of `POST /api/profiler/store`: the raw data in `profile`, plus `tags`, `app_name`, `hostname`, `date`.

Facts the tests rely on: 2223 edges, 1035 function IDs, 582 functions, root wall time 45310 µs,
the unfolded call tree is 471 levels deep.
