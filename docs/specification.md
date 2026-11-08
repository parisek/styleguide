# Specification

Status: first version, written on 2026-11-08 from `docs/API.md`, the ADRs and `README.md`. It records the behavior of release 1.37.1. It changes no behavior. A change to a requirement is Tier 3 (`docs/vision.md`, "How the agent decides"), unless the owner directed it.

This document is normative (`docs/vision.md`, "Precedence"). `docs/API.md` holds the exact shapes: the tables of keys, the JSON types, the flags. A requirement here states the rule. It cites the section of `docs/API.md` or the ADR that holds the detail. When a requirement and `docs/API.md` disagree, open an issue. Do not decide the conflict in code.

Requirements carry numbers (`R6.7`). A number is permanent. A removed requirement keeps its number with the word "Removed" and the date. A commit or a pull request cites the number of each requirement that it meets or changes.

---

## 1. Purpose

A project builds a site from Twig components. The people who build, design and approve the site must see each component as the site renders it, without a separate tool to maintain.

`parisek/styleguide` turns the component tree of a project into a live catalogue.

### Goals

- G1. One package serves the whole catalogue: chrome, renderer, parser, API and CLI. A project writes one bootstrap and one `styleguide.yaml`.
- G2. A preview is the production render: the project's Twig environment, CSS and JS.
- G3. Every reader gets the same facts: a person in the SPA, a tool through `/api/*`, an agent through the CLI.
- G4. A consumer updates with `composer update` and keeps working inside a major version.
- G5. Safe on a public host by default.

### Non-goals

- N1. No Node at runtime. No CDN. No hosted service.
- N2. No restyling of component markup. The package styles its chrome only.
- N3. No CMS integration beyond rendering Twig. A host framework supplies routing and authentication.
- N4. No visual comparison. `parisek/test-kit` compares runs. The catalogue is one of its sides.

---

## 2. Glossary

| Term | Meaning |
| --- | --- |
| entry | One catalogue item: a component, a page or a doc. It lives in `<kind>/<id>/` under a template root. |
| kind (folder) | `component`, `page` or `doc`. Not the `kind:` metadata key. |
| template root | A directory of `templates_path`. With a list, the first root is the strongest. |
| marker | A file that makes a folder an entry: `<id>.twig`, `<id>.yaml` or `styleguide.twig` (ADR-0009). |
| fixture | The template that a preview renders: `styleguide.twig`, a `styleguide.<variant>.twig`, or `<id>.twig`. |
| variant | A fixture `styleguide.<variant>.twig`. The SPA shows one tile for each variant. |
| sidecar | A YAML data file next to a fixture: `styleguide.data.yaml` or `styleguide.data-<name>.yaml`. |
| chrome | The SPA: sidebar, search, toolbar, panels. Built from `frontend/` into `dist/`. |
| render route | `/<mount>/render/<kind>/<slug>`: one entry as a full HTML document, without chrome. |
| mount | The URL path of the catalogue, `bootstrap.base_url`, default `/styleguide` (ADR-0005). |
| project-truth, run-truth | A config value true for the project (in `bootstrap:`), or true only for one run (in `$overrides`). |

---

## 3. Bootstrap and configuration

Detail: `docs/API.md`, "PHP API — public surface" and "YAML schemas".

- R3.1 A consumer constructs `Styleguide` with `templates_path`, `static_path` and `config_yaml`, or with `Styleguide::fromYaml($path, $overrides)`. Both build the same object.
- R3.2 `templates_path` is one directory or an ordered list of directories. Every entry must be an existing directory.
- R3.3 `fromYaml()` reads the `bootstrap:` key. Relative paths resolve against the directory of the YAML file, not against the working directory.
- R3.4 `bootstrap:` must not hold a run-truth key: `twig`, `twig_options`, `twig_extensions`, `auth`, `dist_path`, `config_yaml` or `twig_context.templateUrl`. `fromYaml()` throws `\InvalidArgumentException` and names the key. It never drops the key in silence.
- R3.5 A required key that is missing, and an optional key with a wrong type, throw `\InvalidArgumentException` with the file and the key. There is no guessed fallback.
- R3.6 An unknown key in `bootstrap:` or at the top level of `styleguide.yaml` is not an error. It is ignored.
- R3.7 A malformed value of a key that the package owns (for example `viewports.compare`, `pages.group_by`, `components.sections`, `builtin_pages`, `source_views`) throws `\InvalidArgumentException` at construction.
- R3.8 `twig_context` from `$overrides` merges into the YAML `twig_context`. Every other override replaces the value.
- R3.9 The mount is one value, `base_url` (ADR-0005). It is normalised to a leading `/` and no trailing `/`. A root mount, a relative path, an empty or dot segment, percent-encoding, non-ASCII, a query or a fragment throws at construction. Every produced URL and the cookie path use the mount.
- R3.10 `auth`, when set, is a callable that receives the route and runs once for each request, before any handler. `false` answers `403`. A throwing callable denies the request and logs the error (fail closed). A non-callable value throws at construction.
- R3.11 `run()` returns without output for a URI outside the mount. `handle()` returns `null` for it. `handle()` writes nothing and does not exit.
- R3.12 The Symfony bridge (`Bridge\Symfony\FrontController`, `StyleguideKernel`, `styleguide.config_resolver`) follows `docs/API.md`. `symfony/framework-bundle` stays a `suggest`, not a requirement.
- R3.13 A config resolver maps a request through an allowlist. A `NotFoundHttpException` from it answers `404` with a fixed message that carries none of the resolver's text.

---

## 4. Discovery and metadata

Detail: `docs/API.md`, "Component YAML metadata", "Component Twig file conventions", "Doc Twig file conventions". ADR-0002, ADR-0008, ADR-0009.

- R4.1 A folder `<kind>/<id>/` is an entry when it holds a marker (ADR-0009). A folder whose name starts with `_` is a partial. Only its `<id>.twig` counts.
- R4.2 With several template roots, the first root that holds a marker of `<kind>/<id>/` owns the whole folder: metadata, fixtures, sidecars, `css/` and `js/` (ADR-0008). A symlink from one root into another is refused.
- R4.3 The parser walks the template roots in a deterministic order: roots in list order, paths sorted segment by segment.
- R4.4 Metadata comes from `<id>.yaml` when it exists, else from the first `{# … #}` comment of `<id>.twig`. An entry without `name` is not in the catalogue, except a page listed in `pages.include` (R4.9).
- R4.5 A file that fails to parse does not stop the catalogue. The parser skips it and reports it in `/api/health` with a relative path.
- R4.6 `render:` normalises to one of `ComponentParser::RENDER_MODES`, default `inset`. `kind:` normalises to one of `ComponentParser::KIND_VALUES`, or `''`. The parser never guesses a `kind`.
- R4.7 `fields:` normalises to the canonical shape of ADR-0002. Every other authored key passes through unchanged. A consumer of the shape must accept unknown keys.
- R4.8 Variants are discovered from `styleguide.<variant>.twig`, with `<variant>` matching `[a-z0-9-]+`. No YAML is needed. `variants_order` orders them. The default fixture comes first.
- R4.9 `components.include` and `pages.include` limit the catalogue to the listed ids, on every surface: sidebar, API, search, overview, grid and CLI. A request for an entry outside the list answers `404`. An id that does not exist throws at construction and in `doctor`. Docs are not filtered.
- R4.10 `components.sections` sorts components into sections by `component`, then `kind` with `category`, then `category`, then `kind`. A component that no section takes goes to the fallback section, or to the implicit section `other`. A `hidden` section leaves every listing, but its components still render by URL.

---

## 5. Rendering

Detail: `docs/API.md`, "Twig functions & filters", "Render errors", "URL surface". README, "Component render modes", "File-convention variants".

- R5.1 A render uses the project's Twig environment and the project's `iframe.css`, `iframe.js` and fonts. The package does not change component markup.
- R5.2 When the consumer passes a `twig` environment, the package adds its helpers to it. A helper that the consumer already registered wins (ADR-0004).
- R5.3 The Twig functions and filters of `docs/API.md` are registered: `component_<name>()`, `page_<name>()`, `placeholder()`, `resizer`, `merge_resizer()`, `cachebust`, `styleguide_data()`, the i18n functions and the others in the table.
- R5.4 `component_<name>(null)` renders as `{}` and logs a warning with the caller template and line. A string or a number still throws.
- R5.5 `styleguide_data()` reads the sidecar of the calling entry. A path argument reads a named set or the sidecar of another entry (ADR-0003).
- R5.6 `placeholder()` makes an inline SVG data URL. It makes no network request. The same `seed` gives the same image.
- R5.7 Every component and page render carries the class `is-styleguide-render` on `<html>`. The 404 render does not.
- R5.8 `iframe.body_class` applies to component and page renders. It does not apply to foundations and doc renders. The metadata `body_class` applies after it.
- R5.9 `iframe.page_wrapper_class` wraps page renders only, in `<div data-sg-page-shell>`. Empty means no wrapper.
- R5.10 `?theme=light|dark` on the render route is allowlisted. Default `light`. `?variant=<id>` selects a variant. `?locale=<code>` selects a translation catalogue for one render.
- R5.11 A request with `Sec-Fetch-Dest: iframe` to an SPA path gets the render contents, not the SPA shell. A link with an SPA URL works inside a preview.
- R5.12 A render that throws answers `500`. The body carries the exception message only when `show_source` is on. When it is off, the body is `This entry cannot be shown.` and the message goes to the error log.
- R5.13 An unresolvable locale, a missing msgid or a broken `.mo` file falls back to the msgid. It never throws.
- R5.14 `renderObserved()` returns the HTML, the status, the calls, the unobservable includes and the `null_content` calls of one fixture. It reports and does not judge.

---

## 6. JSON API

Detail: `docs/API.md`, "JSON API endpoints".

- R6.1 `/api/components`, `/api/pages`, `/api/docs` and `/api/fields` return bare arrays. Their shapes are `@api`. A new field is optional and non-breaking. A removed or renamed field is a major change.
- R6.2 `/api/health` returns an object with `warnings`, `counts` and `checked: 'metadata'`. It states that it does not render.
- R6.3 `/api/source`, `/api/markup` and `/api/files` answer only while `show_source` is on. When it is off, they answer as an unknown endpoint.
- R6.4 `source_views` decides which of these endpoints answer and which files `/api/files` reads. Default `[data, html, css, js]`. `twig` is opt-in (ADR-0007).
- R6.5 `/api/files` skips a file over 200 kB, a file that is not valid UTF-8 and a file that resolves outside its template root.
- R6.6 The CLI commands `list` and `show` return the same shapes as the matching endpoints.

---

## 7. SPA chrome

Detail: `README.md`, "What it does" and the sections it links. Design authority: `docs/vision.md`, "Design authority".

- R7.1 The SPA is a static bundle in `dist/`. It reads its configuration from `#sg-config` and its data from `/api/*`. It needs no build in the consumer.
- R7.2 Every SPA route of `docs/API.md`, "URL surface" is a deep link. A reload shows the same view.
- R7.3 The SPA offers the viewport presets, a custom width, `viewports.compare` widths and side-by-side comparison of two to four widths.
- R7.4 An entry with variants shows one tile for each variant, each at the selected viewport.
- R7.5 A page set to `false` in `builtin_pages` leaves the sidebar, and its route shows the landing.
- R7.6 The chrome UI strings come from `frontend/public/locales/` (`cs`, `en`). The content locale follows `?locale=`, then the stored choice, then `bootstrap.default_locale`.
- R7.7 The SPA renders template data and YAML values as text. It inserts HTML only for a `description` value (entry, field, variant), which the metadata contract allows to hold HTML.
- R7.8 The chrome tokens come from `frontend/ui-contract/` (ADR-0010).
- R7.9 The chrome has a light and a dark theme. A preview follows the chrome theme through `?theme=`.

---

## 8. CLI

Detail: `docs/API.md`, "CLI"; `README.md`, "Command-line catalogue (CLI)".

- R8.1 `vendor/bin/styleguide` offers `list`, `show`, `lint`, `doctor`, `front-controller:init` and `maintenance:render`. A removed or renamed command is a major change. A new command or optional flag is a minor change.
- R8.2 `list` and `show` exit `0` on success and `1` on failure.
- R8.3 `lint` exits `0` when clean or with notices only, `1` with a warning or an error, `2` on a usage or internal error. `--ignore` reads a file of expected findings.
- R8.4 `lint` reads metadata through the same precedence as the runtime (R4.4).
- R8.5 `doctor` reports what `styleguide.yaml` does at runtime: missing paths, a stale or unbuilt `dist/`, a moved mount, an empty catalogue.
- R8.6 `front-controller:init` never writes through a symlink and never replaces a different `index.php` without `--force`.

---

## 9. Outage render

Detail: `docs/API.md`, "Offline outage render"; `README.md`, "Outage screen".

- R9.1 `maintenance:render` renders `@page/maintenance/maintenance.twig` to one HTML file next to the component.
- R9.2 The file is self-contained: the stylesheet is inlined, `@font-face` rules are removed, every `url()` that is not a `data:` URI becomes `none`, and the packaged shell holds no script.
- R9.3 One file holds one language. `--locale` and `--out` render another.
- R9.4 `--check` reports a file that is stale against its sources.

---

## 10. Security

Detail: ADR-0006, `docs/API.md` "Render errors", `SECURITY.md`.

- R10.1 `show_source` decides whether fixture sources, markup and entry files are published. A boolean wins. Absent or `null`: on only when the `auth` callable is set (ADR-0006).
- R10.2 No response carries an absolute server path, except a render error while `show_source` is on (R5.12).
- R10.3 Every file path that comes from a request passes `PathGuard`. A path that leaves its root, also through a symlink, is refused.
- R10.4 `AssetServer` serves files from `dist/` only. A hashed file name gets `immutable` cache headers. Every asset has an ETag and answers `304` to a matching `If-None-Match`.
- R10.5 The package sends no data off the machine. It loads no remote asset (R5.6).
- R10.6 A run-truth key in YAML fails loudly (R3.4), so a written `auth` is never ignored.

---

## 11. Distribution and compatibility

Detail: `docs/API.md`, "Versioning policy" and "What's NOT covered by SemVer"; `RELEASING.md`.

- R11.1 The package follows SemVer. The `@api` surface is: the classes and methods marked `@api`, the config array, the YAML schemas, the JSON shapes, the URL surface, the Twig functions and filters, and the CLI.
- R11.2 A deprecated `@api` item stays for at least two minor releases before removal.
- R11.3 `dist/` is committed. CI rebuilds it from `frontend/` and fails when the two differ.
- R11.4 The contents of `dist/`, the chrome class names and the packaged templates are not covered by SemVer. Only the URL surface and the JSON shapes are.
- R11.5 CI runs a backward compatibility check of the PHP API against the latest release.
- R11.6 Runtime needs PHP `^8.3` only. `composer.json` carries no `version` field. Versions come from git tags.
- R11.7 A release runs through the Stamp Release workflow. It stamps `CHANGELOG.md`, tags and publishes. Nobody stamps a heading or a tag by hand.

---

## 12. Documentation

Detail: `AGENTS.md`, "Documentation is part of the change".

- R12.1 A change to a YAML key, an API field, a Twig function, a config key, a CLI command or an `@api` method updates `docs/API.md` and, where a consumer sees it, `README.md` in the same pull request.
- R12.2 A consumer-visible change adds a line under `[Unreleased]` in `CHANGELOG.md`.
- R12.3 A hard-to-reverse decision is an ADR in `docs/adr/`, in the same pull request. The owner says yes before it is written.
- R12.4 A change to a requirement updates this document in the same pull request.

---

## 13. Open questions

None recorded yet. An open question gets a number here, the date and the options. Do not decide an open question in code. Ask the owner.
