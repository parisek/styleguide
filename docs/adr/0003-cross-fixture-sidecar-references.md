# 0003. Let a fixture reference another fixture's sidecar by path

## Context

`styleguide_data()` resolved sidecar files strictly inside the directory of the
component/page/doc being rendered. That was deliberate and documented in three
places — the `Renderer` docblock, `README.md` and `docs/API.md` all said there is
no cross-component lookup, and that a fixture wanting another's demo data should
duplicate it.

The reasoning was sound: a fixture's demo data is its own business, and a lookup
that can reach anywhere makes every fixture a potential dependency of every
other.

It held until several page fixtures had to render the same **shared chrome** — a
header, a footer — with identical data. At that point "duplicate it" stops being
the cheap option and the package offers no other, because the remaining tool is
`{% include %}`, and an include **cannot export variables to its caller**: a
`{% set %}` inside one is include-local. So the partial cannot hand data back. It
has to render as well as hold, and every consumer's demo content accumulates
inside that one file.

Measured in the `sloneek` consumer before this change:

- the shared header partial was **1147 lines**, of which 7 were `{% set %}` — the
  rest hardcoded menu, CTAs, announcement bar, login and language-switcher data
- it was included by **30 fixtures**, 29 of which passed nothing but a variant
- `component/header/` — the component that owns all of that data — held none of it

The one route the package did permit was worse: symlinking a sidecar into the
consumer's own directory so the scoped lookup would find it. That had already
been done once in the same project. It does not survive thirty consumers.

So the boundary was not producing isolation. It was producing one unversioned,
unshared, ever-growing file whose contents no component owned, and whose
consumers' dependency on it was invisible at every call site.

## Decision

`styleguide_data()` takes a single **path** argument whose segment count selects
the shape:

```twig
styleguide_data()                          {# own default set #}
styleguide_data('gallery')                 {# own named set #}
styleguide_data('component/header')        {# another fixture's default set #}
styleguide_data('component/header/dark')   {# another fixture's named set #}
```

Three properties are load-bearing.

**Segment count is the entire grammar.** `/` is illegal inside an id and inside a
set name — the `^[a-z0-9-]+$` rule that already governs `styleguide.<variant>.twig`
ids forbids it — so the shapes cannot collide. A one-segment reference is
unambiguously a set name in the current directory, which is what makes every call
written before this change keep its exact meaning.

**One argument, not two.** A two-parameter form (`name`, plus a `from` naming the
source) was implemented first and rejected during review. It required a keyword to
say something the string could say by itself, and the shape every reader tried
first — `styleguide_data('component/header')` — bound silently to the set-name
parameter and failed with a message that never mentioned the other one. A second
parameter would also have meant "the set" in one call and "the source" in another
depending on what the first one held. A path says both at once, in the order the
directory layout already implies.

**Validation is a whitelist, never a traversal filter.** The reference is
concatenated into a filesystem path. `<kind>` must be one of `component` / `page`
/ `doc`, and every id segment must match `/^[a-z0-9-]+$/D`. The `D` modifier is
not decoration: PCRE's default `$` also matches before a trailing newline, so
`component/header\n` would otherwise pass validation while the documentation
claimed nothing else was expressible. A `PathGuard::pathEscapesRoot()` check on the
resolved **file** backs the lexical rules, covering the one escape a string cannot
describe. On the file rather than its directory, deliberately: a directory-only
check walks straight past a symlinked sidecar.

## Consequences

**Any fixture can now reference any other.** This is the real cost and it is not
hypothetical: a page fixture can grow a dependency on a component's demo data, and
changing that component's sidecar can change how an unrelated page renders. The
mitigation is that the dependency is *stated at the call site* and is greppable —
which the include chain it replaces was not. That is a weaker coupling than the
1147-line partial, not a stronger one, but it is a coupling the package previously
did not permit at all.

**Reach for it only when the data is genuinely shared.** Duplicating a couple of
keys still reads better than a reference. The form exists for the case where the
alternative is a data partial accumulating every consumer's fixture data; README
says so where authors will read it.

**Two arguments that used to be refused are now valid** — that is the feature:
`component/header` and `component/header/dark` were invalid set names and now
resolve. Of the ones still refused, the diagnostic changed: `a/b` was an invalid
*set name* and is now an invalid *kind*; a reference with an empty segment or
more than three names its own shape. Same exception type throughout. Two tests
in this repository asserted on the old wording and were updated.

**One input's behaviour genuinely regressed and was restored.** An earlier
revision of this branch made `styleguide_data('')` throw. It had resolved to the
current default set before, so a consumer passing a possibly-empty variable would
have started failing. It is back to being an alias of the no-argument form, with
a test pinning it.

**The `D` modifier is a deliberate narrowing, not a pure fix.** A set name with a
trailing newline used to resolve — `styleguide.data-gallery\n.yaml` was reachable
— and no longer does. Nothing plausibly relied on it, and the alternative was
documentation that claimed a guarantee the code did not provide.

**The `D` modifier fix reaches beyond this feature.** It was applied to the
set-name pattern as well, where the gap pre-existed. A set name with a trailing
newline used to pass validation and now does not.

**What keeps this from drifting:** the reference grammar is covered by tests for
every shape (no argument, one segment, two, three, too many, empty) plus a
nine-case traversal provider including the newline and uppercase forms; and an
end-to-end test renders all three fixture templates through `Renderer::render()`,
so the call shape the documentation shows is the call shape that is exercised —
the earlier version of this feature had fixture templates that no test rendered,
and the documented syntax therefore had zero coverage.

**If this is ever reversed**, the thing to replace it with is not the old scoped
lookup — that is what produced the 1147-line partial. It is a first-class shared
data location, which was considered here and rejected because it moves a
component's demo data away from the component and adds a second concept to learn.
