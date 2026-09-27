# ADR-0006 — Fixture source is off unless the catalogue is gated

## Context

Since 1.24.0 the catalogue can serve template source. A "Code" toggle on each
variant tile shows the fixture that rendered it (`styleguide.twig` or
`styleguide.<variant>.twig`), fetched from `GET /api/source/<kind>/<slug>`.
Until then the package served rendered HTML only; the templates stayed on the
server.

Public catalogues exist. `Styleguide::run()` puts no gate in front of the
catalogue unless the host passes an `auth` callable, and README recommends a
web-server login as the alternative, which the package cannot see. A catalogue
reachable by anyone is a normal deployment: a client preview, a demo site.
Fixture source can carry what a rendered page hides: comments, internal
names, sample data, the structure of the project's own templates.

Symfony bundle hosts cannot set `auth`. The bundle builds the `Styleguide`
from `styleguide.yaml`, and the host's firewall guards the catalogue. The
Dráty z Porty platform is such a host, and it wants the toggle.

Three alternatives were considered:

- **An environment signal (`APP_ENV`, a debug flag).** Library mode has no
  reliable one: `APP_ENV` is a Symfony convention, WordPress and plain PHP
  hosts set other variables or none, and a production server left in debug
  would publish its templates. Rejected: a rule that depends on a variable
  the package does not own cannot be stated in one sentence.
- **On by default.** The toggle is useful everywhere, and most catalogues
  are internal. But one public catalogue that upgrades would start
  publishing its templates without any change on its side. Rejected: a
  minor release must not widen what a deployment exposes.
- **Off everywhere by default.** Safe, but it ignores the one signal the
  package does have: a host that passed `auth` has said the catalogue is
  private. Rejected as needlessly strict for those hosts.

## Decision

`show_source` in `styleguide.yaml` decides, by one rule:

- A boolean wins. `true` turns the source on, `false` turns it off.
- Absent (or `null`): on only when the `auth` constructor callable is set.
- Any other value (a string, a number): off. A typo fails closed.

When it is off, `/api/source/...` answers exactly like an unknown endpoint
(404), and `#sg-config` carries no `showSource` key. The source never reaches
the browser: the SPA hides the toggle, and the server refuses on its own,
whatever the SPA does.

The rule lives in one private method, `Styleguide::showSource()`, which both
the SPA payload and the API dispatch call.

## Consequences

- A catalogue that sets neither `show_source` nor `auth` behaves as before
  1.24.0: no toggle, no endpoint, the same `#sg-config` payload.
- **Symfony bundle hosts opt in with `show_source: true`** once their
  firewall guards the catalogue. So does a library-mode catalogue behind
  HTTP Basic Auth or a VPN. README says so under *Symfony bundle* and
  *Showing the fixture source*.
- The endpoint serves only the two fixture file names, looked up through the
  same `@project` loader the render uses, never a component's own
  `<slug>.twig`. Widening that set is a new decision under this ADR's rule.
- Turning the default on later, or deriving it from an environment signal,
  needs a new ADR that supersedes this one.
- Guard: `tests/Api/SourceEndpointTest.php` covers the default off, on
  behind `auth`, explicit `true`/`false`, a non-boolean value, and the 404
  when off.
