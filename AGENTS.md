# AGENTS.md

Buggregator Trap: a debug server for local development plus the client functions `trap()`, `tr()`, `td()`.
What it does for users: [README.md](README.md) and [docs/](docs/README.md).

## Code

- `src/Client/` and `src/functions.php` are the public API under semver. `src/Server/`, `src/Module/`,
  `src/Support/` are `@internal` and may break freely. Layer dependencies live in
  `tools/structarmed/structarmed.php`.
- Tests run on Testo, not PHPUnit: the `testo-*` skills cover writing and
  running them. Suites: `composer test:unit`, `test:arch`, `test:acceptance`.
- Before a commit: `composer test`, `composer psalm`, `composer structarmed`, `composer cs:fix` (the QA tools install
  with `composer bin all install`).
- Commit prefixes drive automatic releases and the next version: pick them by [CONTRIBUTING.md](CONTRIBUTING.md).

## Docs

`README.md` is a short landing page; the full user docs live in `docs/`. Navigation inside `docs/` stays inside
`docs/`, linking only to other docs pages and repo files.

## The `trap` skill

`skills/trap/` is a shipped artifact: the `llm/skills` plugin copies it into every project that requires Trap, in
that Trap version. Agents of those projects know Trap only through it.

Every change users can observe lands in the same change in `docs/` and in `skills/trap/`: a feature, a behaviour
change, a CLI option, an env variable, a `trap.xml` setting, a port, a default, an MCP tool or its arguments.
Done when each such fact reads the same in the code, the docs page and the skill reference that covers it
(`SKILL.md` routes to them).

Edit any skill text, and this file, by the `writing-for-agents` skill.

`.agents/skills/` and `.claude/skills/` are generated from `skills.json` by `composer skills:update`: edit the
sources in `skills/`.
