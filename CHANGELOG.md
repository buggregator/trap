# Changelog

## 1.11.0 (2024-09-25)

## What's Changed
* Add config for main loop interval and socket polling interval by @roxblnfk in https://github.com/buggregator/trap/pull/141


**Full Changelog**: https://github.com/buggregator/trap/compare/1.10.2...1.11.0

## 1.10.2 (2024-09-24)

## What's Changed
* Fix Psalm issues by @Kaspiman in https://github.com/buggregator/trap/pull/131
* Add Rector to CI by @Kaspiman in https://github.com/buggregator/trap/pull/133
* Separate polling intervals for sockets by @roxblnfk in https://github.com/buggregator/trap/pull/140

## New Contributors
* @Kaspiman made their first contribution in https://github.com/buggregator/trap/pull/131

**Full Changelog**: https://github.com/buggregator/trap/compare/1.10.1...1.10.2

## 1.10.1 (2024-06-23)

## What's Changed
* Better HTTP/SMTP Multipart parsing by @roxblnfk in https://github.com/buggregator/trap/pull/128


**Full Changelog**: https://github.com/buggregator/trap/compare/1.10.0...1.10.1

## 1.10.0 (2024-06-20)

## What's Changed
* Change the discord badge to static by @roxblnfk in https://github.com/buggregator/trap/pull/118
* Complex update: frontend v1.18; XHProf web endpoint; "file-body" sender by @roxblnfk in https://github.com/buggregator/trap/pull/117
* Add ability to process HTTP events and UI on the same port by @roxblnfk in https://github.com/buggregator/trap/pull/123
* Fix docs and ports option by @roxblnfk in https://github.com/buggregator/trap/pull/124


**Full Changelog**: https://github.com/buggregator/trap/compare/1.9.0...1.10.0

## 1.9.0 (2024-06-04)

## What's Changed
* Make release tags without prefix `v` by @lotyp in https://github.com/buggregator/trap/pull/107
* Add searching for XHProf local files by @roxblnfk in https://github.com/buggregator/trap/pull/111
* CI: separate psalm and phpstan workflows by @roxblnfk in https://github.com/buggregator/trap/pull/113
* CI: separate pest config and isolate pest from unit tests by @roxblnfk in https://github.com/buggregator/trap/pull/114
* ci: add phpstan baseline by @lotyp in https://github.com/buggregator/trap/pull/115


**Full Changelog**: https://github.com/buggregator/trap/compare/1.8.0...1.9.0

## 1.8.0 (2024-05-29)

## What's Changed
* Add tr() and td() functions by @roxblnfk in https://github.com/buggregator/trap/pull/102
* Add `trap()->code()` sugar by @lee-to in https://github.com/buggregator/trap/pull/103
* Change release generation type to Github API by @lotyp in https://github.com/buggregator/trap/pull/106

## New Contributors
* @lee-to made their first contribution in https://github.com/buggregator/trap/pull/103

**Full Changelog**: https://github.com/buggregator/trap/compare/v1.7.5...v1.8.0

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
