# Changelog

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
