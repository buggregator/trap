# reli-prof traces

Recorded with reli-prof 0.12.0 (the `reliforp/reli-prof` Docker image, <https://github.com/reliforp/reli-prof>)
of PHP 8.3 running the script of `tests/Stub/phpspy`, mounted at `/app`:
`reli inspector:trace -p <pid> -o <file>` with the options below. Each file keeps the first 40 samples of its
recording, cut at the blank line that ends a sample; nothing else is changed.

- `phpspy.txt` — the default `phpspy` template.
- `opcode.txt` — `--output-format=template:phpspy_with_opcode`: the opcode after the line of a user frame.
- `tracevar.txt` — `--trace-var='global::$i'`: a `# global::$i = (int) <value>` record after the frames.
- `depth.txt` — `--depth=5`: the frames nearest the leaf only, so the deep samples have no `<main>`.

reli names a closure of PHP 8.3 by its file and lines: `{closure}(/app/routes.php:3-3)`.

`*.folded` are the same traces collapsed by phpspy's own `stackcollapse-phpspy.pl`, sorted: the reference
the parsed call trees are checked against.
