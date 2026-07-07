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
     * Filename shape of a file-convention variant sibling: `styleguide.<id>.twig`.
     * The captured group is the canonical variant id — same character class as
     * the `?variant=` query-string whitelist in Router::parse(), so a value
     * ComponentParser can ever produce is exactly the set a URL can ever name.
     */
    private const VARIANT_FILE_PATTERN = '/^styleguide\.([a-z0-9-]+)\.twig$/';

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
            $metadata = $this->parseTwigComment($content);

            if (!$metadata || !isset($metadata['name'])) {
                return null;
            }

            $hasStyleguide = file_exists($dir . '/styleguide.twig')
                || isset($metadata['styleguide']);
            $variants = $this->discoverVariants($dir, $metadata);

            return $this->normaliseMetadata($id, $metadata, $hasStyleguide, $variants);
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

            try {
                $metadata = $this->parseTwigComment($content);

                if (!$metadata || !isset($metadata['name'])) {
                    continue;
                }

                $id = $file->getBasename('.twig');
                $hasStyleguide = file_exists($file->getPath() . '/styleguide.twig')
                    || isset($metadata['styleguide']);
                $variants = $this->discoverVariants($file->getPath(), $metadata);

                $items[] = $this->normaliseMetadata($id, $metadata, $hasStyleguide, $variants);
            } catch (\Throwable $e) {
                // One pathological template must not 500 the whole catalogue for
                // every sibling component. Record it and keep walking; surfaced
                // via GET /styleguide/api/health, invisible to the normal
                // component list the SPA renders.
                $this->recordWarning($this->relativePath($file->getPathname()), $e);
                continue;
            }
        }

        usort($items, function ($a, $b): int {
            if ($a['weight'] === $b['weight']) {
                $an = (string) $a['name'];
                $bn = (string) $b['name'];
                if (class_exists(\Collator::class)) {
                    $cmp = (new \Collator('cs'))->compare($an, $bn);
                    return $cmp === false ? 0 : $cmp;
                }
                return strcmp($an, $bn);
            }
            return $a['weight'] <=> $b['weight'];
        });

        return $items;
    }

    /**
     * Extract YAML metadata from the first {# ... #} comment in a Twig file.
     *
     * @return array<string,mixed>|false
     */
    public function parseTwigComment(string $content): array|false
    {
        $content = str_replace("\r", "\n", $content);

        if (preg_match("/{#\s*(.*?)\s*#}/s", $content, $match) && $match[1]) {
            try {
                $yaml = str_replace("\t", '    ', $match[1]);
                $parsed = Yaml::parse($yaml);
                return is_array($parsed) ? $parsed : false;
            } catch (ParseException) {
                return false;
            }
        }

        return false;
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
     * A missing or malformed sibling annotation is NOT an error: parseTwigComment()
     * already degrades absence/malformed YAML to `false` without throwing,
     * so this method falls straight through to the map (or the id) exactly
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
            $annotation = $this->parseTwigComment((string) file_get_contents($file));
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
    private function normaliseMetadata(string $id, array $metadata, bool $hasStyleguide, array $variants): array
    {
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
            'usage' => $metadata['usage'] ?? '',
            'fields' => $metadata['fields'] ?? [],
            // Canonical render mode for the iframe wrapper — drives the
            // padding wrapper, --header-height reset, and body min-height
            // in render-cell.twig.
            'render' => self::normaliseRender($metadata['render'] ?? null),
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
            'responsive' => ($metadata['responsive'] ?? true) !== false,
            'hasStyleguide' => $hasStyleguide,
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
