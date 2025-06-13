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

| Type       | Purpose                                                                  |
|------------|--------------------------------------------------------------------------|
| `feat`     | Adding a new feature (e.g., support for a new log source)                |
| `fix`      | Fixing a bug                                                              |
| `docs`     | Documentation updates                                                    |
| `style`    | Code style changes (spaces, formatting, etc.)                            |
| `refactor` | Code refactoring that doesn't change functionality                        |
| `test`     | Adding or updating tests                                                 |
| `chore`    | Maintenance tasks (updating deps, CI config, etc.)                       |
| `perf`     | Performance improvements                                                 |
| `build`    | Changes affecting build process or dependencies                          |
| `ci`       | CI configuration changes                                                 |

## 📌 Examples commit messages for Buggregator Trap

#### `feat`
feat(ui): added JSON log viewer in a separate tab

#### `fix`
fix(worker): fixed memory leak when parsing large payloads

#### `docs`
docs: added instructions for running in Docker

#### `refactor`
refactor(core): moved syslog message handler to a separate module

#### `test`
test: added tests for Telegram webhook integration

#### `chore`
chore(deps): updated react dependency to v18.3

#### `perf`
perf(parser): improved Sentry message parsing performance by caching regex

---

## ⚠️ Breaking Changes

If your commit introduces a **breaking change**, be sure to specify it:

feat(source): removed support for deprecated Graylog format

BREAKING CHANGE: Graylog v1 is no longer supported

## 📌 A Personal Note

🍺 Just to avoid repeating ourselves in DMs — here’s a bit of context about our open source.

We (the devs behind Buggregator, Trap, RoadRunner, Cycle ORM, and more) work on both pet projects and serious open source tools. We don't have funding, money, or marketing (yet!), but we have experience, passion, and a strong desire to grow the PHP/Go ecosystem.

> We don’t have money, but we have experience.  
> And if you find a way to monetize it — we’ll be business partners.

We’re always happy to see people join us who:
- want to **level up** through real-world projects;
- are tired of formal "jobs" and want to build something cool;
- would like to get **reviews from maintainers of Spiral, Yii, Cycle ORM, RoadRunner**;
- are curious about working with **LLMs, automation, build systems, CI, DevOps, architecture, and performance**.

---

## 🧠 What Are These Projects?

- **Trap** — a web server/log trap with UI and worker (this repo).
- **Buggregator** — a full-featured log center built on RoadRunner.
- **Cycle ORM** — a modern ORM without magic.
- **dload** — a binary downloader optimized for "vibe-coding".
- **Spiral, Yii** — contributions to frameworks (you’ll see why "it's not just Laravel that's bad").
- **RoadRunner** — high-performance PHP process manager written in Go.
- Also: CLI tools, dev utilities, landing pages, comics, videos — even Temporal (just kidding, Vlad promised us cash).

We don’t do "dailies", but we respect each other’s time. Join in as your time and energy allow — we’ve got tasks for every skill level and ambition.

## 🙏 Thank You

We truly appreciate any contribution. Even just reporting a bug or suggesting an improvement is a huge help 🙌
