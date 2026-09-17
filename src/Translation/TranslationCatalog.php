<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Translation;

/**
 * @internal Implementation detail of `Styleguide`'s `translations_path`
 *           config key. Consumers never construct this directly.
 *
 * Discovers `*.mo` catalogues under a directory and exposes two read
 * operations per the design doc:
 *
 * - `lookup(locale, msgid, context)` — used by the real `__()`/`_x()`/`_n()`/
 *   `_nx()` Twig functions registered when `translations_path` is set.
 * - `entries(locale)` — the whole-catalogue dump the follow-up
 *   translation-matrix docs page (portadesign/tailwind-base#565) will build
 *   on. Distinguishes a MISSING entry (absent from the returned list) from
 *   one with an EMPTY `msgstr` (present, `msgstr === ''`) — collapsing the
 *   two would hide exactly the gap that page exists to show.
 *
 * Locale codes accepted in both forms: a full catalogue name (`cs_CZ`, with
 * or without the `.mo` extension) or a bare two-letter code (`cs`), which
 * resolves to the one discovered catalogue whose name starts with it.
 * Ambiguity (`pt` matching both `pt_BR.mo` and `pt_PT.mo`) throws rather
 * than silently picking one — see the design doc § Locale code
 * normalisation.
 *
 * Source locale: the language the msgids are written in never has a
 * catalogue of its own, so discovery alone would never offer it. When a
 * `$sourceLocale` is given, it is listed and resolvable like a discovered
 * catalogue, and every lookup against it falls back to the msgid. A real
 * `.mo` of the same code wins.
 *
 * Fallback on any miss (unknown locale, missing msgid, unparsable file) is
 * gettext's own: return the msgid unchanged. No exception, no log line —
 * an incomplete catalogue must never break a render.
 */
final class TranslationCatalog
{
    /** @var array<string, string> locale code (catalogue basename, e.g. "cs_CZ") => absolute .mo path */
    private array $catalogueFiles;

    /** @var array<string, MoFile> locale code => parsed catalogue, filled lazily */
    private array $parsed = [];

    /** @var array<string, true> locale codes that failed to parse — cached to avoid re-throwing/re-reading every call */
    private array $broken = [];

    /** @var list<string> every offered locale code: discovered catalogues plus the source locale, sorted */
    private array $locales;

    /** @var string|null the source locale, once it survived the case-insensitive catalogue check */
    private ?string $sourceLocale = null;

    public function __construct(
        private readonly string $translationsPath,
        ?string $sourceLocale = null,
    ) {
        $this->catalogueFiles = self::discover($this->translationsPath);
        $locales = array_keys($this->catalogueFiles);
        // Case-insensitive, because resolveLocaleCode() matches that way:
        // an `en_us.mo` next to `source_locale: en_US` is one locale, and it
        // is the one with the file.
        $discoveredLower = array_map('strtolower', $locales);
        if ($sourceLocale !== null && $sourceLocale !== ''
            && !in_array(strtolower($sourceLocale), $discoveredLower, true)) {
            $this->sourceLocale = $sourceLocale;
            // Only offer it when a request for it actually reaches it.
            // `source_locale: en` beside `en_GB.mo` resolves to the
            // catalogue (discovered wins, deliberately), so listing `en`
            // would advertise a switcher entry that silently renders
            // en_GB — an unreachable choice is worse than an absent one.
            // Such a project states its region (`en_US`) to get the entry.
            if ($this->resolveLocaleCode($sourceLocale) === $sourceLocale) {
                $locales[] = $sourceLocale;
                sort($locales);
            } else {
                $this->sourceLocale = null;
            }
        }
        $this->locales = $locales;
    }

    /**
     * @return array<string, string> locale code => absolute .mo path, sorted by locale code
     */
    private static function discover(string $translationsPath): array
    {
        if (!is_dir($translationsPath)) {
            return [];
        }
        $files = glob(rtrim($translationsPath, '/') . '/*.mo') ?: [];
        $catalogues = [];
        foreach ($files as $file) {
            $locale = basename($file, '.mo');
            if ($locale === '') {
                continue;
            }
            $catalogues[$locale] = $file;
        }
        ksort($catalogues);
        return $catalogues;
    }

    /**
     * @return string[] every discovered locale code (catalogue basename), plus
     *                  the source locale when one was given, sorted
     */
    public function availableLocales(): array
    {
        return $this->locales;
    }

    /**
     * Resolve a requested locale code (either form) to the exact discovered
     * catalogue code, or null when nothing matches. Throws when a bare
     * two-letter (or otherwise non-exact) code matches more than one
     * catalogue — see class docblock.
     */
    public function resolveLocaleCode(string $requested): ?string
    {
        $requested = trim($requested);
        if ($requested === '') {
            return null;
        }
        $requested = str_ends_with(strtolower($requested), '.mo') ? substr($requested, 0, -3) : $requested;

        // Exact match against a DISCOVERED catalogue (case-sensitive —
        // catalogue codes are conventionally `xx_YY`) wins outright,
        // ambiguity or not: an exact "cs_CZ" request must resolve to
        // cs_CZ.mo even if some OTHER short code also happens to
        // prefix-match it. The source locale is deliberately not consulted
        // here: a two-letter `source_locale: en` must not shadow the
        // `en_GB.mo` a request for "en" used to reach.
        if (isset($this->catalogueFiles[$requested])) {
            return $requested;
        }

        $prefix = strtolower($requested) . '_';
        $exactLower = strtolower($requested);
        $matches = [];
        // Discovered catalogues only. The source locale is handled after
        // this block, so it can neither shadow a real catalogue nor make a
        // previously unique prefix ambiguous.
        foreach (array_keys($this->catalogueFiles) as $code) {
            $lower = strtolower($code);
            if ($lower === $exactLower || str_starts_with($lower, $prefix)) {
                $matches[] = $code;
            }
        }

        if (count($matches) === 0) {
            // Last resort: the source locale, matched the same way.
            if ($this->sourceLocale !== null) {
                $lower = strtolower($this->sourceLocale);
                if ($lower === $exactLower || str_starts_with($lower, $prefix)) {
                    return $this->sourceLocale;
                }
            }
            return null;
        }
        if (count($matches) > 1) {
            throw new \RuntimeException(sprintf(
                'TranslationCatalog: locale code "%s" is ambiguous — it matches %s. '
                    . 'Use the full catalogue code (e.g. "%s") instead.',
                $requested,
                implode(', ', $matches),
                $matches[0],
            ));
        }
        return $matches[0];
    }

    /**
     * @return MoFile|null null when the locale is unresolvable or the file failed to parse
     */
    private function catalogueFor(string $locale): ?MoFile
    {
        $resolved = $this->resolveLocaleCode($locale);
        if ($resolved === null || !isset($this->catalogueFiles[$resolved])) {
            // Unknown, or the source locale: no file, so every lookup
            // falls back to the msgid.
            return null;
        }
        if (isset($this->broken[$resolved])) {
            return null;
        }
        if (isset($this->parsed[$resolved])) {
            return $this->parsed[$resolved];
        }
        try {
            $mo = MoFile::fromFile($this->catalogueFiles[$resolved]);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[parisek/styleguide] TranslationCatalog: failed to parse "%s": %s',
                $this->catalogueFiles[$resolved],
                $e->getMessage(),
            ));
            $this->broken[$resolved] = true;
            return null;
        }
        $this->parsed[$resolved] = $mo;
        return $mo;
    }

    /**
     * Singular lookup — the fallback path `__()`/`_x()` fall through to.
     * Returns the msgid unchanged on any miss, per the class docblock.
     */
    public function lookup(string $locale, string $msgid, string $context = ''): string
    {
        $mo = $this->catalogueFor($locale);
        if ($mo === null) {
            return $msgid;
        }
        $entry = $mo->find($msgid, $context);
        if ($entry === null || $entry['msgstr'] === '') {
            // An empty msgstr is gettext's own encoding of "not translated" —
            // every compiler emits one for a string the translator skipped.
            // Returning it verbatim deletes the visible text: the source
            // string disappears from the page instead of showing through
            // untranslated. entries() keeps the missing-vs-empty distinction
            // for the catalogue audit, so nothing is lost by falling back.
            return $msgid;
        }
        return $entry['msgstr'];
    }

    /**
     * Plural lookup — backs `_n()`/`_nx()`. `$number` selects the variant
     * via the catalogue's OWN `Plural-Forms` rule (never assumed — see the
     * design doc), falling back to `$single`/`$plural` (the germanic n != 1
     * rule) on any miss, exactly like {@see self::lookup()}.
     */
    public function lookupPlural(
        string $locale,
        string $single,
        string $plural,
        int $number,
        string $context = '',
    ): string {
        $mo = $this->catalogueFor($locale);
        $fallback = $number === 1 ? $single : $plural;
        if ($mo === null) {
            return $fallback;
        }
        $entry = $mo->find($single, $context);
        if ($entry === null || $entry['plurals'] === []) {
            return $fallback;
        }
        $index = PluralForms::compile($mo->pluralForms())($number);
        $variant = $entry['plurals'][$index] ?? $entry['plurals'][array_key_last($entry['plurals'])];

        // Same rule as the singular path: an empty variant means the
        // translator skipped that form, not that the form renders as nothing.
        return $variant === '' ? $fallback : $variant;
    }

    /**
     * @return array<array{context: ?string, msgid: string, msgstr: string, plurals: string[]}>
     */
    public function entries(string $locale): array
    {
        $mo = $this->catalogueFor($locale);
        return $mo?->entries() ?? [];
    }
}
