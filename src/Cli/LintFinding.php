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
        public readonly ?string $component = null,
        public readonly ?int $line = null,
        public readonly ?string $fixture = null,
    ) {}

    /**
     * `component`, `line` and `fixture` are present only on a finding that has
     * them (`null-content`; `render-failed` has `fixture`), so every other rule keeps the four-key shape.
     *
     * @return array{severity: string, file: string, rule: string, message: string, component?: string, line?: int, fixture?: string}
     */
    public function toArray(): array
    {
        $data = [
            'severity' => $this->severity->value,
            'file' => $this->file,
            'rule' => $this->rule,
            'message' => $this->message,
        ];
        if ($this->component !== null) {
            $data['component'] = $this->component;
        }
        if ($this->line !== null) {
            $data['line'] = $this->line;
        }
        if ($this->fixture !== null) {
            $data['fixture'] = $this->fixture;
        }

        return $data;
    }
}
