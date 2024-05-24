# Changelog

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
