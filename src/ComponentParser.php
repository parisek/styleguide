<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * @internal The class itself is implementation detail. Two members ARE public
 *           API (annotated individually below): `RENDER_MODES` and the static
 *           `normaliseRender()` — both used by the `vendor/bin/styleguide` CLI
 *           and external tooling that needs the canonical render-mode list.
 *           The instance methods (`parse`, `parseAll`, `parseTwigComment`)
 *           are wrapped by JSON API endpoints — direct PHP access by consumer
 *           is not supported. See `docs/API.md`.
 *
 * Parses styleguide metadata from Twig components & pages in the project's templates/ directory.
 *
 * Reads the first {# ... #} comment in each `.twig` file, parses it as YAML, and produces
 * a normalised array of component/page metadata: name, category, description, fields, …
 *
 * Tabs in YAML are auto-converted to 4 spaces — Twig editors insert tabs, YAML rejects them.
 *
 * Deliberately not `final` (deviates from this package's PSR-12 convention):
 * `tests/ComponentParserTest.php` doubles this class via `getMockBuilder()`
 * to exercise the `\Throwable`-catching resilience path deterministically
 * (see `getWarnings()`), and PHPUnit's mock generator unconditionally
 * refuses to double a class reflection reports as final — there is no
 * opt-out. No production subclassing is implied or supported.
 */
class ComponentParser
{
    /**
     * @api Public contract. Used by the `vendor/bin/styleguide` CLI and by
     *      downstream tooling that needs to validate the YAML key. Adding
     *      new render modes is a minor bump; removing or renaming any of
     *      the four is a major bump.
     *
     * Allowed render modes driving the iframe wrapper in render-cell.twig:
     * `inset` is the default 24px-padded wrapper for atomic components;
     * `bleed` / `chrome` / `overlay` skip the wrapper for full-bleed
     * components (hero / slider / sticky page chrome / modals).
     */
    public const RENDER_MODES = ['inset', 'bleed', 'chrome', 'overlay'];

    /**
     * @api Public contract. Used by the `vendor/bin/styleguide` CLI and by
     *      downstream tooling that needs to validate the YAML key. Adding
     *      new kinds is a minor bump; removing or renaming any of the five
     *      is a major bump.
     *
     * Closed enum declaring what a component *is* (authorial intent, not
     * derivable from any other field — see tailwind-base's
     * `docs/adr/0012-component-kind-taxonomy.md`): `block` is
     * editor-insertable; `section` is page-level chrome; `element` is
     * self-contained and reusable; `part` is a fragment authored for one
     * specific parent; `utility` is a rendering helper with no visual
     * identity of its own.
     */
    public const KIND_VALUES = ['block', 'section', 'element', 'part', 'utility'];

    /**
     * Filename shape of a file-convention variant sibling: `styleguide.<id>.twig`.
     * The captured group is the canonical variant id — same character class as
     * the `?variant=` query-string whitelist in Router::parse(), so a value
     * ComponentParser can ever produce is exactly the set a URL can ever name.
     */
    /**
     * @internal Exposed for `Cli\Linter`, which must apply the SAME rule the
     *           runtime does when deciding whether a component has a fixture —
     *           the looser `STYLEGUIDE_SIBLING_PATTERN` accepts filenames
     *           (`styleguide.WIDE.twig`) that never become variants here.
     */
    public const VARIANT_FILE_PATTERN = '/^styleguide\.([a-z0-9-]+)\.twig$/';

    /**
     * @api Public contract. Shared with `Cli\Linter` so the catalogue walk
     *      and the linter's own file walk can never disagree about which
     *      files are fixtures.
     *
     * Filename shape of the WHOLE `styleguide.*` sibling family — the bare
     * default (`styleguide.twig`) and every file-convention variant
     * (`styleguide.<variant>.twig`), including ones whose `<variant>`
     * segment doesn't satisfy VARIANT_FILE_PATTERN's stricter
     * `[a-z0-9-]+` id rule. Even an invalid-id sibling is still a fixture
     * file — it must never surface as a phantom catalogue entry just
     * because its filename didn't happen to match the narrower variant
     * pattern.
     */
    public const STYLEGUIDE_SIBLING_PATTERN = '/^styleguide(\.[A-Za-z0-9_-]+)?\.twig$/';

    /**
     * Directory segments that mean "partial, not a catalogue entry": any whose
     * name starts with an underscore.
     *
     * `_partials/` is the near-universal convention for "included by something
     * else" — the same meaning Sass gives `_file.scss` and Jekyll `_includes/`.
     *
     * This is used ONLY to suppress the `unindexed` lint finding. It must NOT
     * gate the catalogue walk: a template under `_partials/` that DOES carry a
     * `name:` has always been catalogued, and removing it there would be a
     * silent runtime behaviour change dressed as a lint fix. The catalogue
     * already excludes metadata-less partials on its own, by the missing-name
     * check — this only stops the linter reporting that correct exclusion as a
     * defect.
     *
     * Deliberately a directory rule, not a filename one. `_foo.twig` beside real
     * components is not an established convention here; a directory the author
     * named `_partials` is an explicit statement about everything inside it.
     */
    public const PARTIAL_DIR_PATTERN = '#(?:^|/)_[^/]*(?:/|$)#';

    /**
     * True when `$relativePath` lives under an underscore-prefixed directory.
     *
     * Takes the path RELATIVE to the walk root so a project checked out under
     * e.g. `/home/_deploy/site` is not mistaken for one big partial.
     */
    public static function isPartialPath(string $relativePath): bool
    {
        // Normalise BEFORE dirname(), or a Windows-style path keeps its
        // backslashes through the split and never matches.
        $dir = dirname(str_replace('\\', '/', $relativePath));

        return $dir !== '.' && $dir !== '' && preg_match(self::PARTIAL_DIR_PATTERN, $dir . '/') === 1;
    }

    private string $templatesPath;

    /** @var list<array{file:string, error:string}> */
    private array $warnings = [];

    public function __construct(string $templatesPath)
    {
        $this->templatesPath = rtrim($templatesPath, '/');
    }

    /**
     * @internal Exposed for `Api\HealthEndpoint`; not part of the SemVer
     *           contract — see docs/API.md § Other PHP classes.
     *
     * Files `parse()`/`parseAll()` had to skip because parsing their front
     * comment threw, accumulated across every call made on this instance.
     *
     * @return list<array{file:string, error:string}>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @api Public contract. Used by the `vendor/bin/styleguide` CLI and any
     *      downstream tooling that needs to coerce a YAML value. Signature
     *      and fall-through semantics ('inset' for anything unrecognised)
     *      are SemVer-protected.
     *
     * Coerce an arbitrary YAML value into one of the canonical render modes.
     * Null / missing / typos all fall back to 'inset' (the safe default that
     * matches pre-feature behaviour). Strict-equals against the allowed list
     * so e.g. integers or arrays from a malformed YAML can't slip through.
     */
    public static function normaliseRender(mixed $value): string
    {
        return is_string($value) && in_array($value, self::RENDER_MODES, true)
            ? $value
            : 'inset';
    }

    /**
     * @api Public contract. Used by the `vendor/bin/styleguide` CLI and any
     *      downstream tooling that needs to coerce a YAML value. Signature
     *      and fall-through semantics ('' for anything unrecognised) are
     *      SemVer-protected.
     *
     * Coerce an arbitrary YAML value into one of the canonical `kind`
     * values. Unlike {@see self::normaliseRender()}, there is deliberately
     * NO guessed default here — `kind` is authorial intent about what a
     * component *is* (see `docs/adr/0012-component-kind-taxonomy.md` in
     * tailwind-base), and the package must never invent one on the
     * author's behalf. Null / missing / typos all normalise to '' (absent),
     * which downstream tooling (e.g. `sync-skeleton`'s presence check)
     * treats as "not yet declared" rather than silently defaulting to some
     * plausible-looking kind. Strict-equals against the allowed list so
     * e.g. integers or arrays from a malformed YAML can't slip through.
     */
    public static function normaliseKind(mixed $value): string
    {
        return is_string($value) && in_array($value, self::KIND_VALUES, true)
            ? $value
            : '';
    }

    /**
     * @api Public contract. Shared with `Cli\Linter`'s `broken-usage-ref`
     *      rule so the catalogue's own usage-array parsing and the linter's
     *      raw-YAML re-read can never disagree about how a `usage:` value
     *      splits into ids. Any downstream tooling that needs the same
     *      coercion should call this rather than re-implementing it.
     *
     * Coerce a `usage:` YAML value into a list of trimmed, non-empty ids.
     * Authoring convention stays a comma-separated string
     * (`usage: 404, article-list`) — this is where that string gets parsed
     * into the array the wire contract (`/api/components` et al.) actually
     * emits. An already-array YAML value (e.g. a block list) is accepted
     * too, so authors aren't punished for reaching for YAML's native list
     * syntax: each entry is stringified, trimmed, and empty entries
     * dropped, same as the comma-split path. Anything else (null, bool,
     * int, …) yields `[]`.
     *
     * @return list<string>
     */
    public static function normaliseUsage(mixed $value): array
    {
        if (is_array($value)) {
            $ids = array_map(static fn(mixed $id): string => trim((string) $id), $value);
        } elseif (is_scalar($value)) {
            $ids = array_map('trim', explode(',', (string) $value));
        } else {
            return [];
        }

        return array_values(array_filter($ids, static fn(string $id): bool => $id !== ''));
    }

    /**
     * Parse metadata from a single component/page .twig file.
     *
     * @return array<string,mixed>|null  Null when file missing or metadata invalid.
     */
    public function parse(string $type, string $id): ?array
    {
        $dir = $this->templatesPath . '/' . $type . '/' . $id;
        $file = $dir . '/' . $id . '.twig';

        if (!file_exists($file)) {
            return null;
        }

        try {
            $content = (string) file_get_contents($file);
            [$metadata, $sourceFile] = $this->readComponentMetadata($dir, $id, $file, $content);

            if (!$metadata || !isset($metadata['name'])) {
                return null;
            }

            $hasDefaultFixture = file_exists($dir . '/styleguide.twig');
            $variants = $this->discoverVariants($dir, $metadata);
            // additive (v1.1.0): a component that ships ONLY named variant
            // siblings (no bare styleguide.twig) is still a real, renderable
            // fixture — see normaliseMetadata()'s has_styleguide doc.
            $hasStyleguide = $hasDefaultFixture
                || isset($metadata['styleguide'])
                || $variants !== [];

            return $this->normaliseMetadata(
                $id,
                $type,
                $metadata,
                $hasStyleguide,
                $hasDefaultFixture,
                $variants,
                $this->relativePath($sourceFile),
            );
        } catch (\Throwable $e) {
            // Single-file lookup path (used by Styleguide::dispatchRender()
            // for the render endpoint's <title>/body_class/render metadata)
            // — same resilience contract as parseAll(): a broken template
            // degrades this lookup to the pre-existing "no metadata" outcome
            // (null) instead of 500ing the render endpoint itself.
            $this->recordWarning($this->relativePath($file), $e);
            return null;
        }
    }

    /**
     * Parse all components/pages of a given type (recursive scan of templates/<type>/).
     *
     * @return array<int,array<string,mixed>>
     */
    public function parseAll(string $type): array
    {
        $dir = $this->templatesPath . '/' . $type;
        if (!is_dir($dir)) {
            return [];
        }

        $items = [];
        $iterator = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $flattened = new \RecursiveIteratorIterator($iterator);
        $regex = new \RegexIterator($flattened, '/\.twig$/');

        foreach ($regex as $file) {
            // A variant sibling carrying a {# name: #} header must never
            // surface as a phantom catalogue entry — exclude the whole
            // styleguide.* family, not just the exact default filename, so
            // even a sibling whose <variant> segment is invalid (and thus
            // never discovered by discoverVariants()) still can't leak in
            // here as its own "component".
            if (preg_match(self::STYLEGUIDE_SIBLING_PATTERN, $file->getFilename())) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            $id = $file->getBasename('.twig');

            try {
                [$metadata, $sourceFile] = $this->readComponentMetadata(
                    $file->getPath(),
                    $id,
                    $file->getPathname(),
                    $content,
                );

                if (!$metadata || !isset($metadata['name'])) {
                    continue;
                }

                $hasDefaultFixture = file_exists($file->getPath() . '/styleguide.twig');
                $variants = $this->discoverVariants($file->getPath(), $metadata);
                $hasStyleguide = $hasDefaultFixture
                    || isset($metadata['styleguide'])
                    || $variants !== [];

                $items[] = $this->normaliseMetadata(
                    $id,
                    $type,
                    $metadata,
                    $hasStyleguide,
                    $hasDefaultFixture,
                    $variants,
                    $this->relativePath($sourceFile),
                );
            } catch (\Throwable $e) {
                // One pathological template must not 500 the whole catalogue for
                // every sibling component. Record it and keep walking; surfaced
                // via GET /styleguide/api/health, invisible to the normal
                // component list the SPA renders.
                $this->recordWarning($this->relativePath($file->getPathname()), $e);
                continue;
            }
        }

        // Three-level sort: weight, then name (locale-aware via Collator
        // when the intl extension is loaded, plain strcmp otherwise — see
        // below), then `id` as a FINAL tie-breaker so two entries that also
        // share the same weight AND name (a real if unusual case — e.g. two
        // components both left at the default weight with the same display
        // name) still get a deterministic total order. Without it, the
        // fallback was filesystem/glob order, which is platform-dependent,
        // AND the Collator-vs-strcmp branch above already meant "same
        // weight, same name" could tie-break differently depending on
        // whether ext-intl happens to be installed — a consumer asserting
        // determinism (e.g. `Styleguide::inventory()`'s own contract) could
        // not rely on that being stable across machines.
        usort($items, function ($a, $b): int {
            if ($a['weight'] !== $b['weight']) {
                return $a['weight'] <=> $b['weight'];
            }

            $an = (string) $a['name'];
            $bn = (string) $b['name'];
            if (class_exists(\Collator::class)) {
                $nameCmp = (new \Collator('cs'))->compare($an, $bn);
                $nameCmp = $nameCmp === false ? 0 : $nameCmp;
            } else {
                $nameCmp = strcmp($an, $bn);
            }
            if ($nameCmp !== 0) {
                return $nameCmp;
            }

            // Plain strcmp — deliberately NOT Collator here: `id` is an
            // internal filesystem slug, not display text, so locale-aware
            // collation would be the wrong tool and would reintroduce the
            // Collator-availability-dependent ordering this tie-breaker
            // exists to remove.
            return strcmp((string) $a['id'], (string) $b['id']);
        });

        return $items;
    }

    /**
     * Extract YAML metadata from the first {# ... #} comment in a Twig file.
     *
     * Malformed YAML THROWS ParseException instead of degrading to `false`
     * (changed after a real-world case where an unquoted `{ padding-top: 0 }`
     * in a field description silently dropped the component from the
     * catalogue with no health trace). Both catalogue call-sites — parse()
     * and parseAll() — already wrap this in their \Throwable resilience
     * path, so the exception lands in getWarnings() / GET /styleguide/api/health
     * and the walk continues. Callers that WANT the old degrade-to-false
     * behaviour (variant sibling annotations) catch it explicitly.
     *
     * @return array<string,mixed>|false false = no comment / not a YAML map
     * @throws ParseException on malformed YAML inside the comment
     */
    public function parseTwigComment(string $content): array|false
    {
        $content = str_replace("\r", "\n", $content);

        if (preg_match("/{#\s*(.*?)\s*#}/s", $content, $match) && $match[1]) {
            $yaml = str_replace("\t", '    ', $match[1]);
            $parsed = Yaml::parse($yaml);
            return is_array($parsed) ? $parsed : false;
        }

        return false;
    }

    /**
     * Resolve a component/page/doc's metadata source: PRIORITY to a sibling
     * `<id>.yaml` definition file when present and valid, falling back to
     * the existing `{# ... #}` twig-comment convention otherwise.
     *
     * Transitional (tailwind-base is introducing `<id>.yaml` as the future
     * canonical definition — see tailwind-base ADR-0007):
     * twig-comment parsing is NOT being removed, just deprioritised. The
     * `<id>.yaml` root carries the same metadata keys (`name`/`usage`/
     * `category`/`render`/`web`/`asana`/`figma`/`drupal`/`description`/
     * `weight`/`responsive`) plus `fields:`/`wp:` — a strict superset of what
     * `parseTwigComment()` returns, so passing it straight through here is
     * safe: `normaliseMetadata()` only ever reads the keys it knows about.
     *
     * A malformed `<id>.yaml` (parse error, or a non-map YAML document) is
     * NOT a hard failure — mirrors `parseTwigComment()`'s own false-return
     * contract for bad/absent input — it silently falls back to the twig
     * comment instead of throwing, so one broken definition file degrades
     * to pre-existing behaviour rather than tripping the caller's
     * \Throwable resilience path (and thus the catalogue-wide warning log)
     * for a file that isn't even the twig template itself.
     *
     * Also reports WHICH source actually supplied the metadata (the sibling
     * `<id>.yaml` or the `<id>.twig` itself) as an absolute path — callers
     * need this to attribute field-normalisation warnings to the real file
     * an author would have to open and fix, not a synthetic `type/id` stand-in.
     *
     * @return array{0: array<string,mixed>|false, 1: string} [metadata, absolute source path]
     *                                    metadata false = neither source produced a
     *                                    usable metadata map (source path is still
     *                                    the twig file — the only candidate left)
     * @throws ParseException on malformed YAML inside the twig comment
     *                        fallback (same contract as parseTwigComment())
     */
    /**
     * @internal Exposed for `Cli\Linter`; not part of the SemVer contract —
     *           see docs/API.md § Other PHP classes. The linter MUST resolve
     *           metadata through the same precedence the runtime uses, or it
     *           lints a document the catalogue never reads (tailwind-base
     *           ADR-0007 retires the twig front-comment per component as its
     *           `<id>.yaml` lands, so the two sources routinely disagree by
     *           design).
     *
     * @return array{0: array<string,mixed>|false, 1: string}
     */
    public function readComponentMetadata(string $dir, string $id, string $twigFile, string $twigContent): array
    {
        $yamlFile = $dir . '/' . $id . '.yaml';

        if (file_exists($yamlFile)) {
            try {
                $parsed = Yaml::parseFile($yamlFile);
                if (is_array($parsed)) {
                    return [$parsed, $yamlFile];
                }
            } catch (\Throwable) {
                // Malformed <id>.yaml — fall through to the twig comment
                // rather than fatally skipping the component.
            }
        }

        return [$this->parseTwigComment($twigContent), $twigFile];
    }

    /**
     * Discover `styleguide.<variant>.twig` siblings in a component/page/doc
     * directory. Filesystem is canonical — display metadata (title,
     * description) is layered on top with the following precedence:
     *
     *   1. The sibling file's OWN first `{# ... #}` comment (same authoring
     *      convention as every component/page front-comment) — `title:` /
     *      `description:`. THIS is the primary convention going forward:
     *      metadata lives next to the markup it describes, not in a
     *      centralised map the author has to keep in sync by id.
     *   2. The component's `variants:` map entry for this id — legacy
     *      fallback, kept for templates written before per-sibling
     *      annotations existed. Accepts a plain string (the title, original
     *      BC shape) or a map `{title?: string, label?: string, description?: string}`
     *      (`label` is a legacy alias for `title`; `title` wins when both
     *      are present).
     *   3. The id itself, for title; `''` for description.
     *
     * An id with no matching file is silently dropped (never fabricates a
     * phantom variant) — that check happens before any of the above, on the
     * glob result, not on the YAML map.
     *
     * A missing or malformed sibling annotation is NOT an error: absence
     * degrades to `false` and malformed YAML is caught right here at the
     * call-site, so this method falls straight through to the map (or the id) exactly
     * as if the sibling carried no comment at all — one broken variant
     * annotation must never kill the whole catalogue walk (same resilience
     * contract as parse()/parseAll(), just without a getWarnings() entry:
     * this is a per-FIELD fallback within an otherwise-successful variant,
     * not a skipped file).
     *
     * Plain `styleguide.twig` (no captured group) is the implicit default
     * and is never itself listed here — callers add the default separately
     * (or, for the SPA, prepend it client-side; see docs/API.md).
     *
     * @param array<string,mixed> $metadata
     * @return list<array{id:string,title:string,description:string}>
     */
    private function discoverVariants(string $dir, array $metadata): array
    {
        $entries = is_array($metadata['variants'] ?? null) ? $metadata['variants'] : [];

        $variants = [];
        foreach (glob($dir . '/styleguide.*.twig') ?: [] as $file) {
            if (!preg_match(self::VARIANT_FILE_PATTERN, basename($file), $m)) {
                continue; // not a canonical variant filename (e.g. a stray .bak) — skip, don't error
            }
            $id = $m[1];
            [$mapTitle, $mapDescription] = self::normaliseVariantEntry($id, $entries[$id] ?? null);
            try {
                $annotation = $this->parseTwigComment((string) file_get_contents($file));
            } catch (ParseException) {
                // per-FIELD fallback within an otherwise-successful variant —
                // see the method docblock; deliberately NOT a getWarnings() entry
                $annotation = false;
            }
            $title = is_array($annotation) && is_string($annotation['title'] ?? null)
                ? $annotation['title']
                : $mapTitle;
            $description = is_array($annotation) && is_string($annotation['description'] ?? null)
                ? $annotation['description']
                : $mapDescription;
            $variants[] = ['id' => $id, 'title' => $title, 'description' => $description];
        }

        // Sort by id — equivalent to filename order (id is the only variable
        // segment) and deterministic across filesystems/OSes, unlike glob()'s
        // platform-dependent return order.
        usort($variants, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $variants;
    }

    /**
     * Coerce a single `variants.<id>` YAML value (the legacy map fallback)
     * into a [title, description] pair. See {@see self::discoverVariants()}
     * for the full precedence chain and the accepted shapes.
     *
     * @return array{0:string,1:string}
     */
    private static function normaliseVariantEntry(string $id, mixed $entry): array
    {
        if (is_string($entry)) {
            return [$entry, ''];
        }
        if (is_array($entry)) {
            $title = is_string($entry['title'] ?? null)
                ? $entry['title']
                : (is_string($entry['label'] ?? null) ? $entry['label'] : $id); // `label` = legacy alias
            $description = is_string($entry['description'] ?? null) ? $entry['description'] : '';
            return [$title, $description];
        }
        // Absent entry, or garbage (int/bool/null/list…) — same fallback as
        // "no title supplied": id as title, no description. Never throws.
        return [$id, ''];
    }

    /**
     * @param array<string,mixed> $metadata
     * @param list<array{id:string,title:string,description:string}> $variants
     * @return array<string,mixed>
     */
    private function normaliseMetadata(
        string $id,
        string $type,
        array $metadata,
        bool $hasStyleguide,
        bool $hasDefaultFixture,
        array $variants,
        string $sourceFile,
    ): array {
        return [
            'id' => $id,
            'name' => $metadata['name'],
            'category' => $metadata['category'] ?? '',
            'description' => $metadata['description'] ?? '',
            'asana' => $metadata['asana'] ?? '',
            'figma' => $metadata['figma'] ?? '',
            'drupal' => $metadata['drupal'] ?? '',
            'web' => $metadata['web'] ?? '',
            'weight' => isset($metadata['weight']) ? (int) $metadata['weight'] : 50,
            'usage' => self::normaliseUsage($metadata['usage'] ?? null),
            'fields' => $this->normaliseFields($sourceFile, $metadata['fields'] ?? null),
            // Canonical render mode for the iframe wrapper — drives the
            // padding wrapper, --header-height reset, and body min-height
            // in render-cell.twig.
            'render' => self::normaliseRender($metadata['render'] ?? null),
            // Closed enum declaring what the component *is* — authorial
            // intent, never guessed (see normaliseKind()'s docblock for why
            // this differs from `render` above). '' when the author hasn't
            // declared it yet.
            'kind' => self::normaliseKind($metadata['kind'] ?? null),
            // Optional per-entry class string applied to the render iframe's
            // <body> (merged after the global `iframe.body_class`). Lets a page
            // declare its body background/state — e.g. `body_class: "bg-secondary-500
            // body-secondary"` — mirroring what the production layout puts on
            // <body>, instead of wrapping content in a styleguide-only <div>.
            'body_class' => $metadata['body_class'] ?? '',
            // General SPA-chrome flag (component/page/doc). false → SPA hides
            // the responsive width toolbar and pins the preview to full width.
            // Default true; only an explicit YAML `false` opts out — strict
            // !== false so strings, integers, or typos never disable it.
            //
            // `doc` is the one exception: a doc page is prose (like
            // foundations/overview), never a widget meant to be previewed at
            // different breakpoints, so the rule wins over the YAML — the key
            // is ignored entirely for this type, even an explicit
            // `responsive: true` in a doc's front-comment stays false. This is
            // enforced HERE (not just via the SPA's `responsive !== false`
            // gate) so the rule is data-driven: every API consumer (SPA,
            // `vendor/bin/styleguide`, a future integration) sees the same
            // `responsive: false` for every doc without having to know the
            // "docs are never responsive" rule itself.
            'responsive' => $type === 'doc' ? false : ($metadata['responsive'] ?? true) !== false,
            // True when the entry has SOME renderable fixture — the bare
            // `styleguide.twig` sibling, the legacy `styleguide:` YAML
            // presence flag, OR (additive, v1.1.0) at least one discovered
            // `styleguide.<variant>.twig` sibling. A component may ship
            // ONLY named variants with no bare default at all — it must
            // still surface in the sidebar/palette/overview like any other
            // renderable entry (see `catalog.js`'s `has_styleguide !==
            // false` filters), it just has no "Default" fixture. Callers
            // that specifically need to know whether the UNNAMED default
            // exists (e.g. the SPA variant grid deciding whether to show a
            // synthetic Default tile) want `has_default_variant` below
            // instead.
            'has_styleguide' => $hasStyleguide,
            // Additive (v1.1.0). True only when `<id>/styleguide.twig`
            // itself exists on disk — narrower than `has_styleguide` above,
            // which also goes true from the legacy `styleguide:` flag or
            // from named variants alone. Exists so a caller can tell "there
            // is a real default fixture to render" apart from "there is
            // SOME fixture" — the render endpoint's fallback chain still
            // resolves a no-variant request to the component's own
            // `<slug>.twig` when this is false, but that's the raw
            // production template, not a styleguide-authored fixture, so
            // the SPA's variant grid uses this flag to skip the Default
            // tile entirely rather than pointing it at that fallback.
            'has_default_variant' => $hasDefaultFixture,
            // Additive (v0.9.0). Auto-discovered styleguide.<variant>.twig
            // siblings; [] when none exist — every pre-Phase-4 template keeps
            // this BC default. Default variant is implicit, never listed here.
            // Each record's `title`/`description` come from the sibling's own
            // front-comment annotation first, falling back to the component's
            // legacy `variants:` map, then to the id (title only) — see
            // discoverVariants().
            'variants' => $variants,
        ];
    }

    /**
     * Canonical fields list (ADR-0002): both doctrines normalise to the same
     * core keys; all other authored keys pass through verbatim. Malformed
     * entries are skipped and surface in getWarnings() / GET /api/health.
     *
     * @return list<array<string,mixed>>
     */
    private function normaliseFields(string $relativeSourceFile, mixed $fields): array
    {
        $result = FieldsNormalizer::normalize($fields);
        foreach ($result['warnings'] as $warning) {
            $entry = ['file' => $relativeSourceFile, 'error' => $warning];
            // Idempotent within one request/instance, same rationale as
            // recordWarning() — a caller that parses the same file twice
            // (e.g. parse() then parseAll()) must not accumulate duplicate
            // entries. Deduped by the full (file, error) pair rather than
            // file alone: unlike recordWarning() (one \Throwable per file,
            // parsing aborts on the first), a single file can legitimately
            // carry several DISTINCT malformed-field warnings and all of
            // them are real, wanted diagnostics.
            if (!in_array($entry, $this->warnings, true)) {
                $this->warnings[] = $entry;
            }
        }
        return $result['fields'];
    }

    private function recordWarning(string $relativeFile, \Throwable $e): void
    {
        foreach ($this->warnings as $warning) {
            if ($warning['file'] === $relativeFile) {
                // Idempotent within one request/instance — a caller that
                // queries the same type twice shouldn't accumulate
                // duplicate entries for the same broken file.
                return;
            }
        }
        $this->warnings[] = ['file' => $relativeFile, 'error' => $e->getMessage()];
    }

    private function relativePath(string $absolutePath): string
    {
        return ltrim(substr($absolutePath, strlen($this->templatesPath)), '/');
    }
}
