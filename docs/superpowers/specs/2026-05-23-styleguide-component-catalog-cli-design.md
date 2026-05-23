# Component Catalog CLI

**Date:** 2026-05-23
**Status:** Approved (brainstorming complete, pending spec review)
**Topic:** Expose `ComponentParser` data through a `vendor/bin/styleguide` CLI so AI coding assistants working in downstream projects can quickly discover what components exist and how to use them.

## Problem

When an AI assistant (Claude, Codex, …) works inside a consuming project (`tailwind-base`, WordPress/Drupal skeletons), it has two recurring pain points:

1. **"What components exist?"** — The assistant builds a feature from scratch instead of reusing `card/promo` or `hero/centered` because discovering the catalog requires multiple `Glob` + `Read` calls.
2. **"How do I use this component correctly?"** — Once a candidate is found, knowing its props/fields, variants, and intended use requires reading the `.twig` source and the styleguide page.

The HTTP JSON API (`/styleguide/api/components`) already exposes this data, but requires the dev server to be running and the assistant to know the URL. The filesystem-level alternative (raw `.twig` files) is unstructured and slow to traverse.

## Solution

Ship a thin CLI binary (`vendor/bin/styleguide`) inside the package that wraps the existing `ComponentParser` and prints the same normalised data to stdout as JSON.

The CLI is a **transport adapter only** — no new business logic, no duplicated parsing, no new metadata fields. It exposes the parser through a second face (after HTTP) so the data is reachable without a running webserver.

## Goals

- One Bash call lists the entire component catalogue with normalised metadata.
- One Bash call returns full detail for a single component.
- Works in any consuming project (framework-agnostic — no WordPress / Drupal coupling).
- Zero new runtime dependencies. PHP 8.3+ is already required.
- Zero infrastructure: no server process, no Node, no MCP wiring.
- Output is machine-readable JSON by default; pretty-printed for terminals.

## Non-goals

- **No MCP server.** Discovery is read-only static data — MCP brings no benefit a CLI doesn't, and adds per-consumer configuration overhead.
- **No visual regression / screenshot tooling.** That's a separate workflow; for local iteration the existing browser automation tools (`claude-in-chrome`, `chrome-devtools-mcp`, `agent-browser`) plus a multimodal LLM cover it. For CI use a dedicated tool (BackstopJS, Percy) in the consumer.
- **No render-component-to-HTML command.** The HTTP endpoint already does this; duplicating it in the CLI requires booting Twig + the consumer's environment, which the agnostic CLI cannot do safely.
- **No WordPress-CLI command in this package.** WP-CLI couples the package to WordPress; the package stays framework-agnostic. A consumer that wants `wp styleguide list` can ship a 3-line WP-CLI shim in its own theme/plugin that calls `vendor/bin/styleguide`. Drupal consumers do the same with Drush.
- **No filtering/search inside the CLI.** Consumers (humans or LLMs) pipe to `jq` or filter the JSON themselves. Keeping the CLI dumb avoids reinventing query syntax.
- **No `presets` / variant-only subcommand.** The data returned by `show` already includes any preset/variant fields; a separate command would be a subset filter, not new functionality.

## Architecture

```
+-------------------------------------------+
|  vendor/bin/styleguide  (entry script)    |
|    - parses argv                          |
|    - resolves consumer's templates dir    |
|    - dispatches to Cli\Command            |
+-------------------------------------------+
                 |
                 v
+-------------------------------------------+
|  Parisek\Styleguide\Cli\Command           |
|    list($type): array                     |
|    show($type, $id): ?array               |
|    -> instantiates ComponentParser        |
|    -> json_encode + echo                  |
+-------------------------------------------+
                 |
                 v
+-------------------------------------------+
|  Parisek\Styleguide\ComponentParser       |
|    (already exists — no changes)          |
+-------------------------------------------+
```

### File layout

```
bin/
  styleguide                # CLI entry script (executable, #!/usr/bin/env php)
src/Cli/
  Command.php               # Subcommand dispatch + JSON output
tests/Cli/
  CommandTest.php           # PHPUnit coverage for list/show against fixtures
composer.json               # +bin entry, +autoload-dev for Cli tests
```

The new namespace `Parisek\Styleguide\Cli\` is parallel to `Parisek\Styleguide\Api\` — same role (transport layer), different protocol.

## CLI surface

### `styleguide list [--type=component|page] [--pretty]`

Lists every component (default) or page in the consumer's `templates/<type>/` tree.

**Default output (JSON, one line):**
```json
[{"id":"input","name":"Input field","category":"forms","description":"Single-line text input","weight":50,"hasStyleguide":true,"fields":[…]},…]
```

**`--pretty`:** human-readable indented JSON. For terminals; LLMs use the default.

**Exit code:** `0` on success (even when the catalogue is empty), `1` only on parser errors (missing `templates/` directory, etc.).

### `styleguide show <id> [--type=component|page] [--pretty]`

Returns the full normalised record for a single component or page, in the exact shape `ComponentParser::parse()` returns.

**Output (JSON):**
```json
{"id":"input","name":"Input field","category":"forms","description":"…","weight":50,"usage":"…","fields":[{"name":"label","type":"string","required":true},…],"hasStyleguide":true,"asana":"","figma":"","drupal":"","web":""}
```

**Exit code:** `0` when the component is found, `1` when not.
On not-found, stderr carries a short message (`Component "foo" not found.`), stdout is empty.

### `styleguide --help` / `styleguide <command> --help`

Standard help output naming both commands and their options. No external dependency for arg parsing — a minimal hand-rolled parser is enough for two commands.

## Implementation notes

### Locating the consumer's templates directory

The CLI must find `templates/` in the consumer, not in the package. Resolution order (first match wins):

1. `--templates=<path>` flag (escape hatch for tests and unusual layouts).
2. `STYLEGUIDE_TEMPLATES` env var.
3. Conventional default: `getcwd() . '/templates'` — assumes the user runs the CLI from the consumer's repo root, which matches the rest of the package's conventions (consumer-side commands run from the consumer root).
4. Fall back to `getcwd()` itself if it already ends in `templates`.

If none of these resolve to an existing directory, exit with code `1` and a clear message: `templates/ directory not found. Use --templates=<path>.`

### Composer wiring

`composer.json` gets:

```jsonc
{
  "bin": ["bin/styleguide"],
  "autoload-dev": {
    "psr-4": { "Parisek\\Styleguide\\Tests\\Cli\\": "tests/Cli/" }
  }
}
```

After `composer install`, the consumer's `vendor/bin/styleguide` is a symlink to the package's `bin/styleguide`.

### Output discipline

- Default = compact JSON (one line). Optimised for piping to `jq` or being read by an LLM that pays per token.
- `--pretty` for humans.
- Stderr is reserved for error messages; stdout is reserved for JSON. This lets `vendor/bin/styleguide list 2>/dev/null | jq …` work cleanly.

## Testing

PHPUnit, mirroring the existing pattern:

```
tests/Cli/CommandTest.php
```

Cases (use the existing `tests/fixtures/templates/component/sample/` fixture):

1. `list` against the fixture returns the expected JSON array (snapshot-style assertion).
2. `show sample` against the fixture returns the expected single object.
3. `show missing` exits non-zero, writes to stderr, leaves stdout empty.
4. `list --type=page` against an empty pages directory returns `[]` and exits zero.
5. `--templates=<path>` override resolves correctly when `getcwd()` is unrelated.

The CLI's entry script (`bin/styleguide`) is tested indirectly by invoking it as a real PHP process via `Symfony\Component\Process\Process` for one smoke test that asserts the bin file is executable end-to-end.

Static analysis: `composer phpstan` must continue to pass at the current level — the new `Cli\Command` class declares strict types and uses the same `final class` convention as the rest of `src/`.

## Documentation

### Package-side

`README.md` gains a short section under "Usage":

> ### Programmatic catalogue (CLI)
>
> After install, `vendor/bin/styleguide` exposes the component catalogue without needing the web UI:
>
> ```bash
> vendor/bin/styleguide list                  # all components
> vendor/bin/styleguide show card/promo       # one component, full detail
> vendor/bin/styleguide list --type=page      # all pages
> ```
>
> Useful for AI coding assistants and scripted tooling. Output is JSON; pipe to `jq` for filtering. Run from the consumer's repo root.

### Consumer-side

In `tailwind-base` (and downstream skeletons), `AGENTS.md` / `CLAUDE.md` gets a 3-line addition under a "UI building" or "components" heading:

> When building UI, first run `vendor/bin/styleguide list` to discover existing components, then `vendor/bin/styleguide show <id>` for fields and usage. Prefer reuse over creating new components.

This sits in the consumer because the package's own `CLAUDE.md` / `AGENTS.md` is for *people building the package*, not for people *using* the styleguide from a downstream project.

## Future considerations (not in scope here)

- **`render` subcommand** that bootstraps Twig + the consumer's data layer and prints rendered HTML for a component. Requires solving the framework-agnostic bootstrap problem (Drupal needs Drupal's container, WP needs WP's theme functions) — too much complexity for v1.
- **`--format=table` / `--format=names`** for human terminal use. Skip until someone asks.
- **MCP server.** Revisit only if a future feature needs persistent state, warm processes, or strongly-typed discoverable tools — discovery alone doesn't justify it.

## Release impact

- New minor version (`0.2.x` → `0.3.0`) — adds a feature, no breaking changes.
- `CHANGELOG.md` entry: `### Added — Component catalog CLI (vendor/bin/styleguide list|show)`.
- No migration required in consumers; the CLI is opt-in.
