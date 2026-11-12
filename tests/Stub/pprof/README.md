# pprof profiles

Profiles written by Go's `runtime/pprof`, gzip-compressed protobuf, from the test data of Parca
(<https://github.com/parca-dev/parca>, `pkg/query/testdata/`), Apache License 2.0:
see `upstream/LICENSE-parca-Apache-2.0`.

- `upstream/fib.pb.gz` — a CPU profile (`samples/count`, `cpu/nanoseconds`, 10 ms period) of a Fibonacci
  program: 2974 samples, stacks under several roots.
- `upstream/alloc_objects.pb.gz` — a heap profile of the Prometheus Operator: `alloc_objects`, `alloc_space`,
  `inuse_objects` and `inuse_space`, the default sample type `alloc_space`.

The other profiles of the tests are made in code by `tests/Unit/Module/Profiler/Pprof/Stub/PprofProfile.php`.
