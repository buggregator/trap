# Changelog

## [1.19.0](https://github.com/buggregator/trap/compare/1.18.0...1.19.0) (2026-11-14)


### Features

* add `--port` and `--host` options to `trap test` ([#277](https://github.com/buggregator/trap/issues/277)) ([7c0cacd](https://github.com/buggregator/trap/commit/7c0cacdd452f312e708e5a0f2e0b96c7966e0854))
* **frontend:** keep the call graph paths through the functions hidden by the threshold ([ff677d4](https://github.com/buggregator/trap/commit/ff677d45d5fd7c73961633f7c0d940a80b4a4121))
* **frontend:** sort Top functions by any metric, color the flame chart and the call graph ([ff677d4](https://github.com/buggregator/trap/commit/ff677d45d5fd7c73961633f7c0d940a80b4a4121))
* profile code with `trap()::profile()` and receive Excimer profiles ([bc88e7b](https://github.com/buggregator/trap/commit/bc88e7b9e450a2bcc46e2f67cb4eb5925167c645))
* **profiler:** add `Format::Spx`, the native SPX report as a tar ([6e10c6b](https://github.com/buggregator/trap/commit/6e10c6b4accffe4e4435339c188a515ec7970d57))
* **profiler:** build profiles on a call graph, keeping every caller of a function ([ff677d4](https://github.com/buggregator/trap/commit/ff677d45d5fd7c73961633f7c0d940a80b4a4121))
* **profiler:** let file observers skip files complete before Trap's start ([060e696](https://github.com/buggregator/trap/commit/060e69686d91a415b8a53af6c2115e8b385b2673))
* **profiler:** profile code with SPX via `trap()::profile(new Spx())` ([6e10c6b](https://github.com/buggregator/trap/commit/6e10c6b4accffe4e4435339c188a515ec7970d57))
* **profiler:** receive SPX full reports from files and over HTTP ([060e696](https://github.com/buggregator/trap/commit/060e69686d91a415b8a53af6c2115e8b385b2673))
* **profiler:** receive Xdebug profiles over HTTP and from `xdebug.output_dir` ([ff677d4](https://github.com/buggregator/trap/commit/ff677d45d5fd7c73961633f7c0d940a80b4a4121))
* **profiler:** send and receive pprof profiles ([d8d5db5](https://github.com/buggregator/trap/commit/d8d5db54a1f47e8f89bc22924f07f48acf78a73e))
* **profiler:** show top functions and hot path in the console ([#293](https://github.com/buggregator/trap/issues/293)) ([fd9ad49](https://github.com/buggregator/trap/commit/fd9ad4986dd123a3ce6068561a9c4bceeb7b8196))
* **ray:** receive Ray payloads as Ray events and support execution locks ([#278](https://github.com/buggregator/trap/issues/278)) ([95df9e0](https://github.com/buggregator/trap/commit/95df9e00cfb32daad0f24ffe44c4f46edb80ab1c))
* **server:** configure the gzip body limit with `TRAP_HTTP_MAX_INFLATED_SIZE` ([#298](https://github.com/buggregator/trap/issues/298)) ([edcd824](https://github.com/buggregator/trap/commit/edcd824f04abe15d19e03e67c53c7b91203eb6a2))
* update the UI to buggregator/frontend 1.23.2 ([#137](https://github.com/buggregator/trap/issues/137)) ([ff677d4](https://github.com/buggregator/trap/commit/ff677d45d5fd7c73961633f7c0d940a80b4a4121))


### Bug Fixes

* answer 413 to gzip request bodies inflating past 64 MB ([#290](https://github.com/buggregator/trap/issues/290)) ([29f91e5](https://github.com/buggregator/trap/commit/29f91e59b4276fc848847ee8eada78f9a3f17ef9))
* **command:** keep `trap test` going when a payload file cannot be opened ([#285](https://github.com/buggregator/trap/issues/285)) ([9dbc975](https://github.com/buggregator/trap/commit/9dbc975cc2cf2791bfed9f4e2bca3c122d9521aa))
* give each concurrent stream reader its own position ([#292](https://github.com/buggregator/trap/issues/292)) ([4a056e6](https://github.com/buggregator/trap/commit/4a056e6f8a8969436e43873c21b98f6cb477c880))
* **log:** hide socket warnings of `TrapLogger` from user error handlers and fall back to `error_log()` when STDERR is closed or unwritable ([3378140](https://github.com/buggregator/trap/commit/33781405b57f5e012db06cbdccd4acad0c6e865d))
* **profiler:** bound pprof builds and follow the pprof defaults ([f724ead](https://github.com/buggregator/trap/commit/f724ead8fefff8dffd60c0496d25ad3fd18f1d73))
* **profiler:** harden profile builds and the client after review ([499dcad](https://github.com/buggregator/trap/commit/499dcade4c5742603259d4d6b47d5b278abd4d10))
* **profiler:** keep warnings visible under a throwing error handler ([#296](https://github.com/buggregator/trap/issues/296)) ([3378140](https://github.com/buggregator/trap/commit/33781405b57f5e012db06cbdccd4acad0c6e865d))
* **profiler:** log delivery errors of every destination ([6185ef4](https://github.com/buggregator/trap/commit/6185ef490cc7d267f0a9470148191b0dadc65618))
* **profiler:** never throw from delivery and prefer formats a profiler can produce ([b2044ce](https://github.com/buggregator/trap/commit/b2044cee7d0042cc8627a353300aa7b250b46aa2))
* **profiler:** read a corrupted SPX trace with its own error under a throwing error handler ([#297](https://github.com/buggregator/trap/issues/297)) ([71342b9](https://github.com/buggregator/trap/commit/71342b9e9479ff24d16f855f3cefa3c160e392db))
* **profiler:** read, move aside, put back and delete SPX reports out of reach of user error handlers ([3378140](https://github.com/buggregator/trap/commit/33781405b57f5e012db06cbdccd4acad0c6e865d))
* **profiler:** set the HTTP delivery socket timeouts out of reach of user error handlers ([71342b9](https://github.com/buggregator/trap/commit/71342b9e9479ff24d16f855f3cefa3c160e392db))
* **router:** honor the name given in `#[QueryParam]` ([#284](https://github.com/buggregator/trap/issues/284)) ([a5f220f](https://github.com/buggregator/trap/commit/a5f220f8e269645e45abe2f991939122a726b640))
* **server:** use the timer duration passed to Buffer ([#301](https://github.com/buggregator/trap/issues/301)) ([9a61f33](https://github.com/buggregator/trap/commit/9a61f33f204c112daa504ef0e51cc4bcf305ee1c))


### Performance Improvements

* **profiler:** build call trees faster and in less memory ([c680706](https://github.com/buggregator/trap/commit/c680706a9dab6ae98f70c43b2b6b576315346c14))
* **server:** collect garbage cycles from the main loop ([3fd7660](https://github.com/buggregator/trap/commit/3fd7660be485a1ca2856eb092a061b24cf9c62d0))
* **server:** raise the default `TRAP_MAIN_LOOP_INTERVAL` from 100 to 1000 µs ([0ac3ca2](https://github.com/buggregator/trap/commit/0ac3ca2cf6d30ea7163aac309264bc8c32948b95))
* **server:** run the next loop iteration at once while fibers report progress, and wait on the sockets with `socket_select()` when idle ([0ac3ca2](https://github.com/buggregator/trap/commit/0ac3ca2cf6d30ea7163aac309264bc8c32948b95))
* **server:** stop oversleeping the main loop on Windows ([#295](https://github.com/buggregator/trap/issues/295)) ([0ac3ca2](https://github.com/buggregator/trap/commit/0ac3ca2cf6d30ea7163aac309264bc8c32948b95))


### Documentation

* describe `TRAP_MAIN_LOOP_INTERVAL` ([edcd824](https://github.com/buggregator/trap/commit/edcd824f04abe15d19e03e67c53c7b91203eb6a2))
* describe profiling in the readme ([dec485f](https://github.com/buggregator/trap/commit/dec485feba43b465e8eedbe8c39919421133b20f))
* describe SPX profiling ([a9b4208](https://github.com/buggregator/trap/commit/a9b42084dd6f99ed47dd76547eb167651e5b470b))
* move the documentation into docs/ and rewrite the README as a landing page ([7bacafa](https://github.com/buggregator/trap/commit/7bacafac24c1017812be043d3dda242fe65d104d))
* tell what a profile file write killed half way leaves behind ([a9b4208](https://github.com/buggregator/trap/commit/a9b42084dd6f99ed47dd76547eb167651e5b470b))


### Code Refactoring

* **client:** move TrapLogger into the client namespace ([#269](https://github.com/buggregator/trap/issues/269)) ([bab9a31](https://github.com/buggregator/trap/commit/bab9a31dd0853c39c43f37342b1dd7aa0444d90c))
* **config:** remove the unused `TRAP_XHPROF_SORT` option ([ff677d4](https://github.com/buggregator/trap/commit/ff677d45d5fd7c73961633f7c0d940a80b4a4121))
* **core:** feed frame types, renderers, UI and senders from the modules ([5d4be22](https://github.com/buggregator/trap/commit/5d4be226b3f4c3795ce369b752cf5d4b179c09d7))
* **core:** map UI events of frame subclasses, require the sender container ([aa3eaaf](https://github.com/buggregator/trap/commit/aa3eaaf27454430a320a9bd088912ce9462e6597))
* **core:** module contract for dispatchers, HTTP middlewares and file observers ([#274](https://github.com/buggregator/trap/issues/274)) ([340d264](https://github.com/buggregator/trap/commit/340d2647822c874f40afc3c5e34da18b0c66335c))
* **core:** reject modules taking a sender name, a UI mapper or event type already taken ([163f01b](https://github.com/buggregator/trap/commit/163f01b175a4f4e7db284e4715ddc5ec32c303c9))
* fix psalm 7.3 findings ([#283](https://github.com/buggregator/trap/issues/283)) ([88dcfe3](https://github.com/buggregator/trap/commit/88dcfe3fd953204882f6833db57c4d62db38669d))
* **frontend:** mark UI responses with the core no-dump header ([#271](https://github.com/buggregator/trap/issues/271)) ([768e116](https://github.com/buggregator/trap/commit/768e11677c9bdc360b068ef76d5d65a18b3f51f0))
* **frontend:** move the frontend sender into the Frontend module ([#270](https://github.com/buggregator/trap/issues/270)) ([271d06b](https://github.com/buggregator/trap/commit/271d06b46b3699cf63ab3228845c37c56bf0f3ed))
* **frontend:** skip binary frames the UI has no event type for ([f06785b](https://github.com/buggregator/trap/commit/f06785bd6cae166ab9d709f4ddc94f9f71d2f0aa))
* **frontend:** turn module UI endpoints into controller classes ([#275](https://github.com/buggregator/trap/issues/275)) ([416d153](https://github.com/buggregator/trap/commit/416d1538139aee4218ddf22d9d969d322ecc43b7))
* gather VarDumper and Monolog into modules ([#265](https://github.com/buggregator/trap/issues/265)) ([1ebd726](https://github.com/buggregator/trap/commit/1ebd726577db0244770bafc9788f5884ef52c2fe))
* inflate gzip request bodies with ext-zlib ([#289](https://github.com/buggregator/trap/issues/289)) ([7dac39a](https://github.com/buggregator/trap/commit/7dac39ab04c2094b0598243cad793fc307803757))
* **mailer:** gather the mailer into a module ([#264](https://github.com/buggregator/trap/issues/264)) ([45678c2](https://github.com/buggregator/trap/commit/45678c263e101a6047660a100c52723c7c8c6253))
* make channels modules with their own senders and HTTP endpoints ([6a76653](https://github.com/buggregator/trap/commit/6a766536fbbaa967819ead91c7189b5d63fc16e3))
* move the core into the `Server` namespace ([842d562](https://github.com/buggregator/trap/commit/842d562711b9d39d75e179feeced0b6563accd73))
* move the HTTP dump into a module that ends the HTTP pipeline ([68d5b72](https://github.com/buggregator/trap/commit/68d5b7209842d09271ae671583fad9a955845b1f))
* **profiler:** gather the profiler into a module ([#263](https://github.com/buggregator/trap/issues/263)) ([8c94937](https://github.com/buggregator/trap/commit/8c9493783f955e55d20bd36920bb845a12ceb205))
* **proto:** replace the ProtoType enum with frame type constants ([#273](https://github.com/buggregator/trap/issues/273)) ([f110784](https://github.com/buggregator/trap/commit/f1107843372b7c5a63db5db879c14cb397e10d8b))
* **ray:** gather Ray support into a module ([#268](https://github.com/buggregator/trap/issues/268)) ([d038d23](https://github.com/buggregator/trap/commit/d038d230b2e308d871a40b75e985f2978eeaedc1))
* **sentry:** gather Sentry into a module ([#262](https://github.com/buggregator/trap/issues/262)) ([a88efc4](https://github.com/buggregator/trap/commit/a88efc43caf40a8132a032193d8c37fc92ae2101))
* **server:** remove `TRAP_TCP_POLLING_INTERVAL` and the polling period of the socket servers and clients from the configuration ([0ac3ca2](https://github.com/buggregator/trap/commit/0ac3ca2cf6d30ea7163aac309264bc8c32948b95))
* **tracing:** gather OpenTelemetry tracing into a module ([#267](https://github.com/buggregator/trap/issues/267)) ([f2ce5ab](https://github.com/buggregator/trap/commit/f2ce5abb38906c8bbe0fd5866cd6e21fc5c88ad2))

## [1.18.0](https://github.com/buggregator/trap/compare/1.17.1...1.18.0) (2026-10-15)


### Features

* require PHP 8.2, migrate tests to Testo ([#229](https://github.com/buggregator/trap/issues/229)) ([6c26ae5](https://github.com/buggregator/trap/commit/6c26ae5e9ca814d695668470302f7ad5539b8196))


### Bug Fixes

* decode base64 bodies correctly when a chunk splits a 4-character group ([#258](https://github.com/buggregator/trap/issues/258)) ([5c4d84c](https://github.com/buggregator/trap/commit/5c4d84c705af1409f0a3e0149eb3ef035b97eacc))
* dump a Monolog record sent after a pause as Monolog, not binary ([#255](https://github.com/buggregator/trap/issues/255)) ([e717fae](https://github.com/buggregator/trap/commit/e717faee72938baadcbd062cce2c097d716ed24c))
* keep every body of one batch in its own file in BodyToFileSender ([#254](https://github.com/buggregator/trap/issues/254)) ([11f7cec](https://github.com/buggregator/trap/commit/11f7cec5a72296d716a47a1e970f580d55fbbfdd))
* keep parsing Sentry envelope items after an item with explicit length ([#246](https://github.com/buggregator/trap/issues/246)) ([ed88322](https://github.com/buggregator/trap/commit/ed88322a7cefcba92da34d4d9102c09bf4e90f48))
* render Sentry attachment items in the console instead of a render error ([#248](https://github.com/buggregator/trap/issues/248)) ([f4c804f](https://github.com/buggregator/trap/commit/f4c804fa1a3b832d112941f82374e4030bd14186))
* report trap run startup errors and exit with a non-zero code ([#256](https://github.com/buggregator/trap/issues/256)) ([f5f7615](https://github.com/buggregator/trap/commit/f5f761551229e52c53346b5b8733213918479270))
* restore uploaded files of an Http frame from its string form ([#259](https://github.com/buggregator/trap/issues/259)) ([a0f8b40](https://github.com/buggregator/trap/commit/a0f8b40c30190509cc28e500bc2bea0691f2b10c))
* set the parent of a profiler tree node added before its parent ([#257](https://github.com/buggregator/trap/issues/257)) ([eca830e](https://github.com/buggregator/trap/commit/eca830e2b839a9f5fbc91366c26468163ac82102))
* show the right minutes in tr() deltas over a minute ([#250](https://github.com/buggregator/trap/issues/250)) ([8b1eb6d](https://github.com/buggregator/trap/commit/8b1eb6d494f23312461157b4898abc9175600b7d))
* stop the undefined key warning on Sentry events without sdk ([#249](https://github.com/buggregator/trap/issues/249)) ([3208adf](https://github.com/buggregator/trap/commit/3208adfa1e4e730e333f1028cc78dc186bcdc283))
* store Sentry attachment payload as a stream instead of its byte count ([#247](https://github.com/buggregator/trap/issues/247)) ([6f53cda](https://github.com/buggregator/trap/commit/6f53cdaa56040be68c11a7c47bdf48a01842ef8e))
* throw JsonException instead of TypeError on an invalid Monolog frame ([#251](https://github.com/buggregator/trap/issues/251)) ([b66a282](https://github.com/buggregator/trap/commit/b66a282f27a5ac5c27e89d9a4deeb4986851f6e2))
* throw the intended RuntimeException when BodyToFileSender cannot create the file ([#252](https://github.com/buggregator/trap/issues/252)) ([16d725e](https://github.com/buggregator/trap/commit/16d725e52245708cecabb3c6735af3859cfa2712))


### Documentation

* point the sponsor badge and link to Boosty ([a4734d1](https://github.com/buggregator/trap/commit/a4734d11b8ea4067a60affebb8bf35fc9e2d4f23))

## [1.17.1](https://github.com/buggregator/trap/compare/1.17.0...1.17.1) (2026-10-14)


### Bug Fixes

* stop the zlib warning on broken gzip OTLP bodies ([#235](https://github.com/buggregator/trap/issues/235))

## [1.17.0](https://github.com/buggregator/trap/compare/1.16.1...1.17.0) (2026-10-14)


### Features

* receive OpenTelemetry traces over OTLP/HTTP ([#221](https://github.com/buggregator/trap/issues/221))


### Bug Fixes

* make `trap run` start again and respect the Composer bin proxy autoloader ([#220](https://github.com/buggregator/trap/issues/220))


### Performance Improvements

* index OTLP traces in the span store for console rendering ([#227](https://github.com/buggregator/trap/issues/227))

## [1.16.1](https://github.com/buggregator/trap/compare/1.16.0...1.16.1) (2026-09-08)


### Code Refactoring

* replace the in-tree DI container with internal/container ([#218](https://github.com/buggregator/trap/issues/218))

## [1.16.0](https://github.com/buggregator/trap/compare/1.15.2...1.16.0) (2026-06-16)


### Features

* add PSR-3 Trap logger with STDERR fallback ([#212](https://github.com/buggregator/trap/issues/212))

## [1.15.2](https://github.com/buggregator/trap/compare/1.15.1...1.15.2) (2026-04-07)


### Bug Fixes

* **socket:** handle partial writes for large WebSocket payloads ([#213](https://github.com/buggregator/trap/issues/213))


### Documentation

* add Awesome PHP badge to README ([#192](https://github.com/buggregator/trap/issues/192))

## [1.15.1](https://github.com/buggregator/trap/compare/1.15.0...1.15.1) (2025-12-27)


### Bug Fixes

* fix Symfony 8 compatibility ([#210](https://github.com/buggregator/trap/issues/210))

## [1.15.0](https://github.com/buggregator/trap/compare/1.14.0...1.15.0) (2025-11-30)


### Dependencies

* update dependencies ([#208](https://github.com/buggregator/trap/issues/208))

## [1.14.0](https://github.com/buggregator/trap/compare/1.13.18...1.14.0) (2025-11-20)


### Features

* use empty senders list ([#200](https://github.com/buggregator/trap/issues/200))


### Bug Fixes

* fix WS frame packing for large packets ([#202](https://github.com/buggregator/trap/issues/202))

## [1.13.18](https://github.com/buggregator/trap/compare/1.13.17...1.13.18) (2025-09-08)


### Dependencies

* use `internal/destroy` ([#194](https://github.com/buggregator/trap/issues/194))
* remove termwind ([#196](https://github.com/buggregator/trap/issues/196))

## [1.13.17](https://github.com/buggregator/trap/compare/1.13.16...1.13.17) (2025-09-05)


### Bug Fixes

* use `getenv()` for getting environment variable values ([#193](https://github.com/buggregator/trap/issues/193))


### Documentation

* add CONTRIBUTING.md ([#189](https://github.com/buggregator/trap/issues/189))

## [1.13.16](https://github.com/buggregator/trap/compare/1.13.15...1.13.16) (2025-06-09)


### Features

* add casters for new public protobuf RepeatedField DTO ([#187](https://github.com/buggregator/trap/issues/187))

## [1.13.15](https://github.com/buggregator/trap/compare/1.13.14...1.13.15) (2025-06-08)

## [1.13.14](https://github.com/buggregator/trap/compare/1.13.13...1.13.14) (2025-06-05)


### Bug Fixes

* fix Sentry envelope detection ([#183](https://github.com/buggregator/trap/issues/183))

## [1.13.13](https://github.com/buggregator/trap/compare/1.13.12...1.13.13) (2025-04-16)


### Features

* build binaries for macOS ([#180](https://github.com/buggregator/trap/issues/180))

## [1.13.12](https://github.com/buggregator/trap/compare/1.13.11...1.13.12) (2025-04-03)

## [1.13.11](https://github.com/buggregator/trap/compare/1.13.10...1.13.11) (2025-04-03)

## [1.13.10](https://github.com/buggregator/trap/compare/1.13.9...1.13.10) (2025-04-03)

## [1.13.9](https://github.com/buggregator/trap/compare/1.13.8...1.13.9) (2025-04-03)

## [1.13.8](https://github.com/buggregator/trap/compare/1.13.7...1.13.8) (2025-04-03)

## [1.13.7](https://github.com/buggregator/trap/compare/1.13.6...1.13.7) (2025-04-03)

## [1.13.6](https://github.com/buggregator/trap/compare/1.13.5...1.13.6) (2025-04-03)


### Miscellaneous

* maintenance ([#170](https://github.com/buggregator/trap/issues/170))

## [1.13.5](https://github.com/buggregator/trap/compare/1.13.4...1.13.5) (2025-03-22)


### Bug Fixes

* fix PHAR building ([#167](https://github.com/buggregator/trap/issues/167))

## [1.13.4](https://github.com/buggregator/trap/compare/1.13.3...1.13.4) (2025-03-06)


### Bug Fixes

* use `$_ENV` to get `VAR_DUMPER` envs ([#163](https://github.com/buggregator/trap/issues/163))

## [1.13.3](https://github.com/buggregator/trap/compare/1.13.2...1.13.3) (2025-01-23)


### Bug Fixes

* **smtp:** improve parsing of multiline headers ([#160](https://github.com/buggregator/trap/issues/160))

## [1.13.2](https://github.com/buggregator/trap/compare/1.13.1...1.13.2) (2025-01-23)


### Performance Improvements

* make senders lazy ([#158](https://github.com/buggregator/trap/issues/158))

## [1.13.1](https://github.com/buggregator/trap/compare/1.13.0...1.13.1) (2025-01-19)


### Bug Fixes

* allow `_` in email names in MailToFileSender ([#155](https://github.com/buggregator/trap/issues/155))

## [1.13.0](https://github.com/buggregator/trap/compare/1.12.0...1.13.0) (2025-01-19)


### Features

* add MailToFile sender ([#151](https://github.com/buggregator/trap/issues/151))
* support multiple emails in one SMTP connection ([#153](https://github.com/buggregator/trap/issues/153))

## [1.12.0](https://github.com/buggregator/trap/compare/1.11.1...1.12.0) (2025-01-15)


### Features

* expose `trap --version` command ([#147](https://github.com/buggregator/trap/issues/147))
* support custom listening hosts ([#148](https://github.com/buggregator/trap/issues/148))

## [1.11.1](https://github.com/buggregator/trap/compare/1.11.0...1.11.1) (2024-12-07)


### Bug Fixes

* resolve PHP 8.4 deprecations ([#144](https://github.com/buggregator/trap/issues/144))

## [1.11.0](https://github.com/buggregator/trap/compare/1.10.2...1.11.0) (2024-09-25)


### Features

* add config for main loop interval and socket polling interval ([#141](https://github.com/buggregator/trap/issues/141))

## [1.10.2](https://github.com/buggregator/trap/compare/1.10.1...1.10.2) (2024-09-24)


### Features

* separate polling intervals for sockets ([#140](https://github.com/buggregator/trap/issues/140))


### Code Refactoring

* fix Psalm issues ([#131](https://github.com/buggregator/trap/issues/131))

## [1.10.1](https://github.com/buggregator/trap/compare/1.10.0...1.10.1) (2024-06-23)


### Bug Fixes

* better HTTP/SMTP multipart parsing ([#128](https://github.com/buggregator/trap/issues/128))

## [1.10.0](https://github.com/buggregator/trap/compare/1.9.0...1.10.0) (2024-06-20)


### Features

* frontend v1.18, XHProf web endpoint and `file-body` sender ([#117](https://github.com/buggregator/trap/issues/117))
* process HTTP events and UI on the same port ([#123](https://github.com/buggregator/trap/issues/123))


### Bug Fixes

* fix docs and ports option ([#124](https://github.com/buggregator/trap/issues/124))


### Documentation

* change the Discord badge to static ([#118](https://github.com/buggregator/trap/issues/118))

## [1.9.0](https://github.com/buggregator/trap/compare/1.8.0...1.9.0) (2024-06-04)


### Features

* add searching for XHProf local files ([#111](https://github.com/buggregator/trap/issues/111))

## [1.8.0](https://github.com/buggregator/trap/compare/v1.7.5...v1.8.0) (2024-05-29)


### Features

* add `tr()` and `td()` functions ([#102](https://github.com/buggregator/trap/issues/102))
* add `trap()->code()` sugar ([#103](https://github.com/buggregator/trap/issues/103))

## [1.7.5](https://github.com/buggregator/trap/compare/v1.7.4...v1.7.5) (2024-05-24)


### Bug Fixes

* fix environments in build-phar-release ([453e522](https://github.com/buggregator/trap/commit/453e522b6c49c6e9d54cd6256137c8f25925939f))

## [1.7.4](https://github.com/buggregator/trap/compare/v1.7.3...v1.7.4) (2024-05-24)


### Bug Fixes

* release asset upload ([#99](https://github.com/buggregator/trap/issues/99)) ([b4616c5](https://github.com/buggregator/trap/commit/b4616c52056cd1803b2d3990178577537a694147))
* suppress warnings if Closure::bind() is run with static callable ([3d72a7e](https://github.com/buggregator/trap/commit/3d72a7ef551bd2f21b0935826e8093a58da0b774))

## [1.7.3](https://github.com/buggregator/trap/compare/v1.7.2...v1.7.3) (2024-05-24)


### Bug Fixes

* remove unnecessary escape symbols from action file for phar building ([b6c628b](https://github.com/buggregator/trap/commit/b6c628b62f7a831a9ccca7c3b62a5834f2aa5453))

## [1.7.2](https://github.com/buggregator/trap/compare/v1.7.1...v1.7.2) (2024-05-24)


### Bug Fixes

* fixed path to keys.asc.gpg to sign phar ([7c80552](https://github.com/buggregator/trap/commit/7c80552635d0703e2cbd15bde3a76eedc5adcb08))

## [1.7.1](https://github.com/buggregator/trap/compare/v1.7.0...v1.7.1) (2024-05-24)


### Bug Fixes

* update release-token ([#94](https://github.com/buggregator/trap/issues/94)) ([51f187b](https://github.com/buggregator/trap/commit/51f187b743941093a33d86daba2ba5c815dd62de))

## [1.7.0](https://github.com/buggregator/trap/compare/1.6.0...v1.7.0) (2024-05-24)


### Features

* add box-project/box, composer-require-checker and composer-normalize PHARs ([d86a1e0](https://github.com/buggregator/trap/commit/d86a1e04d5512f32adfa643d0ac43f3c888aa64a))
* add docker compose support ([d86a1e0](https://github.com/buggregator/trap/commit/d86a1e04d5512f32adfa643d0ac43f3c888aa64a))
* add initial Makefile ([3d1738f](https://github.com/buggregator/trap/commit/3d1738fff0e0784a67930340bee7d493ffeacba5))
* add support for pest-php ([340c5eb](https://github.com/buggregator/trap/commit/340c5eb941bc8b805db1cb39cb2bde7259a42911))
* Makefile now builds trap PHAR locally using `make phar` command ([d86a1e0](https://github.com/buggregator/trap/commit/d86a1e04d5512f32adfa643d0ac43f3c888aa64a))
* use latest version from auto-incrementing file ([f7b9210](https://github.com/buggregator/trap/commit/f7b9210ad152347963956d3bfcd56e2206a44a67))


### Bug Fixes

* add cache into the `Info::version()` method; move version.json into `resources` dir ([eb129ba](https://github.com/buggregator/trap/commit/eb129ba460c95755302890ae910f4649fb27d11b))
* cache version variable ([46b2460](https://github.com/buggregator/trap/commit/46b2460edef7f6239df6d6b2e00b49e82b1d5cdd))
* return experimental, if version is not found ([a39cdd9](https://github.com/buggregator/trap/commit/a39cdd90f401e1c20b9e831bb01f5721edf4a921))
* set `console` renderer by default again. ([b6ad654](https://github.com/buggregator/trap/commit/b6ad65463b55afd0670b46b96b95197f2061e573))
* use .build directory for all development tools ([1f49bb8](https://github.com/buggregator/trap/commit/1f49bb8e0249a01d7080ea6bedeea6b6e6ae80b2))


### Documentation

* fix link to logo in readme; add `support` shield ([#83](https://github.com/buggregator/trap/issues/83)) ([23ebc91](https://github.com/buggregator/trap/commit/23ebc91337ec5a943e7f2245de3ad944abfe4e67))
* replace DIV with P around IMG ([4169ae2](https://github.com/buggregator/trap/commit/4169ae28a11a0e5fd38219612bf79cdaa021d44f))
* replace DIV with P tag to be more readable in packagist page ([9594a1d](https://github.com/buggregator/trap/commit/9594a1dadc6e5d0bdf02ad6f8c5f9ec24be738de))


### Code Refactoring

* github workflows ([a108476](https://github.com/buggregator/trap/commit/a108476c674fac90f08e0576fb480946cc211293))
