# ADR-0007 — The Code panel's views are listed per project, the template only on request

## Context

[ADR-0006](0006-show-source-off-unless-gated.md) put the fixture source
behind `show_source` and served only the two fixture file names, never a
component's own `<slug>.twig`. It said that widening that set is a new
decision under its rule.

1.25.0 widens it. The Code panel gets five views of one tile:

- **Data**: the fixture, the call with sample data (`/api/source`).
- **Twig**: the entry's own template (`/api/files`).
- **HTML**: what the fixture renders (`/api/markup`).
- **CSS** and **JS**: the entry's own stylesheets and scripts
  (`css/**/*.css`, `js/**/*.js` in its folder, `/api/files`).

They do not expose the same thing. HTML, CSS and JS reach every visitor's
browser anyway; the panel shows them one step earlier. The fixture is sample
data. The template is the implementation: which parameters a component
takes and how it builds its markup. Most of it can be read back from the
HTML, and in practice little of it is know-how worth guarding. But it is
more than a public catalogue published before, and the decision to publish
it belongs to the project, not to a package upgrade.

Projects also differ in what they want to show at all. The Dráty z Porty
kit, behind a login, wants every view. A client's catalogue may want the
call and its output only.

Three alternatives were considered:

- **Every view behind `show_source`.** One switch, but a catalogue that
  turned `show_source` on for its fixtures would start publishing its
  templates on upgrade. Rejected: ADR-0006 already rules that a minor
  release must not widen what a deployment exposes.
- **A boolean for the template (`show_template`).** Solves the template,
  but leaves the other views all-or-nothing, and the next view would need
  its own key. Rejected in review, before release.
- **Deriving it from the host (auth, environment).** Rejected for the
  reasons ADR-0006 gives: the package owns no reliable signal beyond
  `auth`, and `auth` already feeds `show_source`.

## Decision

`source_views` in `styleguide.yaml` lists the views a catalogue offers:
a non-empty list of `data`, `twig`, `html`, `css`, `js`.

- **Absent: `[data, html, css, js]`**, everything but the template. A
  project publishes its templates by writing `twig` into the list.
- It applies only while `show_source` is on (ADR-0006). With the source
  off, no view exists, whatever the list says.
- A view that is not listed does not exist: the panel does not offer it,
  and its endpoint answers exactly like an unknown one (404). `/api/files`
  reads only the kinds of file the list names.
- Anything but a non-empty list of those names throws at construction. The
  panel keeps its own order, whatever order the list is written in.

The rule lives in `Styleguide::sourceViews()` (validation and default) and
`Styleguide::dispatchApi()` (which endpoint answers). The SPA receives the
list as `sourceViews` in `#sg-config`, only while `show_source` is on.

## Consequences

- A catalogue that upgrades without touching `styleguide.yaml` gains the
  HTML, CSS and JS views where `show_source` is on, which show only what its
  visitors' browsers receive. Its templates stay unpublished.
- The Dráty z Porty kit writes `source_views: [data, twig, html, css, js]`.
- A new view is a new name in `SOURCE_VIEWS`. Whether it joins the default
  follows the same test: only when it shows nothing a browser does not
  already receive. Anything else is off until a project lists it.
- `/api/files` stays bounded: it reads inside `templates_path` only (a
  symlink out is skipped), at most 20 files per entry, each at most 200 kB
  and valid UTF-8, tests left out.
- Guard: `tests/Api/FilesEndpointTest.php` covers the default without the
  template, each view read only when listed, the 404 of an unlisted view on
  all three endpoints, the SPA payload, and invalid values.
  `tests/Api/SourceEndpointTest.php` keeps covering ADR-0006.
