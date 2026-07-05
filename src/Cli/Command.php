<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Cli;

use Parisek\Styleguide\ComponentParser;

/**
 * CLI surface for the styleguide component catalogue.
 *
 * Thin wrapper around ComponentParser that exposes list/show subcommands so
 * AI assistants and tooling can read the catalogue without booting the SPA.
 */
final class Command
{
    private const ALLOWED_TYPES = ['component', 'page', 'doc'];

    /**
     * @param array<int,string> $argv  Arguments excluding program name.
     * @param resource $stdout
     * @param resource $stderr
     */
    public function run(array $argv, $stdout, $stderr): int
    {
        if ($argv === [] || $argv[0] === '--help' || $argv[0] === '-h') {
            fwrite($stdout, $this->helpText());
            return $argv === [] ? 1 : 0;
        }

        $command = $argv[0];
        [$flags, $positional] = $this->parseFlags(array_slice($argv, 1));

        if (isset($flags['help'])) {
            fwrite($stdout, $this->helpText());
            return 0;
        }

        if ($command === 'lint') {
            return $this->runLint($flags, $stdout, $stderr);
        }

        $rawType = $flags['type'] ?? 'component';
        if (!is_string($rawType) || !in_array($rawType, self::ALLOWED_TYPES, true)) {
            fwrite($stderr, "Invalid --type. Allowed: component, page, doc.\n");
            return 1;
        }
        $type = $rawType;

        if ($command !== 'list' && $command !== 'show') {
            fwrite($stderr, sprintf("Unknown command: %s\nRun: styleguide --help\n", $command));
            return 1;
        }

        if ($command === 'show' && $positional === []) {
            fwrite($stderr, sprintf("Missing %s id. Usage: styleguide show <id>\n", $type));
            return 1;
        }

        $templates = $this->resolveTemplatesPath($flags['templates'] ?? null);
        if ($templates === null) {
            fwrite($stderr, "templates/ directory not found. Use --templates=<path>.\n");
            return 1;
        }

        $parser = new ComponentParser($templates);
        $pretty = isset($flags['pretty']);

        if ($command === 'list') {
            $data = $parser->parseAll($type);
            return $this->writeJson($data, $pretty, $stdout, $stderr);
        }

        $id = $positional[0];
        $data = $parser->parse($type, $id);
        if ($data === null) {
            $label = ucfirst($type);
            fwrite($stderr, sprintf("%s \"%s\" not found.\n", $label, $id));
            return 1;
        }
        return $this->writeJson($data, $pretty, $stdout, $stderr);
    }

    /**
     * @param array<string,string|true> $flags
     * @param resource $stdout
     * @param resource $stderr
     */
    private function runLint(array $flags, $stdout, $stderr): int
    {
        $rawType = $flags['type'] ?? null;
        if ($rawType !== null && (!is_string($rawType) || !in_array($rawType, self::ALLOWED_TYPES, true))) {
            fwrite($stderr, "Invalid --type. Allowed: component, page, doc.\n");
            return 2;
        }

        $rawFormat = $flags['format'] ?? 'text';
        if (!is_string($rawFormat) || !in_array($rawFormat, ['text', 'json'], true)) {
            fwrite($stderr, "Invalid --format. Allowed: text, json.\n");
            return 2;
        }

        $templates = $this->resolveTemplatesPath($flags['templates'] ?? null);
        if ($templates === null) {
            fwrite($stderr, "templates/ directory not found. Use --templates=<path>.\n");
            return 2;
        }

        $types = $rawType === null ? null : [$rawType];
        $findings = (new Linter($templates))->run($types);

        if ($rawFormat === 'json') {
            $payload = array_map(static fn (LintFinding $finding): array => $finding->toArray(), $findings);
            // A JSON-encode failure here is an internal error, not "findings
            // present" — map it to lint's own exit code 2, distinct from
            // writeJson()'s generic list/show 0/1 contract.
            if ($this->writeJson($payload, isset($flags['pretty']), $stdout, $stderr) !== 0) {
                return 2;
            }
        } else {
            foreach ($findings as $finding) {
                fwrite($stdout, sprintf(
                    "%s  %s  %s\n",
                    strtoupper($finding->severity->value),
                    $finding->file,
                    $finding->message,
                ));
            }
        }

        foreach ($findings as $finding) {
            if ($finding->severity->failsBuild()) {
                return 1;
            }
        }
        return 0;
    }

    private function helpText(): string
    {
        return <<<TXT
        Usage: styleguide <command> [options]

        Commands:
          list                List all components (or pages/docs with --type=page|doc) as JSON.
          show <id>           Show full metadata for a single component, page, or doc.
          lint                Report metadata quality issues: unindexed templates, dead
                              `styleguide:` content, broken `usage:` refs, unknown `render:`
                              values, empty descriptions. Non-zero exit for CI.

        Options:
          --type=component|page|doc  Select the catalogue type (default: component;
                                     lint scans all three when omitted).
          --format=text|json     lint only — output format (default: text).
          --templates=<path>     Override the templates/ directory location.
                                 Default: \$STYLEGUIDE_TEMPLATES, then ./templates.
          --pretty               Indent JSON output (use for terminals).
          -h, --help             Show this help.

        Examples:
          vendor/bin/styleguide list
          vendor/bin/styleguide list --type=page --pretty
          vendor/bin/styleguide show button
          vendor/bin/styleguide lint
          vendor/bin/styleguide lint --type=component --format=json

        TXT;
    }

    /**
     * @param array<int,string> $args
     * @return array{0:array<string,string|true>, 1:array<int,string>}
     */
    private function parseFlags(array $args): array
    {
        $flags = [];
        $positional = [];
        foreach ($args as $arg) {
            if ($arg === '-h') {
                $flags['help'] = true;
                continue;
            }
            if (str_starts_with($arg, '--')) {
                $body = substr($arg, 2);
                if (str_contains($body, '=')) {
                    [$name, $value] = explode('=', $body, 2);
                    $flags[$name] = $value;
                } else {
                    $flags[$body] = true;
                }
                continue;
            }
            $positional[] = $arg;
        }
        return [$flags, $positional];
    }

    private function resolveTemplatesPath(string|bool|null $override): ?string
    {
        $envValue = getenv('STYLEGUIDE_TEMPLATES');
        $cwd = getcwd();
        $candidates = [];
        if (is_string($override) && $override !== '') {
            $candidates[] = $override;
        }
        if ($envValue !== false && $envValue !== '') {
            $candidates[] = $envValue;
        }
        if ($cwd !== false) {
            $candidates[] = $cwd . '/templates';
            if (str_ends_with($cwd, '/templates')) {
                $candidates[] = $cwd;
            }
        }
        foreach ($candidates as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * @param array<int|string,mixed> $data
     * @param resource $stdout
     * @param resource $stderr
     */
    private function writeJson(array $data, bool $pretty, $stdout, $stderr): int
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        try {
            $encoded = json_encode($data, $flags);
        } catch (\JsonException $e) {
            fwrite($stderr, sprintf("Failed to encode JSON: %s\n", $e->getMessage()));
            return 1;
        }
        fwrite($stdout, $encoded . "\n");
        return 0;
    }
}
