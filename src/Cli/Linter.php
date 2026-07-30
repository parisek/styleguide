<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Cli;

use Parisek\Styleguide\ComponentParser;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Static analysis over a project's templates/ tree.
 *
 * ComponentParser is deliberately lenient at runtime — a template with no
 * parseable `name:` is silently dropped rather than erroring, an invalid
 * `render:` silently falls back to 'inset' (see
 * ComponentParser::normaliseRender()). That leniency is correct for the
 * renderer (one bad template shouldn't break the whole catalogue) but it
 * means real authoring mistakes are invisible until someone notices a
 * missing sidebar entry. Linter re-reads the SAME raw YAML ComponentParser
 * reads (via the public parseTwigComment(), not parseAll() — normalisation
 * is exactly what coerces the values this class needs to see raw) and
 * reports what got silently coerced or dropped, so a consumer's CI can
 * catch it.
 *
 * Pure: never writes to a template, never touches process state.
 * Constructed with a templates/ root, returns findings — nothing else.
 */
final class Linter
{
    /** @var list<string> */
    private const SCAN_TYPES = ['component', 'page', 'doc'];

    /**
     * `styleguide.twig` and its Phase-4 variant siblings
     * (`styleguide.<name>.twig`) are demo fixtures, not catalogue entries —
     * ComponentParser::parseAll() excludes the whole family for the same
     * reason. Shared constant (rather than a private duplicate) so the
     * linter and the catalogue walk can never disagree about which files
     * are fixtures.
     */
    private const STYLEGUIDE_SIBLING_PATTERN = ComponentParser::STYLEGUIDE_SIBLING_PATTERN;

    private readonly string $templatesPath;

    private readonly ComponentParser $parser;

    public function __construct(string $templatesPath)
    {
        $this->templatesPath = rtrim($templatesPath, '/');
        $this->parser = new ComponentParser($this->templatesPath);
    }

    /**
     * @param list<string>|null $types  Restrict the file walk to these
     *        catalogue types; null walks component + page + doc. The
     *        `broken-usage-ref` check always resolves against the FULL
     *        component + page id namespace regardless of this filter —
     *        mirroring frontend/components/usage.js, which resolves
     *        `usage:` tokens against both stores regardless of which view
     *        is open.
     * @return list<LintFinding>
     */
    public function run(?array $types = null): array
    {
        $types = $types ?? self::SCAN_TYPES;
        $knownIds = $this->knownIds();

        $findings = [];
        foreach ($types as $type) {
            foreach ($this->scanFiles($type) as $relPath => $entry) {
                foreach ($this->lintEntry($relPath, $entry, $knownIds) as $finding) {
                    $findings[] = $finding;
                }
            }
        }

        usort(
            $findings,
            static fn(LintFinding $a, LintFinding $b): int => [$a->file, $a->rule] <=> [$b->file, $b->rule],
        );

        return $findings;
    }

    /**
     * @return array<string,true>
     */
    private function knownIds(): array
    {
        $ids = [];
        foreach ([...$this->parser->parseAll('component'), ...$this->parser->parseAll('page')] as $entry) {
            $ids[(string) $entry['id']] = true;
        }
        return $ids;
    }

    /**
     * @return array<string, array{metadata: array<string,mixed>|false, source: string, sidecar_broken: bool, twig_metadata_dead: bool}|ParseException>
     *         relative twig path => the resolved entry, or the ParseException itself when the
     *         winning document is not valid YAML. `metadata` is `false` when the file carries no
     *         metadata at all — three distinct states so lintEntry() can tell an honest partial
     *         apart from a broken catalogue entry. `source` names the document that WON (twig
     *         front-comment or `<id>.yaml`); the two booleans carry what the runtime's own
     *         precedence decision reveals about the losing one.
     */
    private function scanFiles(string $type): array
    {
        $dir = $this->templatesPath . '/' . $type;
        if (!is_dir($dir)) {
            return [];
        }

        $iterator = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $flattened = new \RecursiveIteratorIterator($iterator);
        $regex = new \RegexIterator($flattened, '/\.twig$/');

        $found = [];
        foreach ($regex as $file) {
            if (preg_match(self::STYLEGUIDE_SIBLING_PATTERN, $file->getFilename())) {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            $relPath = $type . substr($file->getPathname(), strlen($dir));
            $sidecar = $file->getPath() . '/' . $file->getBasename('.twig') . '.yaml';
            try {
                // Resolve through the SAME precedence the runtime uses — a
                // sibling `<id>.yaml` wins over the twig front-comment
                // (ComponentParser::readComponentMetadata()). Reading the
                // comment directly linted a document the catalogue never
                // reads: ADR 0007 retires the front-comment per component as
                // its `<id>.yaml` lands, so once a project starts migrating,
                // the two sources disagree BY DESIGN. Downstream that turned a
                // clean migration into 29 phantom `metadata-yaml-invalid`
                // errors — every one of them the component's ordinary leading
                // code comment, parsed as YAML because the metadata block above
                // it had correctly been removed.
                //
                // `$sourceFile` is kept, not discarded: it IS the runtime's own
                // verdict about which document won, and the two reports below
                // are derived from it rather than from a second guess about the
                // precedence rules.
                [$metadata, $sourceFile] = $this->parser->readComponentMetadata(
                    $file->getPath(),
                    $file->getBasename('.twig'),
                    $file->getPathname(),
                    $content,
                );
                $found[$relPath] = [
                    'metadata' => $metadata,
                    // Findings are attributed to the file the metadata actually
                    // came from. Pointing an author at `<id>.twig` for a value
                    // authored in `<id>.yaml` sends them to edit a document the
                    // catalogue ignores — the same class of wrong-file error
                    // this whole PR exists to remove.
                    'source' => $this->relativeSource($sourceFile, $type, $dir),
                    // The runtime SWALLOWS a malformed sidecar and falls back
                    // to the twig comment (ComponentParser::readComponentMetadata()).
                    // That is right for the renderer — one bad file must not
                    // blank a component — but it means the canonical document
                    // can be broken and nothing says so. If a sidecar exists on
                    // disk and did not win, it failed to parse.
                    'sidecar_broken' => is_file($sidecar) && realpath($sourceFile) !== realpath($sidecar),
                    // Both documents present and the sidecar won: the twig
                    // block is dead weight, and editing it is a silent no-op.
                    // ADR 0007 says the twig template keeps only its render
                    // code once the sidecar lands.
                    'twig_metadata_dead' => is_file($sidecar)
                        && realpath($sourceFile) === realpath($sidecar)
                        && self::looksLikeMetadataComment($content),
                ];
            } catch (ParseException $e) {
                // parseTwigComment() throws on malformed YAML since the
                // health-warning change — for the CLI that must become a
                // finding, not a crash (the linter exists precisely to
                // report this class of authoring mistake).
                $found[$relPath] = $e;
            }
        }
        return $found;
    }

    /**
     * True when the file's first `{# … #}` comment parses as a YAML mapping
     * carrying `name:` — i.e. it is a metadata block, not an ordinary code
     * comment.
     *
     * The distinction is the whole point: after ADR 0007 a migrated template's
     * leading comment is usually prose about the markup, and reporting THAT as
     * redundant metadata would be the mirror of the bug this class just fixed.
     */
    private static function looksLikeMetadataComment(string $content): bool
    {
        if (!preg_match('/\{#\s*(.*?)\s*#\}/s', str_replace("\r", "\n", $content), $m) || $m[1] === '') {
            return false;
        }
        try {
            $parsed = Yaml::parse(str_replace("\t", '    ', $m[1]));
        } catch (ParseException) {
            return false;
        }

        return is_array($parsed) && isset($parsed['name']);
    }

    /**
     * Path of the winning metadata document, relative to the templates root and
     * prefixed with its catalogue type — the same shape every other finding's
     * `file` uses.
     */
    private function relativeSource(string $sourceFile, string $type, string $typeDir): string
    {
        return $type . substr($sourceFile, strlen($typeDir));
    }

    /**
     * @param array{metadata: array<string,mixed>|false, source: string, sidecar_broken: bool, twig_metadata_dead: bool}|ParseException $entry
     * @param array<string,true> $knownIds
     * @return list<LintFinding>
     */
    private function lintEntry(string $relPath, array|ParseException $entry, array $knownIds): array
    {
        // The twig path we walked, kept separate from the document the
        // metadata actually came from.
        $twigPath = $relPath;
        if ($entry instanceof ParseException) {
            $metadata = $entry;
        } else {
            $metadata = $entry['metadata'];
            // Attribute metadata findings to the document the value came from,
            // not to the twig file we happened to walk: pointing an author at
            // `<id>.twig` for a value authored in `<id>.yaml` sends them to
            // edit a document the catalogue ignores.
            $relPath = $entry['source'];
        }

        $sourceFindings = [];
        if (!$entry instanceof ParseException && $entry['sidecar_broken']) {
            $sourceFindings[] = new LintFinding(
                LintSeverity::Error,
                preg_replace('/\.twig$/', '.yaml', $relPath) ?? $relPath,
                'sidecar-yaml-invalid',
                'The canonical `<id>.yaml` is not valid YAML — the runtime silently fell back to the twig front-comment, so the component still renders and nothing else reports this.',
            );
        }
        if (!$entry instanceof ParseException && $entry['twig_metadata_dead']) {
            $sourceFindings[] = new LintFinding(
                LintSeverity::Warning,
                // The one finding that belongs on the TWIG file — it is the
                // file to edit. Everything else points at the winning source.
                $twigPath,
                'redundant-twig-metadata',
                'Metadata lives in `<id>.yaml`, which wins — the twig front-comment is dead and editing it changes nothing. Remove it (ADR 0007: the template keeps only its render code).',
            );
        }

        if ($metadata instanceof ParseException) {
            // Distinct from `unindexed`: the author DID write a metadata
            // comment, it just isn't valid YAML — same failure the runtime
            // now surfaces via /styleguide/api/health. Error (not Warning):
            // the component is guaranteed absent from the catalogue.
            return [...$sourceFindings, new LintFinding(
                LintSeverity::Error,
                $relPath,
                'metadata-yaml-invalid',
                'Metadata comment is not valid YAML — dropped from the catalogue. ' . $metadata->getMessage(),
            )];
        }

        if ($metadata === false || !isset($metadata['name'])) {
            return [...$sourceFindings, new LintFinding(
                LintSeverity::Warning,
                $relPath,
                'unindexed',
                'No parseable `name:` key in the first {# #} comment — dropped from the catalogue.',
            )];
        }

        $findings = $sourceFindings;

        if (array_key_exists('styleguide', $metadata) && !is_bool($metadata['styleguide'])) {
            $findings[] = new LintFinding(
                LintSeverity::Warning,
                $relPath,
                'dead-styleguide-content',
                'The `styleguide:` key carries content, but the renderer only checks its presence — move sample data into a sibling styleguide.twig file.',
            );
        }

        if (
            array_key_exists('render', $metadata)
            && !(is_string($metadata['render']) && in_array($metadata['render'], ComponentParser::RENDER_MODES, true))
        ) {
            $raw = is_scalar($metadata['render']) ? (string) $metadata['render'] : gettype($metadata['render']);
            $findings[] = new LintFinding(
                LintSeverity::Error,
                $relPath,
                'unknown-render',
                sprintf(
                    'render: "%s" is not one of %s — silently falls back to "inset".',
                    $raw,
                    implode('|', ComponentParser::RENDER_MODES),
                ),
            );
        }

        if (
            array_key_exists('kind', $metadata)
            && !(is_string($metadata['kind']) && in_array($metadata['kind'], ComponentParser::KIND_VALUES, true))
        ) {
            $raw = is_scalar($metadata['kind']) ? (string) $metadata['kind'] : gettype($metadata['kind']);
            $findings[] = new LintFinding(
                LintSeverity::Error,
                $relPath,
                'unknown-kind',
                sprintf(
                    'kind: "%s" is not one of %s — normalises to "" and the component is treated as undeclared.',
                    $raw,
                    implode('|', ComponentParser::KIND_VALUES),
                ),
            );
        }

        $description = $metadata['description'] ?? '';
        if (!is_string($description) || trim($description) === '') {
            $findings[] = new LintFinding(
                LintSeverity::Notice,
                $relPath,
                'empty-description',
                'No description set — sidebar tooltip and Overview card will be blank.',
            );
        }

        if (isset($metadata['usage'])) {
            foreach (ComponentParser::normaliseUsage($metadata['usage']) as $id) {
                if (!isset($knownIds[$id])) {
                    $findings[] = new LintFinding(
                        LintSeverity::Error,
                        $relPath,
                        'broken-usage-ref',
                        sprintf('usage: references unknown id "%s".', $id),
                    );
                }
            }
        }

        return $findings;
    }
}
