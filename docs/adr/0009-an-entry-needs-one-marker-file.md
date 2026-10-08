# ADR-0009 — An entry needs one marker file, not a template

## Context

[ADR-0008](0008-first-template-root-owns-the-component-folder.md) says the first
root that holds `<kind>/<id>/<id>.twig` owns the folder. The same file also
makes the folder an entry. Tracking issue: #199.

A page that exists only for the catalogue needs no production template. Its
name, category and description sit in `<id>.yaml`. Its content sits in
`styleguide.twig`. The `<id>.twig` file is empty. A host reported the cost: 43
empty page templates in one kit and 1 in tailwind-base. An empty template looks
like production code, and each new catalogue-only page needs one more file with
nothing in it.

Two alternatives were considered:

- **Keep the empty file.** It works, and it adds noise. Rejected: the file
  carries no information, and a person who reads it as the page template finds
  nothing.
- **A new marker file** such as `.entry`. Rejected: it is one more file with
  nothing in it.

## Decision

A folder `<kind>/<id>/` is an entry when it holds any of these files:

- `<id>.twig`
- `<id>.yaml`
- `styleguide.twig`

This replaces the marker sentence of ADR-0008. The rest of ADR-0008 stands. The
first root that holds any marker owns the whole folder, and no file of that
folder comes from a later root. A marker that is a symlink out of its root does
not count. Another marker of the same folder, inside the root, still counts.

A folder whose name starts with `_` is a partial. Only its `<id>.twig` counts,
as before. The two new markers never make a partial an entry.

A folder that holds `<id>.twig` behaves as before.

Without `<id>.twig` the entry has no production template:

- The render endpoint uses the default fixture, `styleguide.twig`. Without a
  fixture it answers 404.
- With `<id>.yaml` and no fixture, the entry is listed with its metadata and
  `has_styleguide` is false. It has no tile, like any entry without a fixture.
- With a fixture and no `<id>.yaml`, the entry has no `name`, so the catalogue
  does not list it. It still renders by its URL. `pages.include` lists it with a
  title taken from its id.
- `components.include` and `pages.include` accept an id that has any marker.

## Consequences

- A catalogue-only page needs `<id>.yaml` and `styleguide.twig`. It needs no
  empty template.
- A scanner that finds entries by `<id>.twig` alone misses the new entries. The
  package scans for all three markers: the catalogue, `listDirectories()`, the
  owner lookup, the filters, the files endpoint and `styleguide lint`. A host
  script that does its own scan must follow.
- A folder that holds only `styleguide.twig` is now an entry. Before, it owned
  nothing.
- A folder that is not an entry must not hold `<id>.yaml` or `styleguide.twig`,
  or its name must start with `_`.

`tests/Overlay/EntryMarkerTest.php` keeps this true.
`LegacySingleRootTest` pins the behaviour of a folder with `<id>.twig`.
