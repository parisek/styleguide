# ADR-0004 — Twig helpers as a stateless extension plus a runtime

## Context

The package registers its bundled Twig helpers by **mutating an environment at
request time**. `Styleguide::registerBundledHelpers()` built every helper as a
closure capturing `$this`, then added them one at a time through
`tryAddFunction()`, which swallows every `LogicException` Twig raises from
`addFunction()`.

Swallowing was deliberate and, for one of the two cases, correct: a duplicate
name means a consumer registered their own — a WordPress host's real `__()`
instead of the identity stub — and their version must win. That contract is
asserted in `tests/BundledHelpersTest.php`.

The other case is not a contract. Twig also raises `LogicException` when the
extension set has already been **initialized**, which happens the moment
anything reads a function or filter from the environment. A framework that
builds Twig as a compiled, lazily-booted service — Symfony — routinely reaches
that state before a consumer's code runs. Registration then cannot take effect,
and the two swallowed cases are indistinguishable by design.

What a consumer actually experiences, now pinned in
`tests/Twig/LockedEnvironmentTest.php`:

- an extension the package wants is missing → construction throws from
  `registerBundledExtensions()`, whose `addExtension()` has no tolerant
  wrapper. The message names the extension, which misdirects: the extension is
  not the problem, the closed environment is.
- every extension is already present → construction **succeeds**, then every
  helper is dropped in turn. A line per helper reaches `error_log()`, which in
  a deployed install lands in a file nobody is watching, after the response has
  been served.

The second is the shape a real Symfony application has, and it is the one that
fails quietly. The package had no test representing such a consumer: every
suite built Twig by hand and never read from it before handing it over, so the
environment was never closed when the package mutated it. The defect was not
subtle code — it was an untested consumer shape.

## Decision

Split the helpers along **mutability**, not along statefulness in general.

`StyleguideTwigExtension` holds the definitions and nothing that a request can
change — only config fixed at construction, such as `static_path` for
`|cachebust`. Being stateless, it can be registered while a Symfony container
compiles, tagged `twig.extension`, instead of being mutated onto a live
environment.

`StyleguideRuntime` holds what a request moves: the `RenderObserver`, the
`Renderer` currently rendering, the locale the request resolved to, and the
minted-id bag. Twig resolves it lazily through a runtime loader, so the
extension never has to know whether it exists yet.

The locale reaches the runtime as a **resolver**, not a value. A setter would
have to be called beside all three assignments to `Styleguide::$requestLocale`,
and the day a fourth appears without the pairing, the translators answer in the
previous locale — three tests failed on exactly that before the resolver went
in. `registerBundledExtensions()` already hands `TypographyExtension` its
locale the same way.

Tolerance is kept **per mode**, not dropped:

- **Library mode** keeps registering one name at a time through
  `tryAddFunction()`, now sourcing the definitions from the extension rather
  than from a 440-line method. Behaviour is unchanged; a host's own `__()`
  still wins.
- **Bundle mode** registers the class whole, where a name collision is a loud
  container error.

The bodies of the stateless helpers stay on `Styleguide` as named static
methods rather than moving into the extension. `tests/BundledHelpersTest.php`
reaches `Styleguide::classifyAspect()` by reflection and `|resizer` depends on
it, so relocating them would break a test that has to keep passing untouched in
order to prove the refactor changed nothing. Moving them is a later cosmetic
commit with no design risk.

## Consequences

A name collision behaves **differently in the two modes, on purpose**: silent
in library mode, fatal in bundle mode. That asymmetry is the price of keeping
the WordPress contract while giving Symfony a correct one, and it has to be
documented wherever the bundle is, or it reads as a regression.

Library mode keeps its silent failure on a closed environment. This ADR does
not fix that, and the fix is deliberately sequenced after it: turning it into a
constructor-time refusal would convert a limping install into a hard failure,
which is only fair once there is a documented remedy to point at. That remedy
is now in README § *If your environment is already initialised*.

`renderNamespaced()`, `invokeTwigFunction()` and
`Renderer::resolveStyleguideData()` widen from private to public `@internal` so
the runtime can reach them. Widening moves a method into PHPStan's stricter
rules for public API, which is worth knowing before doing it casually.

`Renderer` gains an optional fourth constructor argument. Built without it, it
keeps registering `styleguide_data()` itself, carrying the original defect —
a compatibility shim for direct `new Renderer($twig, $context)` callers, not an
endorsement.

The package now has one test representing a Symfony-shaped consumer. That is
the durable part of this decision: the class of bug was invisible because no
test ever put the package in the situation where it occurs.
