<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Cli;

/**
 * Severity of a single `styleguide lint` finding. Ordering matters only for
 * the CLI's exit-code contract: Warning and Error both fail a CI run (exit
 * 1); Notice is informational only (exit 0) — it feeds the metadata-backfill
 * workflow (see the styleguide-render-tagger skill) without blocking a build
 * over something as harmless as a missing description.
 */
enum LintSeverity: string
{
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';

    public function failsBuild(): bool
    {
        return $this !== self::Notice;
    }
}
