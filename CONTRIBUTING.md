# Contributing to Buggregator Trap

## How to Contribute

1. Fork the repository
2. Create a branch: `git checkout -b feat/my-feature`
3. Make changes and write tests
4. Commit using [Conventional Commits](https://www.conventionalcommits.org/)
5. Submit a pull request

## Code Structure

**Client Code** (`src/Client/`): Public API that follows semantic versioning. Changes here affect how developers use the library.

**Server Code** (everything else): Internal code marked with `@internal`. Not subject to semver - breaking changes are allowed.

## Versioning

We use semantic versioning for the **Client API only**:

- **Minor version** for:
  - New Client API features
  - New CLI flags
  - New protocol support
  - New interface features
  - New mechanics
  
- **Patch version** for:
  - Bug fixes
  - Internal improvements
  - Documentation updates

Breaking changes to server code (`@internal`) don't need major version bumps.

## Code Style

We use extended PER 2.0 code style. You can run `composer cs:fix` to fix code style automatically. Don't worry about following the style perfectly - our GitHub CI will fix it automatically.

## Commit Guidelines

**Important:** Choose commit prefixes carefully! We use automatic releases that look at commit prefixes to decide the next version number.

Use [Conventional Commits](https://www.conventionalcommits.org/) format:

| Type              | Purpose                           |
|-------------------|-----------------------------------|
| `feat`            | New feature                       |
| `fix`             | Bug fix                           |
| `perf`            | Performance improvement           |
| `docs`            | Documentation                     |
| `style`           | Code formatting                   |
| `deps`            | Dependencies                      |
| `refactor`        | Code refactoring                  |
| `ci`              | CI changes                        |
| `test` or `tests` | Tests                             |
| `revert`          | Revert commit                     |
| `build`           | Build system                      |
| `chore`           | Other changes                     |
| `security`        | Security improvements             |

**Examples:**

```markdown
feat(client): add depth() method to TrapHandle
fix(server): resolve memory leak in parser
perf(client): optimize dumper performance
docs: update installation instructions
```
