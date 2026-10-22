<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Cli;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One entry of the lint ignore list: "this rule, on these files, is expected".
 *
 * WHY THIS EXISTS. `Linter` had no ignore mechanism at all, and its exit code
 * is the only thing a consumer's CI can gate on. Any project carrying a
 * legitimately unindexed template — a shared fragment under `page/_partials/`,
 * for example — therefore exits `1` on a clean tree, forever. The gate stops
 * distinguishing anything, so it gets dropped from CI or wrapped in a
 * project-local filter script; both outcomes were observed downstream before
 * this landed.
 *
 * The design is deliberately grudging, because a suppression list is how a
 * lint layer goes quiet without anyone deciding that it should:
 *
 *   - `reason` is REQUIRED. An entry nobody can justify in one line is an
 *     entry nobody will dare delete later.
 *   - Entries are per (file pattern, rule) — never "ignore this rule" or
 *     "ignore this file". A blanket rule-level mute would hide the next
 *     occurrence in code written months later, which is precisely the finding
 *     worth having.
 *   - An entry that matches nothing is itself reported (`stale-ignore`), so
 *     the list cannot outlive the thing it excuses.
 */
final class LintIgnore
{
    /**
     * @param string $file Relative path as the linter reports it
     *                     (`page/_partials/header.twig`), or an `fnmatch`
     *                     pattern (`page/_partials/*`). Patterns are what make
     *                     a whole fragment directory one entry instead of a
     *                     roster that has to be edited on every new file.
     * @param string|null $component The called component, for a rule whose
     *                     finding is a call from one template to another
     *                     (`null-content`). One template can call several
     *                     components, so the file alone is too coarse a key:
     *                     ignoring `component_pagination(content.pagination)`
     *                     must not also ignore the next optional sub-object
     *                     someone adds to the same template. Underscores and
     *                     hyphens are the same name, as in Twig.
     */
    public function __construct(
        public readonly string $file,
        public readonly string $rule,
        public readonly string $reason,
        public readonly ?string $component = null,
    ) {}

    /**
     * Rules whose findings carry a `component`, and so need one in the entry.
     */
    public const COMPONENT_RULES = ['null-content'];

    public function matches(LintFinding $finding): bool
    {
        if ($finding->rule !== $this->rule) {
            return false;
        }
        if ($this->component !== null && str_replace('_', '-', $this->component) !== $finding->component) {
            return false;
        }

        // FNM_PATHNAME is deliberately NOT set: `page/_partials/*` should cover
        // nested fragments too. A fragment directory is a subtree, not one
        // level, and requiring `page/_partials/**` (which fnmatch does not
        // support anyway) would just push authors toward listing files.
        return $finding->file === $this->file || fnmatch($this->file, $finding->file);
    }

    /**
     * Read an ignore file.
     *
     * Shape (every key required — see the class docblock for why `reason` is
     * not optional):
     *
     *     ignore:
     *       - file: page/_partials/*
     *         rule: unindexed
     *         reason: shared page fragments, not catalogue entries
     *       - file: component/article-list/article-list.twig
     *         rule: null-content
     *         component: pagination   # required for null-content, forbidden elsewhere
     *         reason: pagination is optional on a one-page list
     *
     * @return list<LintIgnore>
     * @throws \RuntimeException on unreadable, unparseable, or malformed input —
     *         never silently degrades to "no ignores". A typo that quietly
     *         disabled the whole list would show up as a sudden flood of
     *         findings, which reads like a regression in the templates rather
     *         than in this file, and the author would go looking in the wrong
     *         place.
     */
    public static function fromFile(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('lint ignore file is not readable: %s', $path));
        }

        try {
            $parsed = Yaml::parse($raw);
        } catch (ParseException $e) {
            throw new \RuntimeException(sprintf('lint ignore file is not valid YAML: %s — %s', $path, $e->getMessage()));
        }

        if ($parsed === null || $parsed === []) {
            return [];
        }
        if (!is_array($parsed) || !isset($parsed['ignore'])) {
            throw new \RuntimeException(sprintf('lint ignore file has no `ignore:` key: %s', $path));
        }
        if (!is_array($parsed['ignore'])) {
            throw new \RuntimeException(sprintf('`ignore:` must be a list: %s', $path));
        }

        $ignores = [];
        foreach ($parsed['ignore'] as $index => $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException(sprintf('ignore[%s] must be a mapping with file/rule/reason: %s', (string) $index, $path));
            }
            foreach (['file', 'rule', 'reason'] as $key) {
                if (!isset($entry[$key]) || !is_string($entry[$key]) || trim($entry[$key]) === '') {
                    throw new \RuntimeException(sprintf('ignore[%s] is missing a non-empty `%s`: %s', (string) $index, $key, $path));
                }
            }
            $component = $entry['component'] ?? null;
            if ($component !== null && (!is_string($component) || trim($component) === '')) {
                throw new \RuntimeException(sprintf('ignore[%s] has an empty `component`: %s', (string) $index, $path));
            }
            // Required where the finding has a component, refused where it has
            // none: an entry with a key that can never match would sit in the
            // list looking like a working exemption.
            $needsComponent = in_array($entry['rule'], self::COMPONENT_RULES, true);
            if ($needsComponent && $component === null) {
                throw new \RuntimeException(sprintf('ignore[%s] for rule `%s` needs a `component` — the file alone would also hide the next optional call in that template: %s', (string) $index, $entry['rule'], $path));
            }
            if (!$needsComponent && $component !== null) {
                throw new \RuntimeException(sprintf('ignore[%s]: `component` applies only to rule %s, not `%s`: %s', (string) $index, implode(', ', self::COMPONENT_RULES), $entry['rule'], $path));
            }
            $ignores[] = new self($entry['file'], $entry['rule'], $entry['reason'], $component === null ? null : str_replace('_', '-', $component));
        }

        return $ignores;
    }
}
