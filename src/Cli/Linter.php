<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Cli;

use Parisek\Styleguide\ComponentParser;
use Symfony\Component\Yaml\Exception\ParseException;

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
            foreach ($this->scanFiles($type) as $relPath => $metadata) {
                foreach ($this->lintEntry($relPath, $metadata, $knownIds) as $finding) {
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
     * @return array<string, array<string,mixed>|false|ParseException>  relative path => raw YAML
     *         metadata, false when the file carries no metadata comment, or the ParseException
     *         itself when the comment IS there but is not valid YAML — three distinct states so
     *         lintEntry() can tell an honest partial apart from a broken catalogue entry.
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
            try {
                $found[$relPath] = $this->parser->parseTwigComment($content);
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
     * @param array<string,mixed>|false|ParseException $metadata
     * @param array<string,true> $knownIds
     * @return list<LintFinding>
     */
    private function lintEntry(string $relPath, array|false|ParseException $metadata, array $knownIds): array
    {
        if ($metadata instanceof ParseException) {
            // Distinct from `unindexed`: the author DID write a metadata
            // comment, it just isn't valid YAML — same failure the runtime
            // now surfaces via /styleguide/api/health. Error (not Warning):
            // the component is guaranteed absent from the catalogue.
            return [new LintFinding(
                LintSeverity::Error,
                $relPath,
                'metadata-yaml-invalid',
                'Metadata comment is not valid YAML — dropped from the catalogue. ' . $metadata->getMessage(),
            )];
        }

        if ($metadata === false || !isset($metadata['name'])) {
            return [new LintFinding(
                LintSeverity::Warning,
                $relPath,
                'unindexed',
                'No parseable `name:` key in the first {# #} comment — dropped from the catalogue.',
            )];
        }

        $findings = [];

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
