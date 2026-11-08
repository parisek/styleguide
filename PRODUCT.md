# Product

Status: first version, written on 2026-11-08 from `README.md`, `docs/API.md` and the SPA source. A change to a principle or a reader in this file is Tier 3: the owner approves it (`docs/vision.md`, "Design authority").

## Platform

web

## Users

The primary reader is a developer or a designer of a project that renders Twig. They open the catalogue to find a component, see it at a width, read its fields and copy a link. The client and the project lead are secondary readers: they open a link to one entry and judge the look. A tool or an agent reads the same facts through `/api/*` and the CLI (`docs/vision.md`, "Readers"). The SPA is for people.

## Product purpose

The catalogue answers one question: what does this component look like, and how is it used, as the site renders it today? Success: a reader opens a link, sees the same entry, width, variant and theme as the sender, and trusts that production looks the same.

## Positioning

The package shows and documents. It does not edit content, does not compare runs (test-kit does) and does not restyle components (`docs/specification.md`, N2, N4). A preview that fails says so. It does not hide.

## Operating context

- The catalogue runs inside the project, on the project's host, under one mount (`/styleguide` by default).
- A project may expose it on the public internet. Fixture sources are then off unless a gate exists (ADR-0006).
- Readers use a desktop browser. A phone is possible: the sidebar collapses, and the toolbar keeps its controls.
- The chrome speaks Czech and English. Content may speak any language a `.mo` catalogue holds.

## Capabilities and constraints

- One catalogue, every reader: the SPA, the API and the CLI read the same parser output (`docs/specification.md`, R6.6).
- A preview is an iframe with the project's own CSS and JS. The chrome never styles inside it.
- Every view is a deep link: entry, variant, width, theme and locale.
- Template data and YAML are untrusted text. Only a `description` may hold HTML.

## Brand commitments

The chrome is neutral (zinc) with a red accent for selection and focus. The project's name and favicon come from `styleguide.yaml`. The package shows its credit in the sidebar footer.

## Evidence on hand

The fixtures in `tests/fixtures/` hold sample data only. Screenshots in issues and pull requests come from the fixtures or from a consumer that agreed to it. No client content goes into this repository.

## Product principles

These refine the principles of `docs/vision.md`. They do not replace them.

- P1. Production first: a preview equals the production render (vision principle 2).
- P2. One entry, one link: every view is reachable and reproducible from a URL.
- P3. Show the gap: a broken render, a parse warning or an empty catalogue is visible (`/api/health`, the health badge, the 500 render).
- P4. The same facts for the person and the tool: nothing exists only in the SPA (vision principle 4).

## Accessibility and inclusion

Keyboard operation (the ⌘K palette, the sidebar, the toolbar menus), a named control for every action, and WCAG AA contrast in both themes. A colour never carries a meaning alone. The chrome has a light and a dark theme with a toggle; the preview document keeps `color-scheme: light` unless `?theme=dark` asks otherwise. Not measured: a full WCAG audit of the chrome.
