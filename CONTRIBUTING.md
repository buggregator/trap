# Contributing to the Buggregator Trap Project

Hi there! 👋  
Thanks for your interest in contributing to [buggregator/trap](https://github.com/buggregator/trap).

Please read this guide before opening an issue or submitting a pull request.

## 📋 How to Contribute

1. Fork the repository.
2. Create a new branch from `main`:  
   `git checkout -b feat/my-new-feature`
3. Make your code changes.
4. Write or update tests if needed.
5. Make sure the code is properly formatted and passes all checks.
6. Commit using [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) (see below).
7. Submit a pull request.

---

## 🧾 Commit Message Guidelines

We follow the **Conventional Commits** specification to ensure a clean changelog and readable commit history.

**Format:**

### ✅ Supported Types
| Type        | Purpose                                                                  |
|-------------|---------------------------------------------------------------------------|
| `feat`      | New feature                                                               |
| `fix`       | Bug fix                                                                   |
| `perf`      | Performance improvement                                                   |
| `docs`      | Documentation changes                                                     |
| `style`     | Code style update (formatting, missing semi colons, etc)                 |
| `deps`      | Dependency updates                                                        |
| `refactor`  | Code refactoring                                                          |
| `ci`        | Continuous integration changes                                            |
| `test`      | Adding missing tests                                                      |
| `tests`     | Adding missing tests                                                      |
| `revert`    | Revert to a previous commit                                               |
| `build`     | Changes that affect the build system                                      |
| `chore`     | Other changes that don't modify src or test files                         |
| `security`  | Security improvements                                                     |

## 📌 Examples commit messages for Buggregator Trap
#### `feat`
feat(ui): added JSON log viewer in a separate tab

#### `fix`
fix(worker): fixed memory leak when parsing large payloads

#### `perf`
perf(parser): improved Sentry message parsing performance by caching regex

#### `docs`
docs: added instructions for running in Docker

#### `style`
style(ui): fixed indentation and removed unused CSS classes

#### `deps`
deps: bumped @buggregator/logger to 2.4.1

#### `refactor`
refactor(core): moved syslog message handler to a separate module

#### `ci`
ci(github): added build matrix for multiple Node.js versions

#### `test`
test: added tests for Telegram webhook integration

#### `tests`
tests(worker): added edge case tests for malformed input

#### `revert`
revert: revert "feat(ui): added JSON log viewer in a separate tab"

#### `build`
build: switched to esbuild for faster development builds

#### `chore`
chore(deps): updated react dependency to v18.3

#### `security`
security: patched log4js to fix prototype pollution vulnerability

---

## ⚠️ Breaking Changes

If your commit introduces a **breaking change**, be sure to specify it:

feat(source): removed support for deprecated Graylog format

BREAKING CHANGE: Graylog v1 is no longer supported

## 🙏 Thank You

We truly appreciate any contribution. Even just reporting a bug or suggesting an improvement is a huge help 🙌
