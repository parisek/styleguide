<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Cli;

/**
 * One `styleguide lint` finding. Immutable — Linter only ever constructs
 * these, never mutates them.
 */
final class LintFinding
{
    public function __construct(
        public readonly LintSeverity $severity,
        public readonly string $file,
        public readonly string $rule,
        public readonly string $message,
    ) {}

    /**
     * @return array{severity: string, file: string, rule: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'file' => $this->file,
            'rule' => $this->rule,
            'message' => $this->message,
        ];
    }
}
