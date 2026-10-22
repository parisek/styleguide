<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Cli;

use Parisek\Styleguide\Styleguide;

/**
 * The `null-content` rule of `styleguide lint`: renders every fixture and
 * reports each `component_*()` / `page_*()` call that received `null`.
 *
 * `Linter` is static and cannot do this. Whether `content.pagination` is
 * `null` depends on the fixture data, not on the template source, so the only
 * way to know is to render. Kept out of `Linter` for that reason: `Linter`
 * boots no Twig and needs no `styleguide.yaml`; this class needs both.
 *
 * Since a `null` renders as `[]` and writes one log line, a forgotten fixture
 * key no longer fails the render. This rule is what lets CI see it.
 *
 * A fixture that does not render is a finding of its own (`render-failed`).
 * Its trace stops at the failure, so a green `null-content` for it would
 * claim a check that did not finish.
 */
final class NullContentCheck
{
    public const RULE = 'null-content';

    public const RENDER_FAILED_RULE = 'render-failed';

    /**
     * @param list<string>|null $types Restrict the fixtures to these catalogue
     *        types; null renders component + page + doc.
     * @return list<LintFinding>
     */
    public function run(Styleguide $styleguide, ?array $types = null): array
    {
        $findings = [];
        foreach ($styleguide->inventory() as $row) {
            if ($types !== null && !in_array($row['kind'], $types, true)) {
                continue;
            }
            $label = $row['kind'] . '/' . $row['slug'] . ($row['variant'] === null ? '' : ' (variant ' . $row['variant'] . ')');
            $file = $row['kind'] . '/' . $row['slug'] . '/' . $row['slug'] . '.twig';

            try {
                $trace = $styleguide->renderObserved($row['kind'], $row['slug'], $row['variant']);
            } catch (\LogicException $e) {
                // The environment cannot be observed at all. Not a finding
                // about one fixture; the caller reports it for the whole run.
                throw $e;
            } catch (\Throwable $e) {
                $findings[] = new LintFinding(
                    LintSeverity::Warning,
                    $file,
                    self::RENDER_FAILED_RULE,
                    sprintf('Fixture %s threw while rendering: %s. Its `null-content` check did not finish.', $label, $e->getMessage()),
                    fixture: $label,
                );
                continue;
            }

            if ($trace['status'] >= 400) {
                $findings[] = new LintFinding(
                    LintSeverity::Warning,
                    $file,
                    self::RENDER_FAILED_RULE,
                    sprintf('Fixture %s renders with HTTP %d. Its `null-content` check stopped at the failure.', $label, $trace['status']),
                    fixture: $label,
                );
            }

            foreach ($trace['null_content'] as $null) {
                $template = $null['caller']['template'];
                $line = $null['caller']['line'];
                $helper = $null['kind'] . '_' . str_replace('-', '_', $null['component']);
                $findings[] = new LintFinding(
                    LintSeverity::Warning,
                    $template === null ? $file : self::relativeTemplate($template),
                    self::RULE,
                    sprintf(
                        '%s() gets null content%s, in fixture %s; it renders as []. Add the key to the fixture, or ignore this call in .styleguide-lintignore.yaml if the sub-object is optional.',
                        $helper,
                        $line === null ? '' : ' at line ' . $line,
                        $label,
                    ),
                    $null['component'],
                    $line,
                    $label,
                );
            }
        }

        return $findings;
    }

    /**
     * `@component/card/card.twig` -> `component/card/card.twig`: the path shape
     * every other rule reports, so one ignore file addresses them all.
     */
    private static function relativeTemplate(string $name): string
    {
        return ltrim($name, '@');
    }
}
