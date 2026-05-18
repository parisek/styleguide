# CLAUDE.md

Shared project instructions: @AGENTS.md

`AGENTS.md` is the shared AgentMD entry point for Codex, Cursor, Copilot, and other
AI coding assistants. Keep universal project rules there. This file should contain
only Claude Code-specific runtime configuration, hooks, and workflow preferences.

## Task Delegation

Spawn subagents to isolate context, parallelize independent work, or offload bulk mechanical tasks. Don't spawn when the parent needs the reasoning, when synthesis requires holding things together, or when spawn overhead dominates.

Pick the cheapest model that can do the subtask well:

- **Haiku** — bulk mechanical work, no judgment
- **Sonnet** — scoped research, code exploration, in-scope synthesis
- **Opus** — subtasks needing real planning or tradeoffs

If a subagent realizes it needs a higher tier than itself, return to the parent. Parent owns final output and cross-spawn synthesis. User instructions override.

## Preferred Tools

### Data Fetching

1. **WebFetch** — free, text-only, works on public pages that don't block bots.
2. **agent-browser CLI / claude-in-chrome** — for dynamic pages or auth walls that WebFetch can't handle.

### Browser Verification

The package's only browser surface is the SPA in `dist/` plus the iframe render endpoint. After UI/JS changes:

1. `npm run build` in `frontend/` (or `npm run watch` for continuous rebuild).
2. Open the styleguide on whichever consuming project is linked via the path repository (typically `https://tailwind-base.ddev.site/styleguide/`).
3. Verify the specific component or page that was changed — clicking around adjacent ones counts as a smoke check.

If the consuming project still shows old behavior after a rebuild, the project is on the Packagist copy of `parisek/styleguide` instead of the local symlink. Re-run `composer styleguide:local` in that project. See `AGENTS.md` § *Local development against a consuming project*.
