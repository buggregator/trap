# Agent skills

Trap comes with an [Agent Skill](https://agentskills.io) for coding agents such as Claude Code and Codex: `trap`,
in [`skills/trap`](../skills/trap). It teaches an agent to debug and profile a running PHP application through Trap:
to start the server, add dumps, log records or a profiler to the code, run it, read what it sent and clean up.

## What the skill does

The agent loads the skill when it debugs or profiles code at runtime, reads what the application sent, or meets
`trap()`, `tr()` or `td()` in the code. Type `/trap` in Claude Code to load it by hand.

- A script of the skill tells the agent what the project and the machine offer: the installed Trap, a running server,
  the channels of the project (Monolog, Sentry, Ray, a mailer, OpenTelemetry, workers such as RoadRunner) and the
  profilers of PHP.
- The agent starts `vendor/bin/trap --mcp` in the background when no Trap answers, and stops it when done.
- It reads the events over the [MCP server](mcp.md). An agent without an MCP client calls the same tools with a script
  of the skill, over plain HTTP.
- It takes the dumps, log records and profiling calls it added out of the code, takes back the settings it changed to
  reach Trap, and deletes the events of its runs. Events caught before, as the ones you watch in the console or the
  [web UI](web-ui.md), stay.

The references of the skill cover dumps and logs, profiling and Xdebug function traces, step debugging with the
[Xdebug debugger](xdebug.md), long-running workers, pointing Sentry, Monolog, Ray, a mailer or OpenTelemetry at Trap,
fixing a Content Security Policy with the [CSP reports](csp.md) of a Report-Only policy, and the server setup.

## Install with Composer

The [llm/skills](https://github.com/roxblnfk/skills) Composer plugin copies the skills of the packages a project
requires into `.agents/skills/` after every `composer install` and `composer update`, so the skill matches the version
of Trap the project has:

```bash
composer require --dev llm/skills
```

The plugin trusts the skills of the packages the project requires directly, so `buggregator/trap` in `require-dev` is
enough. The first time, it offers a `skills.json` that links the skills into the agent directories the project
already has, such as `.claude/skills/` for Claude Code. To set them by hand, create `skills.json` next to
`composer.json` and run `composer skills:update`:

```json
{
    "aliases": [".claude/skills"]
}
```

`composer skills:show` lists the skills found and where they come from.

## Install without Composer

[skills](https://github.com/vercel-labs/skills) installs the skill into one project from GitHub, into
`.agents/skills/` with links for each agent given:

```bash
npx skills add buggregator/trap -a claude-code -a codex
```

It takes the skill of the default branch, which may be ahead of the Trap version the project has.
