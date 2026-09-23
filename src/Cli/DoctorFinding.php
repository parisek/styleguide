<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Cli;

/**
 * One `styleguide doctor` finding. Immutable — Doctor only constructs these.
 *
 * Carries a `remedy` that `LintFinding` does not. A lint finding names a rule
 * the reader can look up; a doctor finding is about one project's own
 * configuration, so the fix is specific to it and there is nowhere else to
 * write it down.
 */
final class DoctorFinding
{
    public function __construct(
        public readonly LintSeverity $severity,
        public readonly string $check,
        public readonly string $message,
        public readonly string $remedy = '',
    ) {}

    /**
     * @return array{severity: string, check: string, message: string, remedy: string}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'check' => $this->check,
            'message' => $this->message,
            'remedy' => $this->remedy,
        ];
    }
}
