# Design system: styleguide chrome

Status: first version, written on 2026-11-08 from `frontend/ui-contract/`, `frontend/styleguide.css` and the Vue components. It describes the chrome as it ships in 1.37.1. A change to a rule, a token set, a screen or a path here is Tier 3 (`docs/vision.md`, "Design authority").

## Overview

The chrome is a quiet frame around the project's own components. It must never compete with the preview. Neutral surfaces, one accent, compact controls, no decoration inside the canvas.

## Tokens

The tokens are the tools UI contract in `frontend/ui-contract/` (ADR-0010). `tokens.css` is the source of truth; test-kit vendors it.

| Role | Light | Dark | Utility |
| --- | --- | --- | --- |
| Surface | white | zinc-900 | `bg-ui-surface` |
| Toolbar | zinc-50 | zinc-900 | `bg-ui-toolbar` |
| Text | zinc-900 | zinc-100 | `text-ui-text` |
| Muted text | zinc-600 | zinc-300 | `text-ui-muted` |
| Border | zinc-200 | zinc-800 | `border-ui-border` |
| Control border | zinc-300 | zinc-700 | `border-ui-control-border` |
| Control hover border | zinc-400 | zinc-500 | `border-ui-control-hover-border` |
| Active surface / text | zinc-900 / white | zinc-100 / zinc-900 | `bg-ui-active-surface`, `text-ui-active-text` |
| Focus | red-600 | red-600 | `outline-ui-focus` |
| Preview canvas | white | white | `bg-ui-preview` |

Geometry: control height `2rem` (`h-ui-control`), toolbar height `57px`, panel radius `0.25rem`, pill radius `9999px`.

Rules:

1. A new chrome colour uses a token. A component that still uses a raw Tailwind colour (`red-600/10` for a selected menu row, `amber-*` for warnings) keeps it until the token exists; a new use of a raw colour is a design decision.
2. Red is the accent: selection, checked boxes and focus. Amber means a warning (the health badge). No other hue carries meaning.
3. The theme class is `.dark` on `<html>`. The preview document pins `color-scheme: light` and follows `?theme=` only.

## Typography

The chrome uses the Tailwind default sans stack and the default mono stack. Mono is for ids, paths and code. The project's fonts load only inside the preview.

## Layout

- A sidebar on the left, a toolbar on top of the content, the preview below the toolbar.
- On a narrow screen the sidebar becomes a drawer with a backdrop.
- The toolbar holds the viewport presets, the width menu, the theme, the locale and the per-entry actions. It does not wrap into a second row of unrelated controls.
- Panels (fields, source, usage) open beside or below the preview. They never cover the preview without a close control.

## Screens and paths

The routes are the URL surface of `docs/API.md` and `docs/specification.md`, R7.2.

| Screen | Path | Job |
| --- | --- | --- |
| Landing | `/` | Foundations, or the grid with `overview.default: grid` |
| Foundations | `/foundations` | Logo, palette, typography, fonts from `styleguide.yaml` |
| Overview | `/overview` | Components and pages as a list |
| Grid | `/grid` (`?view=board`) | Every entry as a live tile, with section and text filters, or a zoomable board |
| Fields | `/fields` | The field inspector across components |
| Component / page / doc | `/component/<id>`, `/page/<id>`, `/doc/<id>` | One entry: preview, variants, toolbar, panels |

A new screen, a new route or a change of the query grammar (`variant`, `theme`, `locale`, `view`, `zoom`, `at`) is a design decision.

## Components

The Vue components in `frontend/src/components/` are the component inventory. A new control is built from them and from the tokens. A new primitive joins `frontend/ui-contract/` only when test-kit needs it too (ADR-0010).

## Do and don't

- Do keep the preview canvas white and free of chrome styling.
- Do show a failure: an error mark on a tile, the health badge, the 500 message.
- Do give every icon button an accessible name.
- Don't add a colour, a radius or a shadow outside the tokens.
- Don't move a control between the toolbar and a panel without a design decision.
- Don't load a remote font or script in the chrome.
