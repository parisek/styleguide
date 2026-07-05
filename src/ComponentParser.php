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

            return $this->normaliseMetadata($id, $metadata, $hasStyleguide);
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
            if ($file->getFilename() === 'styleguide.twig') {
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

                $items[] = $this->normaliseMetadata($id, $metadata, $hasStyleguide);
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
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function normaliseMetadata(string $id, array $metadata, bool $hasStyleguide): array
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
