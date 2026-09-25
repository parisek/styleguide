# ADR-0005 — One mount path, from `bootstrap.base_url`

## Context

The catalogue lived at `/styleguide`, and that string was written into about
forty places: `Router::parse()`, the SPA config, the foundations asset URLs,
the standalone back-link in `render-cell.twig`, the Vue router's history base,
the API and locale fetches, the iframe sources, the theme cookie path, Vite's
`base`, the committed `dist/index.html`, the Symfony routes and
`StyleguideExtension::SUPPORTED_PREFIX`.

Two keys already claimed to configure it. `bootstrap.base_url` was parsed,
validated as a string, documented as "the prefix the router matches", and
reached nothing. The bundle's `styleguide.prefix` accepted only `/styleguide`
and refused anything else. A project that set either one got no error and no
effect, or an error that said the feature did not exist.

Projects want the catalogue elsewhere: under `/tools/ui` beside an
application, or at a path that does not collide with a CMS route. Hosts also
run under a base path of their own (`/subdir`, `/index.php`), which the
bundle's shell ignored: it requested its assets at the domain root.

Three shapes were considered for the unlock:

- **A `<base href>` element in the shell.** One line, and it changes the
  resolution of every relative URL in the shell document, router links
  included, not only the two entry assets that need it. Rejected.
- **A relative route resource imported with `->prefix()`.** Symfony-idiomatic,
  and a second route resource that the absolute one would then have to be
  deprecated against. Rejected.
- **One value, threaded everywhere, with the built SPA told at runtime.**
  Chosen.

## Decision

The mount path is **one value, `bootstrap.base_url` in `styleguide.yaml`**,
default `/styleguide`. It is project truth: it lives in the YAML beside the
rest of the catalogue's configuration, and in the PHP constructor array for a
programmatic consumer.

- **One normaliser, `MountPath::normalise()`** (`@internal`), used by the
  constructor, the Symfony extension and `doctor`. The canonical form has one
  leading slash and no trailing slash. It refuses `/` (the catalogue would claim
  every URL of the host), relative paths, empty interior segments (`/a//b`;
  trailing slashes are trimmed), dot segments, percent-encoding, non-ASCII,
  query and fragment. Refusal is an exception at
  boot or at container build, never a fallback.
- **Routing and URL production are separate.** `Router::parse()` matches the
  request path against the mount. Every URL the catalogue produces starts with
  the *public base*: the host's base path plus the mount. In library mode the
  host base is empty and `base_url` is the full public path; in the Symfony
  bridge the controller passes `Request::getBaseUrl()` through
  `Http\Request::$basePath`, and `base_url` is the path inside the application.
- **`dist/` carries no mount.** Vite builds with `base: './'`. The shell's two
  entry asset URLs are made absolute under the mount when the shell is served,
  because a relative URL in a document served at `/styleguide` (no trailing
  slash) resolves against `/`. The SPA reads `baseUrl` from `#sg-config` once
  and builds every URL through `frontend/src/lib/runtimeConfig.js`.
- **The Symfony routes read the mount from a container parameter**,
  `styleguide.base_url`, in their paths (`%styleguide.base_url%{slash}`). The
  host's route import does not change with the mount.
- **`styleguide.prefix` is transitional.** Default `null`; a given value must
  normalise to the YAML's mount or the container refuses to build, and it never
  overrides the YAML. It goes in the next major, with `SUPPORTED_PREFIX`.

## Consequences

- A project moves the catalogue with one YAML key, in every mode. The web
  server must route the new path; `doctor` reports a moved catalogue as a
  notice for that reason.
- `twig_context` values in the YAML (`homeUrl`, `frontPageUrl`) are project
  values and do **not** follow the mount. A project that moves the catalogue
  changes them too.
- A project that had already set a valid non-default `base_url` moved with
  1.22.0. `doctor` had reported that the key did nothing.
- `StyleguideKernel`'s cache key includes `styleguide.yaml`, because the mount
  is compiled into the routes and a production container never checks freshness.
- Guards: `MountPathTest` (the normaliser's contract), `MountPathThreadingTest`
  (routing, SPA config, shell assets, back-link and foundations assets follow
  one value), `BundleTest` (a moved mount, a `/subdir` host base, disagreeing
  and agreeing `prefix`), `tests/e2e/run.sh` (all Layer A checks at
  `/styleguide` and at `/tools/ui`), `tests/e2e/playwright/mount.spec.js` (the
  SPA in a browser at `/tools/ui`, including the cookie path), and
  `tests/e2e/front-controller.sh` (the shipped front controller at both).
- A new URL anywhere in PHP, Twig or the SPA must be built from the public base
  (`publicBase()` in `Styleguide`, `url()`/`assetUrl()` in the SPA). A literal
  `/styleguide` fails the `/tools/ui` e2e runs, not the default ones.

Delivered in #158, #159, #160, #161 (1.22.0); tracked in #157.
