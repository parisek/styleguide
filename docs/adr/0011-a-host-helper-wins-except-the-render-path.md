# 0011. A host helper of the same name wins, except the render path

Amends [ADR-0004](0004-twig-helpers-as-extension-and-runtime.md). Issue #267.

## Context

A component renders in the catalogue with the package's helpers. In
production it renders with the host's. When both define one name, such as
`placeholder()` or `|resizer`, the catalogue showed something production
never does.

On 1.38.2 the package won in almost every mode. Twig initialises the helpers
added one at a time last, and the package adds its own that way. Only a host
`addFunction()` / `addFilter()` won. ADR-0004 said a collision in bundle mode is
a loud container error. It is not: the bundle passes
`styleguide.twig_extensions` through `fromYaml()`, the same path as the
micro-kernel, and the package won there without a message.

## Decision

A host Twig function or filter with the name of a `StyleguideTwigExtension`
helper wins in every mode: an extension or `addFunction()` / `addFilter()` on a
`twig` environment, `twig_extensions`, the micro-kernel and the bundle. The
package reads the host's extension names before it registers its helpers and
leaves out each one the host defines.

Two limits:

- **`component_*` and `page_*` stay the package's** over a host extension.
  They are the catalogue's render path, not a helper. A host version would
  bypass the renderer, the null-content handling and the observer, so
  `renderObserved()`, `lint` and the trace would stop working. A host
  `addFunction()` of those names still wins, as it did before; that is the
  existing escape hatch.
- **The extras** (`TypographyExtension`, `AttributeExtension`, `IntlExtension`,
  `StringExtension`, `DumpExtension`) are whole extensions the package adds
  after the host's. Their own helpers keep winning over a host extension.
  Making them yield would drop the whole extension.

`Doctor::runFor()` reports each shadowed helper as a notice, which does not
fail a build. A project that uses its own `__()` on purpose must stay green.

## Consequences

The catalogue renders like production for the host's own helpers. A host that
relied on the package's version renames its helper or keeps it out of the
catalogue's environment.

Order matters when a host registers `StyleguideTwigExtension` itself (README,
initialised environment): only a host extension registered after it wins.

`styleguide doctor` on the command line cannot see a host definition, because
`styleguide.yaml` carries no `twig` or `twig_extensions`.
