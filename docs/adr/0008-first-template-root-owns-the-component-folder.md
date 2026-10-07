# ADR-0008 — The first template root owns the whole component folder

## Context

`bootstrap.templates_path` takes a list of roots as well as a string. A host
uses this to put a project over a shared kit: the project overrides a few
components and takes the rest from the kit. Tracking issue: #190.

Two roots can both hold part of one component. The project may have
`component/button/button.twig`, and the kit may have
`component/button/styleguide.twig` and `component/button/button.yaml`. The
catalogue must decide which file it shows.

Two alternatives were considered:

- **Fallback per file.** Take each file from the first root that has it. A
  project override of `button.twig` would then show the kit's fixture and the
  kit's `button.yaml`. Rejected: the catalogue shows a half component. The
  fixture calls the kit's template with the kit's parameters, so it can
  describe a component that does not exist in the project. A reviewer cannot
  tell from one folder what the project ships.
- **Merge the folders.** Same problem, with a harder question when two roots
  hold the same file name.

## Decision

With a list, the order of the roots is the order of the list. The first root
wins.

The first root that holds `<kind>/<id>/<id>.twig` owns the whole folder
`<kind>/<id>/`. That is the template, the fixtures, `<id>.yaml`, the data
files, `css/` and `js/`. No file of that folder comes from a later root.

A root owns a folder only when the template stays inside the root. A symlink
from root 1 into root 2 does not make root 1 the owner.

Example. The host sets `templates_path: [project, kit]`.

- `project/component/button/button.twig` exists. `kit/component/button/` has a
  template, a fixture and `button.yaml`. The project owns `button`: the
  catalogue shows the project's template and only the files in
  `project/component/button/`. If the project has no fixture, `button` has no
  fixture. The kit's is not used.
- `project/component/card/` does not exist. The kit owns `card`.

Twig templates are different. A template that the owner does not have is found
in the next root, in the same order. A kit component that calls
`component_button()` uses the project's `button`.

A string `templates_path` is one root and behaves as before. Only the string
form is legacy. A list is never legacy, even with one element or with
duplicates that collapse to one root: it enforces the containment rule above.

## Consequences

- An override folder must be complete. To change one template, the project
  copies the whole folder and edits it, including the fixture and
  `<id>.yaml`.
- The override stays reviewable: one folder shows all of the component.
- A reader can see the owner of a component from the file system alone.
- A file that a project forgot to copy is missing in the catalogue. It is not
  filled from the kit. This is on purpose.

The tests in `tests/Overlay/` keep this true. `OverlayCatalogueTest` checks
that no kit fixture or data file leaks into an owned folder.
`TemplateRootsTest` and `RootReportingTest` check the order of the roots and
the refusal of a symlink between roots.
