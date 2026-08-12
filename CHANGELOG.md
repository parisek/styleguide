# Changelog

All notable changes to `parisek/styleguide` are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases before [0.4.0] have moved to [`CHANGELOG-archive.md`](CHANGELOG-archive.md).

## [Unreleased]

### Fixed

- **BREAKING — `_nx()` no longer substitutes the number.** It now returns the
  selected plural form, exactly as WordPress's `_nx()` does, leaving the
  substitution to the caller (`sprintf`, or `|format` in Twig). It previously ran
  `sprintf($form, $number)` itself, so `_nx('%d day', '%d days', 5, …)` rendered
  `5 days` in the styleguide and `%d days` on the live site.

  That split is the one thing the `…t` helpers were introduced to prevent (#21:
  *"same signatures as the WP originals … so authoring stays portable across
  CMSes"*), and `_nx` was the only translator breaking it — `__`, `_x` and `_n`
  all mirrored WordPress already. Two sibling functions differing only by a
  `context` argument behaved differently on substitution, with no way for an
  author to notice: both outputs look plausible on their own.

  Fixed in all three places it appeared: the catalogue-backed `_nx`, the
  identity-stub `_nx`, and the fallback expression inside `_nxt` — which now
  matches `_nt`'s.

  **Migration.** A template relying on the old behaviour renders a literal `%d`
  after upgrading — but it already rendered `%d` on the production site, so the
  fix surfaces an existing defect rather than introducing one. Add `|format(n)`
  at the call site, which is what the same template needs under WordPress anyway.
  Templates written correctly for production stop double-substituting.

  Found in `sloneek`, wiring a client-side countdown whose plural form has to be
  chosen per tick in the browser: the `%d` the JavaScript substitutes survived in
  the styleguide but not on the site.

  The existing signature test could not catch this — its strings carry no
  placeholder, and `sprintf('many', 2) === 'many'`. The regression test uses `%d`
  and was confirmed to fail against the unfixed code.

## [1.14.0] - 2026-08-11

### Added

- **`maintenance:render --check`** reports whether the committed outage screen
  still matches the templates and translations it was rendered from. Exit `0`
  current, `1` stale. It writes nothing and needs no built stylesheet, so it
  runs in CI with no Node and no build step.

  The render now writes a fingerprint into the file (`<!-- @screen-fingerprint
  … -->` after `</html>`), computed over the maintenance component and page
  templates, the `.mo` catalogue for the rendered locale, the document shell,
  and `MaintenanceRenderer::RENDERER_VERSION` — the last so that a package
  upgrade which changes the shell cannot leave every committed screen looking
  current while rendering differently.

  **The compiled stylesheet is deliberately excluded.** A fingerprint over the
  output would go stale on every unrelated CSS change and make the check a
  chore every pull request pays. The trade: a design-token change that alters
  the screen's colour or type does not invalidate the fingerprint — re-render
  after touching tokens. A file rendered before this release carries no
  fingerprint and is reported stale rather than passing, because "cannot tell"
  and "fine" are different answers.

## [1.13.1] - 2026-08-11
### Fixed

- **`maintenance:render` left `@font-face` in the artefact on any Tailwind
  build.** The scanner that locates the at-rule honoured backslash escapes
  only inside strings, not outside them. CSS escapes identifiers too, and
  Tailwind leans on that: `content-[""]` compiles to the selector
  `.before\:content-\[\"\"\]`, whose quotes are escaped characters rather
  than delimiters. One of them opened a string that ran to the end of the
  file, so every `@font-face` after it went unseen — and shipped inside a
  document whose entire contract is that it requests nothing.

  Found by rendering a real project's stylesheet after 1.13.0 was released,
  not by the suite: every fixture was hand-written and none carried an
  escaped selector. The regression test uses the shape from that build.

## [1.13.0] - 2026-08-11
### Added

- **`styleguide lint` can accept expected findings, and says so out loud.**
  The linter had no ignore mechanism, and its exit code is the only thing a
  consumer's CI can gate on. Any project carrying a legitimately flagged
  template — a component rendered only inside one flow, so nothing demos it
  standalone — therefore exited `1` on a clean tree, forever. The gate stops distinguishing anything, so it
  gets dropped from CI or wrapped in a project-local filter script; both were
  observed downstream before this landed.

  Entries live in `<templates>/.styleguide-lintignore.yaml` (auto-discovered)
  or a file named by `--ignore=<path>`:

  ```yaml
  ignore:
    - file: component/legacy-embed/*
      rule: no-fixture
      reason: rendered only inside the checkout flow, nothing to demo standalone
  ```

  Four guards keep the list from becoming a place where checks go to die
  quietly: `reason` is required; an entry is always scoped to one rule, so
  there is no "mute this rule everywhere" that would also hide the next
  occurrence; an entry matching nothing reports itself as `stale-ignore` (and
  that finding cannot itself be suppressed); and every run announces
  `N finding(s) suppressed by the ignore list.` on STDERR, so a hidden finding
  never looks like an absent check. Both output formats are unchanged.

  A malformed or missing `--ignore` file exits `2` — a usage error, distinct
  from `1` ("findings present"). Silently ignoring nothing would recreate the
  problem one level up, and a typo would read as a lint regression in the
  templates rather than in the ignore file.

  `stale-ignore` is judged only against the types a run actually walked.
  `lint --type=component` never reads page or doc templates, so every page and
  doc entry matches nothing there — reporting those as stale would tell the
  reader to delete entries that are doing their job. An entry whose first path
  segment is itself a pattern can reach any type, so it is still judged: a
  false notice is untidy, but an entry nothing ever checks is how the list
  rots.

### Added

- **Offline outage render** — `styleguide maintenance:render` writes the
  project's maintenance screen to one self-contained HTML file
  (`<templates_path>/component/maintenance/maintenance.html` by default —
  beside the component it renders, so the committed artefact and its template
  share a listing) for a CMS drop-in to serve while the CMS is down. A fallback screen is needed exactly when the CMS
  cannot render one: WordPress reads `.maintenance` before plugins and theme
  load, and reaches `wp-content/db-error.php` with no database at all. The
  command takes its whole configuration from `styleguide.yaml` — `bootstrap:`
  for the paths and the catalogues, `iframe.css` for the stylesheet to inline
  — so nothing is restated on the command line. The stylesheet is inlined,
  every `@font-face` rule is stripped, and the shipped shell references no
  script and no font: the file must reach for nothing while the site is down.
  The project supplies `page/maintenance/maintenance.twig`; its absence fails
  the command rather than writing a document with an error banner in it. A
  project needing different markup ships its own
  `<templates_path>/maintenance-document.twig`. On any failure nothing is
  written, so a stale but working file survives. Also available without the
  CLI as `Parisek\Styleguide\MaintenanceRenderer`. See `docs/API.md`
  § Offline outage render. Refs portadesign/tailwind-base#569.
- **`Styleguide::renderTemplate()` / `hasTemplate()`** — the narrow public
  primitives behind offline renders: render an arbitrary template on the
  configured environment, and probe whether a template name resolves.
  `renderObserved()` cannot serve that case — it renders a catalogue entry and
  records a call trace, while an offline render needs neither.

- **Locale switching** — `translations_path` config key (constructor array and
  `bootstrap.translations_path` in `styleguide.yaml`) points at a directory of
  compiled `.mo` catalogues. When set, `__()`/`_x()`/`_n()`/`_nx()` become real
  gettext-backed translators (pure-PHP `.mo` reader, no new Composer
  dependency) instead of identity stubs — a consumer that pre-registers its
  own translator (e.g. WordPress's real `__()`) is unaffected, exactly as
  before. The render endpoint (`/styleguide/render/<kind>/<slug>`) accepts an
  additive `?locale=<code>` query param selecting the catalogue for that one
  render — content strings AND `<html lang>`/`langcode`, one switch for both;
  absent → `default_locale`, i.e. unchanged behaviour. A bare two-letter code
  resolves to the one discovered catalogue whose filename starts with it;
  ambiguity (`pt` matching both `pt_BR.mo` and `pt_PT.mo`) is a `400` naming
  the conflict rather than a silent pick. The SPA's existing chrome language
  switcher now also drives the iframe's `?locale=`, so machine consumers of
  the render URL (visual-regression harvesters, screenshot scripts) see the
  same locale a human toggling the switcher sees. **Cache consequence:** a
  render URL now returns different content per `?locale=` — any cache in
  front of the styleguide must include `locale` in its key, same as
  `?theme=`/`?variant=`. On the SPA side, the switcher's choice now persists
  per-visitor across page loads via the chrome switcher's own `sg-locale`
  localStorage key — one visitor choice drives both the chrome strings and
  the rendered content, and a value written under the earlier
  `styleguide:locale` key is migrated on first read. Precedence is the SPA's
  own `?locale=` query param, then the stored value, then
  `bootstrap.default_locale`; a stale stored value (catalogue
  renamed/removed) falls back to the YAML default and clears itself. The
  stored value is mirrored in a module-level ref, so a switcher click updates
  the preview immediately instead of waiting for the next navigation. This
  layer is entirely client-side — the server never reads localStorage, so a
  direct render request with no `?locale=` always resolves to
  `default_locale` alone, keeping `visual:harvest`/`visual:compare` captures
  reproducible. An empty `msgstr` — gettext's own encoding of "not
  translated", which every compiler emits for a skipped string — falls back
  to the source string rather than rendering as nothing; the catalogue audit
  (`entries()`) still reports missing and empty separately, so the
  translation gap stays visible without the label disappearing from the page
  while it is open. See `docs/API.md` § Locale switching and the design doc
  referenced there.

## [1.12.0] - 2026-08-09

### Added

- **`Styleguide::fromYaml(string $path, array $overrides = []): self`** — loads
  project config from `styleguide.yaml`'s new `bootstrap:` section instead of a
  hand-written PHP array, so `static/index.php` and any other consumer that
  renders the same project (e.g. a CLI fixture-coverage audit) share one
  declaration instead of each restating it. Layered on top of the array
  constructor, which is unchanged — `Styleguide::__construct()` still takes the
  same config array it always has, and `fromYaml()` just builds one and hands
  it over.

  `$overrides` is the run-truth boundary: what's true about the *project*
  (paths, locale, routes) belongs in the YAML; what's true only about *this
  run* (`templateUrl`, a pre-built `twig` environment, `auth`, …) can only
  arrive via `$overrides`. The boundary is enforced, not just documented:
  writing a run-truth key into `bootstrap:` (the exhaustive list is in
  `docs/API.md` § YAML schemas → `bootstrap:` — **Forbidden keys**, not
  repeated here) throws `\InvalidArgumentException` naming the key, rather
  than the key being silently dropped or (in `templateUrl`'s case) silently
  honoured. A genuinely unrecognised key is still tolerated,
  for forward compatibility with a later schema — only this fixed,
  known-forbidden set is refused. `twig_context` is merged key-by-key rather
  than replaced wholesale, so an override supplying only `templateUrl`
  doesn't discard the YAML's own `homeUrl`/`frontPageUrl`/`langcode`.
  Relative `bootstrap.*` paths resolve against the YAML file's own directory,
  not the caller's `__DIR__` or cwd — the same file produces the same absolute
  paths whether read over HTTP or from a CLI script invoked from anywhere.
  A present-but-wrongly-typed optional key (`base_url: []`, `twig_context:
  bad`, a non-string `namespaces` entry) also throws, naming the key,
  instead of being coerced or quietly falling back to the constructor default.

  `bootstrap:` is a new, standalone top-level YAML key, deliberately kept
  outside every section `sync-styleguide` already regenerates — an unaware
  generator that round-trips unknown keys (as it already does for `project:`)
  cannot destroy it. See `docs/API.md` § YAML schemas → `bootstrap:` for the
  full key reference and the generator-safety note, and consult
  parisek/styleguide#119 for the design history.

  **Consumer action required:** existing projects keep working unchanged with
  the array constructor. Adopting `fromYaml()` means adding a `bootstrap:`
  section to `styleguide.yaml` (by hand, or by teaching `sync-styleguide` to
  populate it from `static/index.php`) and switching the entry point to call
  `Styleguide::fromYaml()` instead of `new Styleguide([...])`.

## [1.11.0] - 2026-08-09

### Added

- **`Styleguide::renderObserved(kind, slug, variant?)`** — renders a fixture and
  returns the HTML together with the `component_*` calls it produced. Each call
  carries the component name, the argument hash **as passed** (unflattened,
  uninterpreted), the originating fixture, and its position in the call structure
  (`direct`/`nested`, plus the parent). A consumer previously had to reach into
  `Renderer`'s private state by reflection and win a Twig function-name
  registration race to learn any of this.

  The trace is a return value rather than a hook, deliberately: a hook leaves it
  a side effect of correct wiring, and a consumer that wires it wrong gets an
  empty trace — indistinguishable from a clean project.

  Arguments are raw on purpose. While calibrating the first consumer, the
  semantics of flattening them changed four times; each was a one-file edit
  there rather than a release here plus a bump in two projects. The package
  reports what happened; what counts as a defect belongs to rules that move
  faster than a package can.

  The result also **declares calls it cannot observe**:
  `{% include '@component/x/x.twig' %}` bypasses recording entirely, because
  `component_*` is a function that can be wrapped while `include` is a tag
  compiled into the template class. Capturing those is out of scope; declaring
  them is not — a blind spot the tool announces costs one line of output, one it
  does not know it has produces a confidently wrong report.

  Observation **refuses** when a consumer-supplied environment already registered
  `component_*`/`page_*`, naming the collision and the remedy. `run()`'s HTTP
  path keeps tolerating those registrations exactly as before; only observation
  refuses, because only observation is wrong without them.

- **`Styleguide::inventory()`** — components, pages, docs and their variants in a
  stable order. Consumers that glob for fixtures themselves restate naming rules
  this package owns (`styleguide.<variant>.twig`, the underscore segment that is
  never a variant), which works until the package grows another shape and then
  silently skips fixtures nobody knows exist.

- **`Styleguide::componentDirectories()`** and
  `ComponentParser::listDirectories()` — component identities with a
  `hasTemplate` flag, independent of whether a fixture renders them. `inventory()`
  lists only what renders, so a component with no `styleguide*.twig` never
  appears in it — which is exactly the "never rendered" case a coverage check
  exists to catch. Reported by the first real consumer; one call answers both
  "what exists" and "does it have a template", so nothing has to glob.

### Fixed

- **`Renderer` now restores the outer fixture context** instead of resetting it
  to `null` when an inner render finishes. Ordinary exceptions already unwound
  correctly; re-entrancy did not.

- **`ComponentParser::parseAll()` has a total order.** Weight and display name
  left equal pairs to filesystem traversal order, and `Collator` availability
  changed the comparison itself, so a caller asserting determinism could not.
  Ties now break on id. This can reorder previously-ambiguous pairs.

## [1.10.2] - 2026-08-06

### Fixed

- **`merge_resizer()` now reports the argument it silently annihilates.** A
  non-final argument keeps only its media-queried variants, and the last tuple
  of a `|resizer` call never gets a `media` (it is the unconditional `<img>`
  fallback). Both rules are correct alone; together they mean a **single-tuple**
  non-final argument contributes nothing and is dropped whole:

  ```twig
  merge_resizer(
    content.image|resizer(['900', '', '', 'center']),        {# dropped entirely #}
    content.image_mobile|resizer(['1439', '', '', 'center']),
  )
  ```

  Nothing threw, nothing logged — the desktop layer just disappeared and the
  mobile image served every breakpoint up to 1920px wide. Downstream this reads
  as a CSS sizing bug, so the search starts nowhere near the template at fault.
  Such an argument is now reported via `error_log()` by position, with the fix
  (give it at least two tuples, maxWidth on the non-final ones).

  The returned candidate list is **unchanged** — this adds a diagnostic only
  (`error_log()` is itself a new side effect during rendering; the markup is not).
  Legitimate quiet cases stay quiet: `null` arguments, empty-array arguments,
  well-formed multi-tuple calls, and a non-final argument that keeps its
  media-queried variants while losing its fallback — that last one is the
  function's documented contract, not a mistake.

  The argument is named by its position in the **original** call. Arguments are
  re-indexed by the null-dropping filter before the check runs, so a naive count
  would report `merge_resizer($a, null, $bad, $fallback)`'s third argument as the
  second.

### Documentation

- `docs/API.md` described `merge_resizer` as `merge_resizer(image, mode, …tuples)`
  and typed it a *filter*. It is a variadic *function* taking one `|resizer`
  output per viewport layer, and it has no `mode` parameter. Corrected, and given
  its own § contract section covering the positional asymmetry, the
  at-least-two-tuples rule, and worked ❌/✅ calls.

## [1.10.1] - 2026-08-05

### Fixed

- **A `render: chrome` component could grow its preview until the browser died.**
  Such a component declares `body { min-height: 200vh }` so sticky/fixed chrome
  has scroll-room, while both preview surfaces sized their iframe from the
  measured content height. That defines the two heights in terms of each other,
  and the loop ran until the element hit the browser's 2^25px clamp:

  ```
  variant grid:   620 x 33,554,400 px per tile   (83,215 megapixels over four tiles)
  single preview: 820 x 33,554,400 px            (Custom-width mode)
  ```

  The renderer process died — downstream this was reported simply as "the browser
  crashes on this page". Only the paths with no canonical preset height were
  affected (Full, and fixed-width/Custom-width presets); a preset carrying its own
  height never consulted the measurement and never diverged.

  Both surfaces now pin such an entry to a fixed viewport height and let the
  iframe scroll internally, which breaks the cycle at its source rather than
  damping it. `min-height: 200vh` is unchanged: once the viewport is fixed, `vh`
  is well-defined, so the scroll-room stays — and the variants now demo more
  honestly than before the bug existed, since previously nothing scrolled inside
  a tile at all.

  Also added a defensive ceiling on any measured content height. It is not what
  fixes the loop; it means a future feedback path degrades into a conspicuously
  tall pane someone reports rather than a dead tab. Exceeding it truncates
  nothing — the iframe keeps its whole document and scrolls.

## [1.10.0] - 2026-08-05

### Changed

- **`|typography` now typesets per language.** The bundled `TypographyExtension`
  is registered with a locale resolver built from `default_locale`, so
  `|typography` applies the resolved language's quote/dash/spacing conventions
  (via `parisek/twig-typography` >= 1.3's per-language layer) instead of only
  the house policy. No config changes needed — `default_locale` already drove
  `<html lang>` and the `langcode` value handed into every render's Twig
  context, and now does the same job for typography. Bumped the
  `parisek/twig-typography` floor to `^1.3` — the
  first version whose constructor accepts a resolver; older 1.x versions
  silently ignore the extra constructor argument (PHP allows excess
  arguments to user-defined constructors) rather than erroring, so without
  the floor bump a project could sit on an old 1.x with no per-language
  typesetting and no signal that anything was missing.

## [1.9.0] - 2026-08-05
### Added

- **`styleguide_data()` can read another fixture's sidecar.** Its argument is now
  a path whose segment count selects the shape:

  ```twig
  styleguide_data()                          {# own default set — unchanged #}
  styleguide_data('gallery')                 {# own named set — unchanged #}
  styleguide_data('component/header')        {# another fixture's default set #}
  styleguide_data('component/header/dark')   {# another fixture's named set #}
  ```

  Because `/` is illegal inside an id or a set name, a one-segment reference is
  unambiguously a set name in the current directory — an existing call that
  resolved before still resolves to the same file. See `### Changed` for the two
  inputs whose handling did move.

  This supersedes the previous "no cross-component lookup" rule. That rule left
  fixtures with only one way to share demo data — an `{% include %}` data
  partial — which cannot export variables to its caller and therefore
  accumulates every consumer's data in one file; a downstream project's had
  reached 1147 lines while the component owning that data held none of it.

  Validation is whitelist-only (closed kind set, `^[a-z0-9-]+$` per segment with
  the `D` modifier, plus a `PathGuard` containment check), so no separator,
  traversal segment, trailing newline or symlink escape reaches the resolved
  path.

### Changed

- **`styleguide_data()` rejects a `/`-containing argument as an invalid
  *reference* or *kind* rather than an invalid *set name*.** Still a
  `RuntimeException` for anything that stays refused; only the message differs.
  Tests matching on the old wording need updating.
- **A set name with a trailing newline no longer resolves.** PCRE's default `$`
  matches before a final newline, so `styleguide.data-gallery\n.yaml` was
  reachable; the `D` modifier closes that. A deliberate narrowing — the
  documentation already claimed the stricter rule.

## [1.8.3] - 2026-08-04
### Fixed

- **`styleguide lint` no longer reports templates inside underscore-prefixed
  directories as `unindexed`.** `_partials/` is the near-universal convention for
  "included by something else, not an entry in its own right" — the same meaning
  Sass gives `_file.scss` and Jekyll `_includes/`. The suppression covers any
  metadata without a `name:` key: an absent comment and a mapping that simply
  lacks the key alike.
  Such a template has no `name:` because it was never supposed to have one, so
  the catalogue excluded it correctly and the linter then reported that correct
  exclusion as a **warning**, asking the author to fix a file behaving exactly as
  intended. Found on a downstream project where the only two warnings in the
  whole report were its shared header and footer partials.

  **Scoped to the missing-name case only, and deliberately not to the walk.** A
  first attempt skipped underscore directories in `ComponentParser::parseAll()`
  as well, for symmetry. Review caught that this silently removes a partial that
  *does* carry a `name:` from the catalogue and from `/api/components` — a
  runtime behaviour change dressed as a lint fix, and not a patch-level one. Such
  a component is still catalogued and still linted by every other rule; only the
  `unindexed` finding is suppressed, and only where there is no name to index by.

  Directory rule, not a filename one: `_foo.twig` beside real components is not
  an established convention here, and dropping a file over a leading underscore
  in its own name would be a surprise.

## [1.8.2] - 2026-07-30
### Fixed

- **The iframe's `<body>` safety-net background no longer defeats
  `iframe.body_class`.** The rule was unlayered, so it beat any consumer
  `bg-*` utility regardless of specificity — the class landed on `<body>` in
  the DOM while the computed colour stayed the net's, which made the symptom
  hard to trace. Moved into `@layer base`: the net still paints when nothing
  opposes it, a consumer utility now wins, and a consumer's own unlayered
  `body {}` keeps winning — the behaviour the surrounding comment already
  promised. Surfaced downstream as a styleguide footer rendering on white
  while page content was cream.

## [1.8.1] - 2026-07-30
### Added

- **`GET /styleguide/api/health` declares what it actually checked** —
  `"checked": "metadata"`. Additive; existing readers of `warnings`/`counts`
  are unaffected.

  The endpoint is called *health* but only parses metadata, and a template
  whose **body** has a fatal Twig error still parses its metadata fine. It is
  therefore counted as a healthy component while every render of it fails.
  Downstream, `warnings: []` alongside a full component count was read as "the
  catalogue is fine" for days while eleven templates rendered nothing.

  **No compile check was added, not even behind a flag.** Since 1.8.0 a broken
  template makes `/render/component/<id>` return 500 with the real Twig error,
  so sweeping the render endpoint IS the check — and it is strictly stronger
  than compiling, because it also catches a missing partial, a runtime failure
  and the "template not found" alert fallback. A weaker duplicate behind an
  opt-in flag would just be a diagnostic nobody switches on. README gained the
  nine-line sweep as § *CI smoke test* (~9 s on a 66-component project, no
  browser, no build).


## [1.8.0] - 2026-07-30
### Added

- **`styleguide lint` reports catalogue entries that nothing renders
  (`no-fixture`).** A component with no `styleguide.twig`, no variant sibling
  and no `styleguide:` key parses perfectly — so every existing metadata rule
  passes it — and then shows an empty frame in the sidebar. No preview, no
  visual baseline and no behavioural test can reach it, and nothing anywhere
  said so. Found nine such entries in one downstream project, one of them a
  page.

  Fixture detection follows the runtime exactly: `isset()` on the `styleguide:`
  key (a bare `styleguide:` with no value is not a fixture there, and
  `array_key_exists()` would have silently exempted the component), and the
  strict canonical variant-filename pattern rather than the looser one the
  catalogue walk uses to *exclude* fixtures — `styleguide.WIDE.twig` is
  excluded from the walk but is not a canonical variant, so that component
  really does render nothing.

  Notice, not warning, and `kind: utility` is exempt outright: a utility
  renders whatever it is handed, so it has no stable appearance a demo page
  could pin (its fixture-less state is correct, not a gap). Structural partials
  a project keeps as catalogue entries are a judgement call rather than a
  defect, so the rule informs and never fails a build — a rule that broke CI
  over either would be the first one consumers switch off.

### Fixed

- **`styleguide lint` reads a component's `<id>.yaml` when it has one, like the
  runtime does.** `Cli\Linter` called `parseTwigComment()` directly, while
  `ComponentParser` resolves metadata through `readComponentMetadata()`, where a
  sibling `<id>.yaml` wins over the twig front-comment.

  That is not a subtle inconsistency once a project starts migrating. ADR 0007
  retires the front-comment **per component** as its `<id>.yaml` lands — so from
  the first migrated component onward, the two sources disagree by design, and
  the twig file's leading `{# … #}` is just an ordinary code comment. The linter
  parsed it as YAML anyway. Downstream, completing the migration for 29
  components produced 29 phantom `metadata-yaml-invalid` errors, each one a
  correct code comment being read as a broken metadata block, on components the
  catalogue was serving perfectly.

  The linter now goes through `readComponentMetadata()` (made `@internal`-public
  for this), so every rule sees the same document the catalogue does, and
  findings are attributed to the file the metadata actually came from —
  pointing an author at `<id>.twig` for a value authored in `<id>.yaml` sends
  them to edit a document the catalogue ignores.

  Two new rules cover the blind spots that adopting the runtime's precedence
  would otherwise have created:

  - **`sidecar-yaml-invalid`** (error). `readComponentMetadata()` swallows a
    malformed `<id>.yaml` and falls back to the twig comment. That is right for
    the renderer — one bad file must not blank a component — but it means the
    *canonical* document can be broken while the component renders perfectly
    and nothing says so. Reported on the `.yaml`.
  - **`redundant-twig-metadata`** (warning). Both documents present and the
    sidecar winning means the twig front-comment is dead: editing it changes
    nothing, silently. ADR 0007 says the template keeps only its render code
    once the sidecar lands. Downstream this state had already produced three
    components whose corrected descriptions lived in the dead block and had
    never been visible. Reported on the `.twig` — that is the file to edit.

  An ordinary leading code comment does not trigger the second rule: the check
  requires the comment to parse as a YAML mapping carrying `name:`. Reporting
  prose about the markup would be the mirror of the bug being fixed.

- **A broken component template no longer reports itself as a missing one.**
  `component_*()` / `page_*()` wrapped both the template load AND the render in
  `catch (\Throwable)`, so every failure produced the same output: the alert
  component saying *"template not found"*, returned normally — HTTP 200.

  A template with a fatal Twig syntax error was therefore indistinguishable
  from a file that is not there. The real parser message went only to
  `error_log`, and `Renderer::render()`'s 500 path — added in 1.6.0 precisely
  so that *"a health check or CI smoke test polling `/render/component/<id>`"*
  cannot see success for a broken component — could never fire, because the
  throw was swallowed one layer below it.

  Downstream (portadesign/tailwind-base, 2026-07) eleven templates shipped that
  way for days. HTTP 200, no console error, no failed request, and the
  components stayed in the catalogue (discovery reads only the leading `{# #}`
  block and never tokenizes the body) — so their behavioural tests ran against
  the alert fallback and passed. Every automated check was green while the
  pages rendered none of the components they named.

  Now only a `LoaderError` — the file genuinely is not there — falls back to the
  alert. A `SyntaxError` (exists, does not compile) and any throw from the
  render itself propagate to `Renderer::render()`, which turns them into HTTP
  500 with the real message. The `render()` call moved outside the `try` as
  well: a `LoaderError` raised by a nested `{% include %}` is a failure of the
  template being rendered, not evidence that that template is missing, and must
  not be relabelled as one.

  A missing `{% include %}` target or `{% extends %}` parent is resolved at
  render time, so it used to be caught here too and reported as *the including
  template* being missing — pointing the author at a file that is right there
  while the genuinely absent one went unnamed. That is why `render()` moved out
  of the `try`, not merely why the catch narrowed.

  **Behaviour change — read before upgrading.** This is a bug fix, but it is
  not a silent one, and the blast radius is wider than the styleguide:

  - A page fixture containing a broken component now returns **500 for the
    whole render**, instead of 200 with one alert box among healthy siblings.
  - `component_*()` / `page_*()` are project Twig helpers. Wherever a consumer
    calls them **outside** the styleguide sandbox — a real page template in
    production — a component that was quietly rendering an alert box will now
    raise instead.

  The upgrade therefore turns any *already broken, not yet noticed* component
  into a visible failure. Since not noticing is precisely the bug being fixed,
  assume such components exist rather than assuming they do not: run
  `vendor/bin/styleguide lint --templates=<path>` and one render pass over the
  catalogue before rolling this out, not after.

  The message shown is now the actual Twig error rather than "not found", and
  both new failure paths `error_log()` before rethrowing — a consumer whose CMS
  or proxy swallows the 500 body would otherwise have no server-side trace at
  all, which would be strictly less diagnosable than the behaviour this
  replaces.

  **If a consumer genuinely wants the old resilient behaviour**, the escape
  hatch already exists and is unchanged: register your own `component_*` /
  `page_*` function on the Twig environment before handing it to the package.
  `registerBundledHelpers()` never overwrites a name the project already
  registered, so your implementation wins. No new configuration flag is
  introduced for this — a switch that restores "broken renders as 200" would
  reintroduce the failure mode by name.

## [1.7.2] - 2026-07-28
### Fixed

- **A field with no `label` is named by its key instead of being dropped, when
  its role says no editor fills it.** definition-kit 0.6 made `label` required
  only of a field that projects into `acf.json` — a `role: parent`, `query`,
  `global`, `inherited` or `derived` prop has no editor to write copy for, and
  inventing one was the noise that change removed.

  `FieldsNormalizer` still required it, so every such prop was skipped with a
  warning and vanished from the props table. On eprukaz2025 that was **115
  fields across 32 components**, all of them correctly declared — the
  documentation got worse the moment the theme documented itself properly.

  The props table is developer-facing, so the key is a perfectly good name for
  one. The role vocabulary is closed — `query`, `global`, `parent`, `inherited`,
  `derived` — so a typo, a role removed upstream (`computed`), an empty string
  or a non-string leaves the entry malformed and skipped exactly as before. A
  field that projects — no `role`, or `role: field` — still needs its editor
  label and is still reported without one.

## [1.7.1] - 2026-07-22

### Added

- **`is-styleguide-render` on `<html>` for every component and page render.** A
  component's JS sometimes has to know it is being previewed rather than served
  live — to skip page-load choreography a screenshot would otherwise catch
  mid-flight, or to lift a production-only guard. The renderer is the only layer
  that knows this for a fact, so consumers should not have to derive it.
  Without the class the reachable signal is `location.pathname`, and two real
  components in a downstream project ended up testing
  `startsWith('/styleguide/render/')` — which couples them to this package's
  ROUTING and would break silently the day a route moves, with no failing test
  to say so.

  Emitted unconditionally, with no `iframe` config required, and it composes
  with `iframe.html_class` / the `dark` theme class rather than replacing them.
  Consumers on an older version can seed the same class through
  `iframe.html_class`, so the check reads identically either way and needs no
  version branch.

  Two worked cases from the project that prompted this: a hero whose reveal is a
  1000 ms `setTimeout` plus a 0.5 s transition (the suite shoots ~1.5 s earlier
  and captured a blank frame), and `vanilla-cookieconsent`, whose default
  `hideFromBots` matches `navigator.webdriver` and therefore hides from every
  automated browser — correct in production, a blank baseline in a preview.

  The 404 render is deliberately untouched: it is the package's own error page,
  not a consumer's component, and nothing of theirs runs inside it.

## [1.7.0] - 2026-07-22

### Added

- **`kind:` YAML metadata key on the component/page front-comment.** New closed
  enum (`block | section | element | part | utility`) declaring what a
  component *is* — authorial intent, never derived — surfaced on
  `/api/components` and `/api/pages` alongside `render`. `ComponentParser`
  gains `KIND_VALUES` and `normaliseKind()`, mirroring `RENDER_MODES` /
  `normaliseRender()`, except an absent or unrecognised value normalises to
  `''` rather than a guessed default — see
  `docs/adr/0012-component-kind-taxonomy.md` in `tailwind-base` for the
  taxonomy rationale. `normaliseMetadata()`'s previously-fixed whitelist array
  now includes `kind`.
- **`unknown-kind` lint rule** (Error). `normaliseKind()` swallows an
  unrecognised value into `''` with no other signal, so `vendor/bin/styleguide
  lint` now reports it — parity with the `unknown-render` rule that exists for
  exactly the same reason. Without it a typo like `kind: sectoin` reaches
  `/api/components` silently for any consumer not also running
  `parisek/definition-kit`, which is optional.

## [1.6.2] - 2026-07-21

### Fixed

- **Manifest icons audited as `missing` on WordPress / Drupal consumers.** A
  `site.webmanifest` is fetched and parsed by the *browser*, so its icon `src`
  values must be real URLs — under a theme that means
  `/wp-content/themes/<theme>/static/images/touch/icon-192.png`. `FaviconAudit`
  joined those onto `static_path` anyway, looking for
  `<static_path>/wp-content/themes/<theme>/static/images/…` — a path that exists
  nowhere — so every manifest icon reported `missing` with the file sitting right
  there on disk (and `maskable_icon` never resolved). Consumer paths are now
  stripped back to static-root-relative before any disk read via the new
  `PathGuard::stripAssetBase()`, the inverse of `Renderer::resolveAssetUrl()`.
  Same treatment for the `favicon:` / `og_image:` yaml keys, so a project that
  worked around the sibling `<img src>` bug by hardcoding the full theme path
  stops reporting false `missing` rows too. The echoed `path` stays exactly as
  authored, and browser-facing values keep the URL the manifest actually serves.
  `FaviconAudit::run()` / `OgImageAudit::run()` take the asset base as a new
  optional trailing argument (default `''` — standalone behaviour unchanged).

## [1.6.1] - 2026-07-21

### Fixed

- **Foundations favicon / OG-image previews 404'd on WordPress and Drupal consumers.**
  `FaviconAudit` / `OgImageAudit` echo the docroot-agnostic `styleguide.yaml` path
  (`/images/touch/favicon.svg`) back out, and `foundations.twig` rendered it straight
  into the browser-tab / iOS / share-card mockup `<img src>`s — so on any consumer
  whose static dir isn't the docroot (WordPress: `/wp-content/themes/<theme>/static`)
  every mockup showed a broken image. The audits' browser-facing values are now rebased
  onto `twig_context.templateUrl` via `Renderer::resolveAssetUrl()`, the same treatment
  `iframe.css` / `project.favicon` / `styleguide.logo[*].src` already get.
  `favicon_audit.entries[*].path` and `og_image_audit.path` are deliberately left raw —
  the audit table prints them as a `<code>` echo of what the author wrote. The rebased
  OG-image value lands in a new `og_image_audit.url` twin (additive; `path` unchanged).
  Standalone consumers (empty `templateUrl`) render byte-for-byte as before.

## [1.6.0] - 2026-07-16

### Added

- **Fields overview + canonical fields API (#95, ADR-0002).** Both field-definition doctrines
  (legacy twig-annotation `title`, definition-kit sibling `<id>.yaml` `label`) normalise
  server-side into one canonical, ordered fields list; every other authored key (`mcp`, `wp:`,
  `visible_when`, constraints, …) passes through the API verbatim — open contract, see
  `docs/API.md` § Fields. New sidebar entry **Fields** (`/fields`, shown when any component
  declares fields via `catalog.hasFields`): global searchable overview — filterable by field
  key/label/type or component name — with click-through to the component
  (`/component/<id>?fields=1` opens the drawer). `FieldsDrawer`/`FieldsTable` render labels from
  both doctrines and expand per-row verbatim detail. Malformed field entries are skipped rather
  than failing the whole component, surfacing instead as a warning via `GET /styleguide/api/health`.

### Changed

- **`/api/components` + `/api/fields`: `fields` is now the canonical list** (was: raw YAML map
  pass-through), same on `/api/pages`/`/api/docs` — the same `Field[]` shape
  (`{ key, label, type, description, required, children, ...verbatim }`) via the new
  `FieldsNormalizer` (ADR-0002). Consumers reading the raw map shape must switch to the
  documented `Field` type; unknown authored keys still pass through verbatim. See `docs/API.md`
  § Fields canonicalisation.

## [1.5.1] - 2026-07-16

### Fixed

- **Browser-tab favicon regression since the Vue rewrite.** `<link rel="icon" id="sg-favicon-tag">`
  shipped with an empty `href` on every SPA page: the server-side tag patch was removed when
  `dispatchSpa()` switched to the single `#sg-config` injection, but no SPA code ever consumed
  `config.favicon` for the link (only the sidebar `<img>` had a consumer, which is why it went
  unnoticed). Document-level config consumption (favicon link, `data-default-locale`, title seed)
  is now consolidated in `frontend/src/lib/documentChrome.js`, an unconfigured favicon falls back
  to the generic glyph, and a Playwright assert guards the producer→consumer wiring end to end.

## [1.5.0] - 2026-07-15

### Added

- **Prefer a sibling `<id>.yaml` component definition over the twig annotation (#91).**
  Transitional support for tailwind-base's incoming per-component canonical definition file
  (`<id>.yaml`, sibling of `<id>.twig`). `ComponentParser` now checks for it first via a new
  `readComponentMetadata()` helper shared by `parse()` and `parseAll()`; a malformed or missing
  `<id>.yaml` falls back to the existing `{# … #}` twig-comment parsing, unchanged. Purely
  opt-in and fallback-safe: a project with no `<id>.yaml` files renders exactly as before.

### Changed

- **HealthWarningBadge opens a native `<dialog>` (#89).** Clicking the parser-warnings badge
  now lists every warning (file + message) in a modal — the previous console.warn-only click
  read as a dead button. Esc/backdrop/close-button dismissal comes from the native element;
  `console.warn` stays as a debugging side channel. New `health.dialog_title`/`dialog_close`
  i18n keys (cs/en); `health.warnings_title` no longer mentions the console.

### Fixed

- **Sidebar respects authored `weight:` order (#92).** `buildTree` (prefix grouping) re-sorted
  every section alphabetically client-side, silently discarding the server's weight-sorted
  order — a `weight: 1` homepage rendered after "404". Nodes now keep the incoming API order
  (weight, cs-collation name tiebreak); groups sit where their first member appears and group
  children keep authored order too. Default-weight sections look unchanged — the server
  tiebreak already produced the alphabetical order.

## [1.4.0] - 2026-07-15

### Added

- **Standalone icon catalog page (#87).** New optional `icons:` yaml block (groups of
  `ico-*.svg` entries — generated by the consumer's sync tooling, see
  portadesign/tailwind-base#272) gets its own first-level DOKUMENTACE page at
  `/styleguide/icons` (render endpoint `/styleguide/render/icons/index`): inline SVG tiles
  grouped per category, icon name + optional human label per tile. Markup is prepared
  server-side by the new `IconsCatalog` — the consumer-side `{{ attribute }}` Twig
  placeholder is stripped, legacy fixed `width`/`height` roots are normalized to a
  synthesized `viewBox`, script vectors (`<script>`, `<foreignObject>`, `on*` handlers,
  `javascript:`/`data:` hrefs) are removed, and every file read goes through the shared
  `PathGuard` containment. `multi`-color icons (brand marks with own fills) are badged
  instead of tinted; missing/unreadable files render a loud dashed missing-state tile.
  The sidebar entry is gated on the new sg-config `hasIcons` flag, so a project without
  an `icons:` block gets no dead menu item and renders unchanged.

## [1.3.0] - 2026-07-12

### Added

- **Flexible color model (#71).** `colors:` palettes accept a free `swatches:` list
  (named colors, optional per-swatch `css_variable`) alongside the existing Tailwind-style
  `shades:` map — projects without a 50–950 scale finally render a sane overview. Both
  shapes normalize through the new `ColorPalettes`/`ColorUtil` layer; swatch text color
  (light/dark) is now computed from the hex via WCAG relative luminance instead of the
  hardcoded shade-name list, so dark 100s and light 700s stop guessing wrong. Foundations
  markup adapts to small palettes (capped swatch tiles, wrapping strip, truncating labels).
  All swatch data is `html_attr`-escaped into the Alpine attributes in `foundations.twig`
  (autoescape is off in this package), so free-form swatch names carrying apostrophes or
  quotes render safely. OKLCH values are computed from the hex when the yaml omits them and
  are the preferred lightness basis.

- **Contrast / a11y layer (#72).** Every swatch on the overview now grades white/black
  text against WCAG AA (4.5:1) — dot badges on the tile, exact ratios in the tooltip and
  hero — and an expandable matrix grades every color × color pair (all palettes + white +
  black) with AAA / AA / AA-large / fail verdicts. Computed server-side from the hex via
  the #71 normalization layer; zero yaml changes needed (new `labels.contrast_matrix*`
  keys are optional with English defaults).

- **Favicon audit section (#73).** Foundations gains a `#favicon` section: the favicon
  rendered in simulated contexts (browser tab light/dark, iOS home-screen icon, Android/PWA
  maskable icon) plus a server-side audit checklist — file existence, real pixel dimensions
  vs. expectations (`png_96` 96×96, `apple_touch` 180×180), parsed ICO contents, manifest
  JSON validity with per-icon existence checks, and the `theme_color` swatch. Audited by the
  new `FaviconAudit` class, which runs entirely server-side against `static_path`; every
  configured path (and every manifest icon `src`) is containment-checked to stay under
  `static_path` before any filesystem read. Checklist labels are overridable via optional
  `labels.favicon*` keys, all with English defaults — no yaml changes are required beyond
  the existing `favicon:` block.

- **OG image audit section (#74).** Foundations gains an `#og-image` section: the optional
  `og_image:` yaml key renders as share-card mockups — Facebook/LinkedIn (1.91:1 crop),
  X/Twitter summary_large_image (2:1 crop), and a Slack unfurl — plus a compact server-side
  audit checklist covering existence, real pixel dimensions vs. the ≥ 1200×630 recommendation,
  aspect ratio vs. the 1.91:1 convention, and file size against platform limits (warn > 1 MB,
  error > 8 MB). Audited by the new `OgImageAudit` class, which shares the containment-hardened
  `PathGuard` helper with `FaviconAudit` (#73) rather than duplicating its scheme-URI rejection
  and realpath-under-`static_path` checks. The section always renders — an empty-state prompt
  covers the unconfigured case, since an OG image is expected on every project. Checklist
  labels are overridable via optional `labels.og_*` keys, all with English defaults.

### Changed

- **Foundations interactivity no longer requires the consumer to ship Alpine (#79).**
  `templates/foundations.twig` drops all Alpine directives; swatch switching, copy-to-clipboard
  and the contrast-matrix toggle now live in a package-shipped, dependency-free
  `dist/foundations.[hash].js`, injected for foundations renders exactly like the existing
  foundations CSS bundle. The hero server-renders the default swatch, so the no-JS view shows
  real data. Consumer component demos inside the iframe are unaffected — they keep using
  whatever the consumer bundles.

- **`|resizer` now emits tuple-declared variants for real fixture images too, not only
  `placeholder()` fakes** ([#70](https://github.com/parisek/styleguide/issues/70)). Every tuple
  becomes an entry reusing the ORIGINAL `src` (no image pipeline — the browser downloads one
  file) with the tuple's declared `width`/`height`/`media`, so the styleguide `<picture>` DOM
  mirrors the multi-source markup the CMS resizer emits in production — what DOM-structural
  checks (tailwind-base's `picture.contract.js` sharpness contract) assert against. With
  real-content fixtures (the `picture.md` "prefer real content" default) previously passing
  through untouched, the contract silently skipped exactly the fixtures projects prefer.
  Missing tuple axes derive from the fixture's `width`/`height` metadata; without metadata the
  provided axis is kept and the other omitted (never invented). `.gif` fixtures pass through
  whole — parity with timber-kit `Resizer::$skip_animated` (animation can't be cheaply proven
  from a URL, and flattening an animated preview is the dangerous direction). Verified against
  a real consumer (pm-a): 220 visual baselines unchanged, behavior picture-contract coverage
  grew from 16 to 26 renders with no failures.

- **Malformed metadata YAML now surfaces in `GET /styleguide/api/health` instead of silently
  dropping the component from the catalogue.** `ComponentParser::parseTwigComment()` throws
  `ParseException` on invalid YAML (previously degraded to `false`); the existing resilience
  paths in `parse()`/`parseAll()` catch it and record a `getWarnings()` entry, so the walk
  continues and the broken file is named. Variant sibling annotations keep the old
  fall-back-silently behaviour (caught at the `discoverVariants()` call-site). Real-world
  trigger: an unquoted `{ padding-top: 0 }` in a field description made two sloneek
  components vanish with no trace. Behavioural note for direct `parseTwigComment()`
  consumers: wrap in `try/catch (ParseException)` if you relied on the `false` return.
- **`styleguide lint` reports `metadata-yaml-invalid` (Error) for malformed metadata YAML** instead
  of crashing on the propagated `ParseException` — distinct from `unindexed` (no metadata comment
  at all), because the author DID write metadata and the component is guaranteed missing from the
  catalogue.

## [1.2.0] - 2026-07-09

### Added

- **`styleguide.data.yaml` / `styleguide.data-<name>.yaml` sidecars + `styleguide_data()` Twig function.** A component/page/doc directory may now ship one or more pure-YAML sidecars — `styleguide.data.yaml` (the DEFAULT set, no-arg `styleguide_data()`) and any number of `styleguide.data-<name>.yaml` NAMED sets (`styleguide_data('<name>')`), `<name>` matching the SAME `[a-z0-9-]+` id rule already used for `styleguide.<variant>.twig` variant ids — a flat naming family living directly in the component directory, mirroring the variant-sibling convention rather than introducing a second nested `data/` concept. `'default'` is a RESERVED set name — `styleguide_data('default')` throws an `\InvalidArgumentException` before touching the filesystem, pointing at the no-arg call instead, so a stray `styleguide.data-default.yaml` can never be loaded through either door. Solves a real gap: Twig's `{% include %}` can't export variables back to the caller, so several `styleguide.<variant>.twig` siblings sharing bulky demo data had no clean way to share it short of duplicating the data or an `{% extends %}`-based "data template" trick (still valid as an escape hatch for expression-heavy demos that can't be plain YAML). Resolution is always scoped to the CURRENTLY rendering component's own directory — there is no cross-component lookup — and its "currently rendering" context is cleared (reset to `null`) immediately after each render completes, so a `styleguide_data()` call reaching the environment between renders throws instead of silently reusing a stale directory. Any `{ placeholder: {...} }` node anywhere in the tree — at any depth — is recursively resolved into the exact shape `placeholder()` itself returns, with `ratio:` (colon-separated, e.g. `"16:9"`) accepted as a YAML-only alias for `Placeholder::generate()`'s own `aspect:` option (slash-separated; the alias converts the separator too, and an explicit `aspect:` always wins over `ratio:` when both are present); `src:`/`url:` string values are recursively rebased onto `templateUrl`/`homeUrl` (same rules as `resolveAssetUrl()` — including root-relative paths like `/dist/foo.png`, which ARE rebased, not treated as absolute). An invalid `<name>` (doesn't match `[a-z0-9-]+`, which also rejects traversal-shaped values like `'../x'`, `'a/b'`, `'%2e%2e'`) is rejected before the filesystem is touched; a missing set → `RuntimeException` naming the expected path (relative to `templates_path` — the absolute path is logged via `error_log()` instead, never leaked into rendered 500-page markup) AND enumerating every data set actually present in that directory (a typo aid); an empty/`null`/`{}`/`[]` sidecar resolves to `[]`, while a bare-scalar top-level node throws a `RuntimeException` naming the actual shape found; malformed YAML → Symfony's `ParseException` propagates unchanged (same uncaught contract as `styleguide.yaml` itself), and since no object/custom-tag parse flags are ever passed, a `!php/object`-tagged node resolves to `null` (never a real PHP object) and an arbitrary custom tag hits the same `ParseException`. Never collides with variant-sibling discovery (`.yaml` vs. the `.twig`-only glob). Discovered while wiring `mairateam`'s shared hero demo data. See `README.md` § *YAML sidecar data* and `docs/API.md` § `styleguide_data()`.

## [1.1.2] - 2026-07-08

### Fixed

- **Doc entries are never responsive.** A doc page is prose — like `foundations`/`overview` — never a widget meant to be previewed at different breakpoints, but its `responsive` YAML key still defaulted to `true` like a component/page, so a doc got the viewport-preset/width toolbar unless every author remembered to set `responsive: false` by hand. `ComponentParser` now forces `responsive: false` for every `doc` entry regardless of the YAML key — even an explicit `responsive: true` in a doc's front-comment has no effect. Component/page kinds are unaffected (still default `true`, opt out with `responsive: false`).
- **Consumer `iframe.body_class` no longer bleeds onto doc pages.** Same bug shape as the foundations fix in [1.1.0]: `render-cell.twig` applied the consumer's site-wide `iframe.body_class` to `<body>` for `doc` renders too — a consumer with a dark `iframe.body_class` broke prose readability on a real doc page, reported after a consumer's doc rendered with the site's dark brand background. The global class is now skipped for `kind == 'doc'`, mirroring `foundations`. Deliberate difference from `foundations`: the **per-entry** `body_class` (authored in the doc's own YAML front-comment) still applies — it's an explicit per-doc opt-in by the author, not a site-wide bleed, so it remains the doc's escape hatch.

## [1.1.1] - 2026-07-08

### Fixed

- **Sidebar favicon gets protective padding.** The project favicon in the sidebar header rendered edge-to-edge inside its rounded box — icons without their own safe-area looked glued to the corners. The image now sits inset (`p-1`, `object-contain`) so any favicon shape breathes.
- **Sidebar-toggle icon stays a hamburger.** The open-state morph into an X read as "close/dismiss" rather than "menu" and was misleading next to the sidebar's own close affordances; the icon is now static in both states (`aria-expanded` still carries the state for assistive tech).

## [1.1.0] - 2026-07-08

### Added

- **Components may ship ONLY named variant fixtures — no bare `styleguide.twig` required.** A component/page/doc directory with `styleguide.<id>.twig` siblings and no bare `styleguide.twig` now still surfaces as a renderable entry: `has_styleguide` goes `true` from named variants alone, not just from the bare sibling or the legacy `styleguide:` flag, so an all-named component is no longer silently filtered out of the sidebar/palette/overview. New additive `has_default_variant` field on `/api/components`/`/api/pages`/`/api/docs` (`true` only when `<id>/styleguide.twig` itself exists) lets the SPA's variant grid tell "has some fixture" apart from "has the unnamed default fixture" — `VariantGrid.vue` now shows its synthetic "Default" tile only when `has_default_variant` isn't explicitly `false`, so a variants-only component's grid shows exactly its named tiles (all click-to-isolate, the first included) with no broken/empty tile pointing at the component's raw production template. See `docs/API.md` § `/api/components` and `README.md` § *File-convention variants → All named, no bare default*.

### Fixed

- **Consumer `iframe.body_class` no longer bleeds onto the foundations page.** `render-cell.twig` applied the consumer's site-wide `iframe.body_class` (and the per-entry `component.body_class`) to `<body>` for every kind, including `foundations` — a package-owned page with a fixed zinc-on-white palette. A consumer with a dark `iframe.body_class` (e.g. a dark brand background) made the foundations page's headings/cards nearly invisible, discovered in the `mairateam` project after the 1.0 bump. Both classes are now skipped for `kind == 'foundations'`; component/page kinds are unaffected. The existing light/dark `body { background-color }` safety net in `<head>` is untouched.


## [1.0.0] - 2026-07-07

### Added

- **`styleguide lint` CLI subcommand.** Reports metadata quality issues across `templates/`: unindexed templates (no parseable `name:`), dead `styleguide:` YAML content, broken `usage:` cross-references, unknown `render:` values, and empty `description` strings. `--type=component|page|doc` (default: all three), `--format=text|json` (default: text), reuses `--templates`/`--pretty`. Exit `0` clean, `1` warning/error findings present, `2` usage/internal error — a three-tier contract specific to `lint` (`list`/`show` keep their existing `0`/`1` codes). See `docs/API.md` § CLI and `README.md` § Command-line catalogue. The `broken-usage-ref` check and the catalogue itself now share one `ComponentParser::normaliseUsage()` helper, so a `usage:` value — comma-separated string as authored, or an already-array YAML value — is parsed into ids exactly once; `/api/components`/`/api/pages`/`/api/docs` emit the result as `usage: string[]` rather than the raw CSV.
- **New `GET /styleguide/api/health` endpoint.** Reports per-file parse warnings (`ComponentParser` now catches `\Throwable`, not just YAML `ParseException`, so one pathological template no longer 500s the whole `/api/components` catalogue — it's skipped and recorded instead) plus component/page/doc counts. A separate endpoint rather than a `_warnings` field on the existing four, which each emit a bare JSON array with no additive slot for a sibling field.
- **`?theme=light|dark` on the render endpoint.** Implements the contract `README.md`/`docs/API.md` already documented but the code never enforced (doc drift closed). Whitelisted server-side (`Router::whitelistTheme()`) — anything other than the literal string `dark` resolves to `light`. Stamps `class="dark"` + a matching `color-scheme` on the rendered `<html>`; inert for projects without dark-mode CSS. SPA toolbar gained an iframe-theme toggle independent of the chrome theme.
- **Optional `auth` config key.** `callable(array $route): bool` checked once per request before any dispatch; return `false` to respond `403 Forbidden` (plain text) before SPA/render/API/asset handling runs. `null` (the default) preserves today's behaviour — no gating. A non-`null`, non-callable value throws `InvalidArgumentException` at construction (fail loudly at boot instead of silently allowing every request), and an `auth` callable that throws is treated as a denial and logged via `error_log()` rather than letting the exception reach the response (fail closed). Documented alongside a recommendation to prefer web-server-level HTTP Basic Auth for publicly reachable deployments.
- **Auto-discovered `styleguide.<variant>.twig` sibling files.** `ComponentParser` now globs a component/page/doc directory for `styleguide.<id>.twig` files (`<id>` matching `[a-z0-9-]+`) and surfaces them as an additive `variants: [{id, title, description}]` field ([] when none exist — every pre-existing template keeps this BC default). The filesystem is canonical: an id with no matching file is silently dropped rather than fabricating a phantom variant. Display metadata (`title`/`description`) is authored directly in the sibling file's own first `{# … #}` comment — the same front-comment convention every component/page template already uses — with the component's YAML `variants:` map (`<id>: <title-string \| {title?, label?, description?}>`, `label:` a legacy alias for `title:`) kept only as a fallback for templates written before per-sibling annotations existed; a missing or malformed sibling annotation falls through to the map, then to the id, without ever failing the whole component. Ordered by id (== filename order). Passed through verbatim by `/api/components`, `/api/pages`, `/api/docs`, and the `list`/`show` CLI commands, alongside the object's own sibling-detection flag, now named `has_styleguide` for casing consistency with `body_class` and the rest of the object. See `docs/API.md` § Component YAML metadata and § Component Twig file conventions.
- **`?variant=<id>` on the render endpoint** resolves the auto-discovered `styleguide.<id>.twig` sibling in place of the default `styleguide.twig`, for `component`/`page`/`doc` kinds. Whitelisted server-side against `^[a-z0-9-]+$` (`Router::whitelistVariant()`) on both the render endpoint and the SPA-shell deep links (`/component/<slug>`, `/page/<slug>`, `/doc/<slug>`) so `Router::synthesizeEmbeddedRoute()` can forward it across the iframe-embed swap. Absent, invalid, or unknown-but-well-formed values fall back silently to the default `styleguide.twig` → `<slug>.twig` chain — never a 404 — so a bookmarked deep link survives a deleted/renamed variant file. Query-only, no cookie fallback (unlike `theme`): a variant is scoped to one deep link, not a visitor preference worth persisting, so an in-iframe native navigation to a link without its own `?variant=` loses it on the swap — an accepted, documented gap. Composes independently with `?theme=`. `?variant=<id>` is deep-linkable via the SPA's own `?variant=` query param, silently reset on navigation to a different entry unless the incoming URL itself carries a valid one for that entry — see the variant grid bullet below for how the SPA surfaces this today.

- **Variant grid — every variant as its own preview screen, tiled to fit the canvas (SPA-only; prototype).** The toolbar pill switcher is gone, and so is the short-lived server-side stacked view: the render endpoint's `?variant=` semantics are unchanged from the file-convention variants feature above (no `?variant=` → the default `styleguide.twig` body only, single block, no headings; `?variant=<id>` → isolate that one block). Instead, whenever the SPA's current entry has discovered variants and no `?variant=` is selected, the preview area becomes a responsive grid of independent `<iframe>` tiles — the default fixture first, then each discovered variant in order. Each tile gets a slim header (the variant's title, plus its `description` when supplied). A deep-linked `?variant=<id>` still shows the classic single preview of just that variant (an unknown/removed id falls back to the grid, not a 404 and not a stale "selected" pill — there is no pill any more).
  - **Device presets now apply per tile, with one shared scale readout in the toolbar.** The toolbar's responsive-width preset dropdown (+ custom width + orientation toggle), previously hidden while the grid was active, now stays visible and functional: a fixed-width/fixed-height preset (Mobile, Tablet, …) renders every tile's iframe at exactly that preset's logical size, then scales the whole tile down (never up) to fit that tile's own measured cell width. Since the shared preset and (uniform cell widths) the resulting zoom are identical across every tile, the scale no longer repeats in each tile's header — the toolbar's viewport trigger label now shows it once instead (e.g. `Mobile 375 × 667 (84 %)`, the same `(NN %)` convention the classic single preview already used), and only when the shared zoom is below 100%. Full stays fluid (100% cell width, auto content height, no scaling) — the grid's original behavior. Drag-to-resize handles remain single-preview-only (the grid has none to hide).
  - **Tile density — Auto | 1 | 2 | 3 | 4.** A new toolbar dropdown (grid mode only, matching the device-preset trigger's own rounded-pill/icon/label/chevron shape) replaces the earlier rows/grid layout toggle with five density options — inspired by Histoire's single/grid story layout, extended to be preset-aware. "Auto" (the default) derives the `auto-fit` `minmax()` column basis from the shared viewport preset's effective width instead of one fixed constant for every preset — a Desktop preset (1280 px) settles on far fewer tiles per row than Mobile (375 px) on the same canvas; Full keeps the original fixed 420px basis. "1"–"4" fix the column count exactly (`repeat(N, minmax(0, 1fr))`), ignoring the preset — "1" is the direct visual replacement for the old "rows" stacked layout (a single-column grid with the same subgrid header-height sharing renders identically). Persisted under `sg-variant-columns` in localStorage; a pre-existing `sg-variant-layout` value migrates once (`"rows"` → `1`, `"grid"` → `"auto"`) and the legacy key is removed. Tiles no longer stretch to the tallest one in their row, and the pre-load placeholder height dropped from a visibly over-tall 320px to a much smaller 96px.
  - **Click-to-isolate, with a subtle expand-icon hint.** A variant tile's header is now a click/keyboard affordance that navigates straight to `?variant=<id>` (the classic single, resizable preview) — no more re-selecting it from a dropdown. A small expand icon at the right edge of every clickable header (muted, revealed on hover and on keyboard focus) makes the click-through discoverable; the Default tile's header carries neither the affordance nor the icon — there is no route that shows it alone in the classic single preview without also satisfying the grid's own activation rule (any entry with variants and no `?variant=` selected always lands back on the grid).
  - **Breadcrumb-based return to the grid, replacing the earlier "← All" toolbar back control.** Once a variant is isolated, the toolbar breadcrumb gains a trailing Variant segment ("Section / Component name (slug) / Variant title") and its component-name crumb itself becomes a click/keyboard link back to the grid — standard breadcrumb semantics instead of a dedicated button. The crumb stays a plain, non-interactive label whenever no variant is isolated (nothing to go back to). The description bar (between the toolbar and the usage panel) also picks up variant context: while isolated, it shows the variant's own `description` (prefixed with its title, styled like the grid's own tile-header titles) in place of the component/page's general description — replaced, not appended, since the variant's own description is the more specific of the two; a variant with no description of its own leaves the bar showing nothing rather than falling back to the general blurb.

### Changed

- **Sidebar prefix-tree groups drop the chevron.** The component-section and Pages-section group rows (e.g. "Widget — 4") no longer render a rotating arrow glyph before the label — the count badge alone signals a group, and the label now sits flush at the same left padding as every flat sibling item instead of being indented out of line. The whole row is still the expand/collapse toggle; `aria-expanded` keeps the state programmatically discoverable now that the visual chevron cue is gone.
- **Light motion layer added across the SPA chrome**, all gated behind `@media (prefers-reduced-motion: no-preference)` (Tailwind's `motion-safe:` variant, or a dedicated CSS keyframe for anything Tailwind can't express) so a reduced-motion preference gets an instant state change instead of a tween: the toolbar's hamburger button morphs its three bars into an X (~200ms) when the sidebar toggles; the mobile sidebar slide-over now uses a 240ms `cubic-bezier(0.32,0.72,0,1)` easing plus a backdrop opacity fade (~200ms) instead of a hard show/hide; sidebar sections and prefix-groups expand/collapse via a CSS `grid-template-rows` 0fr↔1fr tween instead of snapping open/closed; the variant grid's tiles get a staggered entrance (opacity/translateY, ~180ms, 30ms stagger per tile) on first render; the command palette fades and scales in (~120ms) on open.
- **Preview navigation no longer flashes the previous entry's content.** The preview `<iframe>` is now keyed by its own src identity, so navigating to a different component/page/doc (or reloading, or toggling the iframe theme, or switching a variant) unmounts the old iframe and mounts a genuinely fresh one instead of just repointing one persistent element's `src` — a real browser used to keep painting the old document until the new one finished loading. The pane's measured content height also resets immediately on navigation instead of holding the previous entry's height until the new document reports its own, which used to make the preview pane visibly jump. The variant grid applies the same fix per tile.
- **⌘K/Ctrl+K now opens a command palette instead of focusing the sidebar's filter input.** The palette (`SearchPalette.vue`) ranks components/pages/docs by a new weighted multi-field score (`lib/searchMatch.js`'s `scoreEntry()` — name > id > category > description, exact > prefix > substring), groups results by section, and is fully keyboard-navigable (arrows with wraparound, Enter to open, Esc to close; a second ⌘K/Ctrl+K also closes it). The sidebar's own inline filter input is unchanged and still works as before. One behavior narrowed as a side effect: Escape-to-clear-the-filter used to be global (any focus state); it's now scoped to firing only while that filter input itself has focus, so it no longer fights with the palette's own Escape-to-close.
- **SPA chrome rewritten from Alpine.js 3 to Vue 3 + Pinia + vue-router** (Phase 1 of the Styleguide 2.0 roadmap). 1:1 feature parity — no new user-facing behavior. The `dist/` bundle is `@internal` and not covered by SemVer, but for transparency: every viewport preset/zoom/rotation, the sidebar prefix-tree grouping, search, locale switching, theme cycling, the fields drawer, and the usage/link cross-reference panels now ship with unit tests (Vitest) and a headless-browser parity suite (Playwright, running in CI for the first time — the previous Alpine-era browser suite was local-only).
- `Styleguide::dispatchSpa()` now injects a single `<script id="sg-config" type="application/json">` payload into `dist/index.html` instead of 6 separate regex substitutions, and throws when that injection point is missing instead of silently shipping a half-patched shell.
- New CI job asserts committed `dist/` is byte-for-byte reproducible from `frontend/` source (`npm run build && git diff --exit-code dist/`).
- **Helper registration no longer matches Twig exception text.** `tryAddFunction()`/`tryAddFilter()` used to rethrow unless the `LogicException` message contained the literal substring `"already registered"` — version-fragile and untested. They now always swallow (never crash a consumer's boot over a Twig-internal message change) and log to `error_log()` when the message doesn't match the expected collision, so the rare genuine-misuse case still leaves a trace.

### Fixed

- **Iframe dark theme no longer resets on in-iframe navigation.** A native link click inside dark-toggled render content (e.g. a nav link to another `/styleguide/page/...`) re-issues a `Sec-Fetch-Dest: iframe` request carrying no `?theme=` of its own, so `Router::synthesizeEmbeddedRoute()` used to hardcode `light`, silently reverting the visitor's choice. The SPA toolbar's iframe-theme toggle now also writes an `sg-iframe-theme=dark|light` cookie (`path=/styleguide`, `SameSite=Lax`); the router reads it as a fallback — through the same whitelist as the query param — whenever the request's own `?theme=` is absent. An explicit `?theme=` still wins over the cookie.
- **`#sg-config` JSON injection hardened against script-breakout XSS.** `dispatchSpa()` now encodes with `JSON_HEX_TAG` in addition to its existing flags, so a consumer-controlled value containing a literal `</script>` can no longer terminate the `#sg-config` element early.
- **Render-time exceptions now return HTTP 500.** A component/page/doc template that throws during render used to respond `200 OK` with the error markup embedded in the body — a health check or CI canary hitting `/render/<kind>/<slug>` couldn't distinguish a broken component from a working one. `Renderer` now calls `http_response_code(500)` before returning the (still-visible) error markup. `render404()` is unaffected.
- **`.map` files now serve as `application/json`.** `AssetServer` fell through to `mime_content_type()` for `.map` extensions (typically `text/plain`); explicit `application/json; charset=utf-8` matches their actual content.
- **Foundations CSS glob picks the newest file when several match.** `resolveFoundationsCssUrl()` used to return whatever `glob()` happened to list first when a stale `dist/foundations.*.css` from a previous build wasn't cleaned up; it now picks the newest by mtime and logs a warning via `error_log()`.
- **Variant grid tiles no longer grid-blow-out to 100% zoom for any fixed-width preset wider than the tile's own cell.** Real browsers (unlike the jsdom-based unit tests, which stub `clientWidth` and never actually lay anything out) default a CSS grid item's automatic minimum size to its content's min-content size — the tile card and its content-area wrapper had neither set `min-width: 0`, so a scaled-down device preset's own fixed-width iframe kept forcing its ancestor tracks to grow back to the iframe's full logical size, measuring that inflated width right back into the next zoom calculation and permanently pinning it at 100%. The "scale down to fit this tile's own measured cell width, never up" behavior documented since the original device-presets feature never actually engaged in a real browser as a result. Both the tile card and its content-area wrapper now carry `min-w-0`.
- **A fixed-width preset's scaled tile screen no longer overflows and gets clipped on its right edge.** `VariantGrid.vue`'s per-tile cell-width measurement read the content-area wrapper's `clientWidth` — its padding-box width, which includes the wrapper's own `p-3` — so `computeTileGeometry()`'s zoom fit against a cellWidth ~24px too generous; the resulting `wrapperWidth` (and the scaled iframe inside it) rendered past the wrapper's true content box and lost its right edge to the wrapper's `overflow: hidden`. `registerCell()` now measures the wrapper's content-box width (its `clientWidth` minus its own padding) instead, matching what its `ResizeObserver` already reported on every subsequent tick — both the initial synchronous read and every resize now agree on the same number, and the scaled screen renders centered with equal left/right gaps and no cropped edge.

### Documentation

- **`docs/API.md` fixes.** The `/api/fields` response shape documented an `{id, type, name, fields}` union that never existed — `FieldsEndpoint::handle()` has always emitted `{component_id, component_name, fields}` (matching `README.md`'s copy, which was already correct). Also documented three bundled Twig filters (`cachebust`, `format_date`, `custom_price_format`) that shipped since 0.1.3/0.3.8 but were never added to the § Twig functions & filters table.
- **CHANGELOG archived.** Entries older than `[0.4.0]` moved to `CHANGELOG-archive.md` (byte-for-byte relocation, nothing edited) to keep the active changelog focused on recent series.
- **Stale e2e Layer B removed.** `tests/e2e/smoke-browser.sh` reached into `window.Alpine.store(...)`, a global the Vue rewrite removed — it had been dead code since Phase 1. Deleted the script and its `run.sh`/CI wiring; the Playwright suite (`tests/e2e/playwright/styleguide.spec.js`) already covers the same behaviour and, unlike its predecessor, runs in CI.
- **`docs/MIGRATION.md` added.** Step-by-step guide for replacing a bespoke, hand-rolled styleguide with this package: the common bootstrap/config/rewrite/lint/cleanup steps once, then per-project deltas for the fleet's `centrumocnichvad` (Tailwind v3 + SCSS, dual CSS bundle), `suys-static` (Drupal-backed Twig, dead `styleguide:` YAML content), and `bootstrap-base` (Bootstrap 5, `picsum.photos` fixture images) migrations. Linked from `README.md` § `lint` — metadata quality report.
- **Sibling `styleguide.twig` is now documented as the official fixture convention** (`README.md` § Fixtures & sample data); the `styleguide:` YAML key stays functional as a presence-only flag for backward compatibility, but content under it is flagged by `lint` (`dead-styleguide-content`).

## [0.6.5] - 2026-06-22

### Fixed

- **Toolbar crowding on narrow viewports.** Below `lg` the preview toolbar's secondary actions (Canvas / open-in-new / reload) now collapse into a single ⋮ overflow menu instead of colliding with the breadcrumb and the viewport dropdown; inline icons stay on `lg+`, and the viewport dropdown remains one-tap at every width. New i18n key `toolbar.more_actions`. (#61)

## [0.6.4] - 2026-06-22

### Fixed

- **Pages section now groups by name prefix.** The prefix-tree grouping (≥3 items sharing a `"<Prefix> - <Suffix>"` name collapse under a `<Prefix>` group) applied only to component sections — the Pages list rendered flat, because the tree builder walked `this.items` (components) and never `this.pages`. Added `components.pagesTree` and gave the Pages section the same search-vs-tree rendering, so e.g. "Hlavička - static / sticky / absolute / fixed" collapse under a "Hlavička" group (prefixes with < 3 members stay flat, same rule as components). (#59)

## [0.6.3] - 2026-06-22

### Fixed

- **Orientation toggle showed the wrong axis for Desktop presets.** The portrait/landscape switch bound its active state to `previewRotated` (relative to the preset's canonical shape); since Desktop presets are landscape-canonical, `rotated=true` renders portrait, so the toggle highlighted "landscape" while the preview was visibly portrait — and the mismatch survived a refresh. The toggle now reads/sets **absolute** orientation (`ui.isPortrait` / `ui.setPortrait()`, derived from the effective display dimensions), correct for both portrait-canonical (Mobile / Tablet) and landscape-canonical (Desktop) presets. (#57)

## [0.6.2] - 2026-06-22

### Fixed

- **Last indigo removed from the chrome.** The Fields-drawer `textarea` field-type badge (a categorical palette) was the only remaining indigo; flipped to red to match the Porta-red accent, so the SPA chrome is fully indigo-free. (#55)

## [0.6.1] - 2026-06-22

### Fixed

- **Tall / rotated preset scaling.** Device presets with a canonical height (including rotated ones — e.g. a rotated Desktop at 800×1280) overflowed the preview pane vertically and tucked their top edge under the toolbar where it couldn't be scrolled into view. Restored **fit-to-bounds** scaling: `zoom = min(1, availW/w, availH/h)` for fixed-height presets, so the whole emulated device — top edge included — stays visible. Full / Custom (no canonical height) remain width-only; scaling only ever shrinks, never upscales. (#53)

## [0.6.0] - 2026-06-22

### Changed

- **SPA chrome redesign — sidebar + toolbar.** Cleaner, birdclaw-influenced visual language with a Porta-red accent. Sidebar: active item is a red pill (across docs / components / pages / prefix-tree children), bullet dots dropped, airier rows, a "SEARCH" label + pill input, and a circular outlined theme toggle (stroke sun/moon/monitor). Toolbar: the desktop segmented viewport bar and the separate mobile menu are unified into **one labelled dropdown at every width** (`<word> · <W×H> ▾`, red active row); the KOMPONENTA badge + page usage chip move from indigo to Porta red. Collapsible sections, count badges, and the automatic prefix-tree are all retained. (#51)

### Added

- **Mobile sidebar overlay.** Below `lg`, the sidebar becomes a fixed slide-over (default-closed) opened by the hamburger over a backdrop; selecting a nav item closes it — so the preview gets full width on phones instead of being squeezed to ~100px. New i18n key `search.label` (cs `Hledat` / en `Search`). (#51)

### Fixed

- **Sidebar favicon fallback.** The SPA sidebar `#sg-favicon` swaps in a generic glyph via `onerror` when the configured favicon 404s, instead of the browser's broken-image icon (companion to the standalone-bar fallback from 0.5.0). (#51)

## [0.5.0] - 2026-06-22

### Added

- **Asset paths resolve against `twig_context.templateUrl`.** Every consumer-supplied asset path the package emits — `iframe.css` / `iframe.js` / `iframe.fonts[]`, `project.favicon` (the `<link rel="icon">`, the standalone-bar `<img>`, and the SPA sidebar `#sg-favicon`), and `styleguide.logo[*].src` (the foundations overview) — is now rebased onto the bootstrap's `templateUrl` at render time via the new `Renderer::resolveAssetUrl()`. This lets `styleguide.yaml` keep one short, docroot-agnostic path (`/dist/css/style.css`) that resolves correctly in **every layout**: standalone (empty `templateUrl` → unchanged), WordPress (`/wp-content/themes/<theme>/static/…`), and Drupal (`/themes/custom/<theme>/static/…`) — no per-CMS hardcoding, no `STYLEGUIDE_ASSET_PREFIX`-style docroot guessing. Backward compatible: an empty base is a no-op (byte-for-byte the old output); already-absolute-under-base paths are left untouched (no double prefix); external (`https://…`, `//cdn…`, `data:`) and anchors (`#…`) are never rebased. Fixes blank / CSS-less page renders (and the slow `/dist/...` 302-redirect loop) when the styleguide is served from a theme rather than from the static dir as docroot. (#46, #49)

### Fixed

- **Standalone-bar favicon fallback.** The "← back to styleguide" bar (shown when a render is opened in its own tab) now swaps a generic glyph in via `onerror` when the configured favicon 404s, instead of painting the browser's broken-image icon next to the page title — mirroring the SPA sidebar's favicon fallback. (#47)

### Documentation

- README gains an **"iframe asset paths — resolved against `templateUrl`"** section: a per-layout resolution table (standalone / WordPress / Drupal) and the `resolveAssetUrl()` rules. (#48)

## [0.4.5] - 2026-06-08

### Added

- **`iframe.page_wrapper_class` config.** Set it in `styleguide.yaml` and **every page render** is wrapped in `<div class="…">` — the project's structural shell (the `page-wrapper` sticky-footer flex column / `min-h-dvh` that the production layout puts around `header + main + footer`). Applied to `kind: page` only (never component / doc previews, so the full-height shell can't leak into a small preview), built through `create_attribute` (same class-escaping contract as the `<body>` line). Default `''` renders no wrapper, keeping the package framework-agnostic — Tailwind projects opt in, Bootstrap / custom consumers leave it blank. Completes the production-parity pair with `body_class`: `body_class` reproduces the page's `<body>`, `page_wrapper_class` reproduces the wrapper `<div>` — so a page preview matches production without each consumer hand-wrapping every `page/<name>/styleguide.twig`. Flows through the existing `iframe.*` passthrough (`Styleguide → Renderer → render-cell.twig`), no PHP changes.

- **Per-entry `body_class` metadata.** A component / page / doc can declare `body_class: "…"` in its front-comment metadata; the render iframe applies it to `<body>`, merged **after** the global `iframe.body_class` via `create_attribute({ class: [iframe.body_class, <entry>.body_class] })` (empty values dropped — no stray `class=""`). Lets a page mirror what its production layout puts on `<body>` (e.g. a dark brand background from an ACF `body_background_color`) instead of wrapping the page body in a styleguide-only `<div class="bg-… body-…">`. Parsed into the component record (so it also surfaces in `/api/*` + the CLI) and threaded through `Styleguide → Renderer → render-cell.twig`.

### Fixed

- **Static analysis & PHP 8.4 deprecations.** The typography translation helpers (`_xt` / `__t` / `_nt` / `_nxt`) now resolve their underlying Twig function callable through a guarded helper, clearing 9 PHPStan level-8 errors (`getFunction()` / `getCallable()` are nullable in Twig's signature) with no behaviour change — the fallback mirrors the identity stubs registered just above. PHPUnit now defines `<source>` and sets `ignoreIndirectDeprecations`, muting the implicitly-nullable-parameter deprecations emitted by the upstream `mundschenk-at/php-typography` (its latest release v6.7.0 is the ceiling of our `^6.0` constraint, so the deprecations are unfixable here); our own `src/` deprecations still surface.

## [0.4.4] - 2026-06-05

### Added

- **Typography-aware translation helpers `_xt` / `__t` / `_nt` / `_nxt`.** Same signatures as the WP `_x` / `__` / `_n` / `_nx` originals — `_xt`/`_nxt` require `context` and `_nt`/`_nxt` require `number` (no silent defaults that could mask a missing required argument) — but the translated result is piped through `|typography`, so long-form copy gets consistent typographic treatment without remembering `|typography` on every callsite (`_x` → `_xt` is a one-character opt-in). Each composes via `getFunction()/getFilter()->getCallable()` at call time, so the project's real translator (WP `_x()` etc.) and tuned typography settings win automatically; `is_safe: ['html']` mirrors the filter's contract. The WP-production (Timber) and Drupal sides land in parisek/timber-kit#42 and parisek/custom-components#87 with the same four signatures, so authoring stays portable across CMSes. (#21)

## [0.4.3] - 2026-06-04

### Added

- **Sidebar prefix tree.** Components whose display name shares a `<Prefix> - <Suffix>` shape are grouped into collapsible sidebar nodes (a prefix with ≥ 3 members), and children show the suffix only (`Article ▸ content / image / links`). Singletons and names without ` - ` stay flat with their full name. Groups are expanded by default, collapse state persists (`sg-groups`), and the active item's group auto-expands; a search query flattens the tree to full-name results. Purely presentational — derived from names at render time, no metadata/API change, so it benefits every downstream project automatically. (#38)

## [0.4.2] - 2026-06-04

### Fixed

- **Critical: the viewport-width toolbar never rendered for `responsive: true` entries** (regressed in 0.4.0). The `<template x-if>` gate called `$store.components.find(...)` directly inside the Alpine expression, which silently broke the render (no console error, gate logically true) — every component/page lost its resolution controls. Moved the lookup into a `toolbarVisible` getter so the template only reads a plain identifier. Added a browser e2e regression that asserts the toolbar actually renders (the previous suite only called `setPreset()` as a method, so a missing toolbar went unnoticed). (#36)

## [0.4.1] - 2026-06-04

### Fixed

- Entries with `responsive: false` now hide the viewport toolbar and pin the iframe preview to Full width, even when a fixed preview width was persisted from a previous route. (#34)

### Added

- **Search shortcut hint + toolbar reload.** The sidebar search box now renders the `⌘ K` hint (the Cmd/Ctrl+K focus keybind already existed); a new toolbar **reload** button re-fetches the `/api/*` catalogue and force-reloads the preview iframe (handy in local dev when a template changes). New `toolbar.reload` i18n key (cs/en).

## [0.4.0] - 2026-06-04

### Added

- **`doc` content kind** — a new first-class template kind alongside `component` and `page`. Doc templates live at `templates/doc/<name>/<name>.twig` (prefer `styleguide.twig` sibling, fallback `<name>.twig`). URL surface: `/styleguide/doc/<slug>` (SPA), `/styleguide/render/doc/<slug>` (bare iframe), `/styleguide/api/docs` (JSON list — same shape as `/api/pages`). The `@doc` Twig namespace is auto-registered when `templates/doc/` exists (doc templates reference `@doc` via `{% include %}` directly — there is no `doc_*()` helper). The kind is **optional**: absent `templates/doc/` → `/api/docs` returns `[]`, the DOKUMENTACE sidebar group still shows foundations + overview. New `Api\DocsEndpoint` class (`@internal`).
- **DOKUMENTACE sidebar group** — collapsible sidebar section grouping Foundations, Overview, and doc entries. Controlled by a new `nav.docs` i18n key (cs: `Dokumentace`, en: `Documentation`). The group is always present in the sidebar; doc entries appear below foundations + overview when `templates/doc/` is populated.
- **General `responsive` front-comment flag** — new optional boolean YAML metadata key applicable to component, page, and doc templates (default `true`). When set to `false`, the SPA hides the responsive-width toolbar for that entry, useful for docs or fixed-layout demos where viewport resizing has no meaning.

[Unreleased]: https://github.com/parisek/styleguide/compare/v1.7.1...HEAD
[1.7.1]: https://github.com/parisek/styleguide/compare/v1.7.0...v1.7.1
[1.7.0]: https://github.com/parisek/styleguide/compare/v1.6.2...v1.7.0
[1.6.2]: https://github.com/parisek/styleguide/compare/v1.6.1...v1.6.2
[1.6.1]: https://github.com/parisek/styleguide/compare/v1.6.0...v1.6.1
[1.6.0]: https://github.com/parisek/styleguide/compare/v1.5.1...v1.6.0
[1.5.1]: https://github.com/parisek/styleguide/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/parisek/styleguide/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/parisek/styleguide/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/parisek/styleguide/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/parisek/styleguide/compare/v1.1.2...v1.2.0
[1.1.2]: https://github.com/parisek/styleguide/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/parisek/styleguide/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/parisek/styleguide/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/parisek/styleguide/compare/v0.6.5...v1.0.0
[0.4.0]: https://github.com/parisek/styleguide/compare/v0.3.14...v0.4.0
