# phpspy traces

Recorded with phpspy 0.8.0 (commit `e1bfa1a`, <https://github.com/adsr/phpspy>) of PHP 8.5 running a script of
methods, closures in two files, an anonymous class, internal functions and a 120-deep recursion. The paths are
cut to `/app` and the pids set to `100`; nothing else is changed.

- `default.txt` — `phpspy -H 199`: the deep samples overflow the default 4096-byte buffer into chunks, and
  one sample is torn: phpspy read the stack while it changed.
- `chunked.txt` — `phpspy -H 199 -b 512`: most samples in chunks.
- `single.txt` — `phpspy -H 199 -1 -d tp`: single-line mode with the timestamp and pid of each sample; the chunk
  trailers sit on the lines of the frames.

`*.folded` are the same traces collapsed by phpspy's own `stackcollapse-phpspy.pl`, sorted: the reference
the parsed call trees are checked against. The single-line trace is turned into the multi-line format first,
which the script reads.
