<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Cli;

use Parisek\Styleguide\ComponentParser;
use Parisek\Styleguide\MaintenanceRenderer;
use Parisek\Styleguide\Renderer;
use Parisek\Styleguide\Styleguide;
use Symfony\Component\Yaml\Yaml;

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

        if ($command === 'maintenance:render') {
            return $this->runMaintenanceRender($flags, $stdout, $stderr);
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

        try {
            $ignores = $this->resolveIgnores($templates, $flags['ignore'] ?? null);
        } catch (\RuntimeException $e) {
            // Exit 2 (usage/internal), never 1: a malformed ignore file is not
            // "the templates have findings", and conflating the two would make
            // a typo here look like a lint regression in the tree.
            fwrite($stderr, $e->getMessage() . "\n");
            return 2;
        }

        $types = $rawType === null ? null : [$rawType];
        $linter = new Linter($templates, $ignores);
        $findings = $linter->run($types);
        $suppressed = $linter->suppressedFindings();

        if ($rawFormat === 'json') {
            $payload = array_map(static fn(LintFinding $finding): array => $finding->toArray(), $findings);
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

        // Always announced, and on STDERR so it reaches a human without
        // disturbing either output contract (`--format=json` emits a bare
        // array; the text format is one finding per line). Silent suppression
        // is how a lint gate quietly stops meaning anything.
        if ($suppressed !== []) {
            fwrite($stderr, sprintf("%d finding(s) suppressed by the ignore list.\n", count($suppressed)));
        }

        foreach ($findings as $finding) {
            if ($finding->severity->failsBuild()) {
                return 1;
            }
        }
        return 0;
    }

    /**
     * Render the outage screen to one self-contained HTML file.
     *
     * Everything the render needs is already declared in `styleguide.yaml`:
     * `bootstrap:` locates the templates, the static root and the `.mo`
     * catalogues, and `iframe.css` names the stylesheet a rendered page needs.
     * Reading them here rather than adding flags keeps one source of truth —
     * the file the styleguide itself boots from.
     *
     * @param array<string,string|true> $flags
     * @param resource $stdout
     * @param resource $stderr
     */
    private function runMaintenanceRender(array $flags, $stdout, $stderr): int
    {
        $configPath = $this->resolveConfigPath($flags['config'] ?? null);
        if ($configPath === null) {
            fwrite($stderr, "styleguide.yaml not found. Use --config=<path>.\n");
            return 2;
        }

        try {
            $data = (array) Yaml::parseFile($configPath);
        } catch (\Throwable $e) {
            fwrite($stderr, sprintf("%s is not valid YAML: %s\n", $configPath, $e->getMessage()));
            return 2;
        }

        $bootstrap = is_array($data['bootstrap'] ?? null) ? $data['bootstrap'] : [];
        $project = is_array($data['project'] ?? null) ? $data['project'] : [];
        $iframe = is_array($data['iframe'] ?? null) ? $data['iframe'] : [];

        // A CLI render has no request to take a locale from, so it renders one
        // language: the flag, else the configured default. The drop-in serving
        // the file cannot know a visitor's language either — one file, one
        // language, by design.
        $locale = $flags['locale'] ?? $bootstrap['default_locale'] ?? $project['locale'] ?? 'en';
        if (!is_string($locale) || $locale === '') {
            fwrite($stderr, "--locale must be a locale code, e.g. cs_CZ.\n");
            return 2;
        }

        $baseDir = dirname($configPath);
        $staticPath = isset($bootstrap['static_path']) && is_string($bootstrap['static_path'])
            ? $this->resolvePath((string) $bootstrap['static_path'], $baseDir)
            : $baseDir;

        $cssSource = $flags['css'] ?? Renderer::normaliseStylesheets($iframe['css'] ?? [])[0] ?? null;
        if (!is_string($cssSource) || $cssSource === '') {
            fwrite($stderr, "No stylesheet to inline — set iframe.css in styleguide.yaml or pass --css=<path>.\n");
            return 2;
        }
        $cssFile = $this->resolvePath(ltrim($cssSource, '/'), $staticPath);
        if (!isset($flags['check']) && !is_file($cssFile)) {
            fwrite($stderr, sprintf("Stylesheet not found at %s — build the CSS first.\n", $cssFile));
            return 2;
        }

        $outTemplates = isset($bootstrap['templates_path']) && is_string($bootstrap['templates_path'])
            ? $this->resolvePath((string) $bootstrap['templates_path'], $baseDir)
            : $baseDir . '/templates';
        $out = $flags['out'] ?? $outTemplates . '/' . MaintenanceRenderer::OUTPUT_RELATIVE;
        if (!is_string($out) || $out === '') {
            fwrite($stderr, "--out must be a file path.\n");
            return 2;
        }

        $templatesPath = isset($bootstrap['templates_path']) && is_string($bootstrap['templates_path'])
            ? $this->resolvePath((string) $bootstrap['templates_path'], $baseDir)
            : $baseDir . '/templates';

        try {
            $styleguide = Styleguide::fromYaml($configPath, ['default_locale' => $locale]);
            $renderer = new MaintenanceRenderer($styleguide, $templatesPath);

            if (isset($flags['check'])) {
                return $this->checkOutage($renderer, $out, $locale, $stdout, $stderr);
            }

            $html = $renderer->render((string) file_get_contents($cssFile), substr($locale, 0, 2) ?: 'en', $locale);
        } catch (\Throwable $e) {
            // A failed offline render is a build error, not a broken page:
            // report it and write nothing, so a stale but working file on disk
            // survives rather than being replaced by an error document.
            fwrite($stderr, sprintf("Render failed: %s\n", $e->getMessage()));
            return 1;
        }

        // The default target sits in component/maintenance/, a directory the
        // project has no reason to own yet — only page/maintenance/ carries a
        // template it writes itself. Creating it is what makes the default path
        // work on a project that never rendered the screen before. The test
        // scaffold used to create it by hand, which hid the gap.
        $outDir = dirname($out);
        if (!is_dir($outDir) && !@mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            fwrite($stderr, sprintf("Could not create %s.\n", $outDir));
            return 1;
        }

        // Write beside the target, then rename. A rename is atomic on the
        // same filesystem, so a full disk or a crash mid-write leaves the
        // previous artefact intact — the contract this command documents.
        // file_put_contents() straight onto $out would truncate it first and
        // could then report a short write as success.
        //
        // The temp name carries the pid: two renders of the same target would
        // otherwise share one temp file, and the loser's unlink takes the
        // winner's pending write with it.
        $temp = sprintf('%s.%d.tmp', $out, getmypid());
        $written = file_put_contents($temp, $html);
        if ($written !== strlen($html)) {
            @unlink($temp);
            fwrite($stderr, sprintf("Could not write %s.\n", $out));
            return 1;
        }
        if (!rename($temp, $out)) {
            @unlink($temp);
            fwrite($stderr, sprintf("Could not replace %s.\n", $out));
            return 1;
        }

        fwrite($stdout, sprintf(
            "%s written — %s, %d kB, shell %s.\n",
            $out,
            $locale,
            (int) round(strlen($html) / 1024),
            $renderer->template(),
        ));
        return 0;
    }

    /**
     * `--check`: is the committed screen still current?
     *
     * Compares the fingerprint written into the file against the fingerprint
     * of the inputs as they stand now. Cheap on purpose — it reads a handful
     * of templates and one catalogue, so it can run in CI with no Node, no
     * built stylesheet and no browser.
     *
     * @param resource $stdout
     * @param resource $stderr
     */
    private function checkOutage(
        MaintenanceRenderer $renderer,
        string $out,
        string $locale,
        $stdout,
        $stderr,
    ): int {
        if (!is_file($out)) {
            fwrite($stderr, sprintf("%s does not exist — run `maintenance:render`.\n", $out));
            return 1;
        }

        $found = MaintenanceRenderer::fingerprintOf((string) file_get_contents($out));

        if (null === $found) {
            // Rendered by a version that predates fingerprinting. Treat it as
            // stale rather than as passing: "cannot tell" and "fine" are
            // different answers, and only one of them is safe here.
            fwrite($stderr, sprintf("%s carries no fingerprint — re-render it once to start checking.\n", $out));
            return 1;
        }

        $expected = $renderer->fingerprint($locale);

        if ($found !== $expected) {
            fwrite($stderr, sprintf(
                "%s is stale: templates or translations changed since it was rendered.\n"
                . "  in the file: %s\n  expected:    %s\nRun `maintenance:render` and commit the result.\n",
                $out,
                $found,
                $expected,
            ));
            return 1;
        }

        fwrite($stdout, sprintf("%s is current (%s).\n", $out, $found));
        return 0;
    }

    private function resolveConfigPath(string|bool|null $override): ?string
    {
        $cwd = getcwd();
        $candidates = [];
        if (is_string($override) && $override !== '') {
            $candidates[] = $override;
        }
        if ($cwd !== false) {
            $candidates[] = $cwd . '/styleguide.yaml';
            $candidates[] = $cwd . '/static/styleguide.yaml';
        }
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private function resolvePath(string $path, string $baseDir): string
    {
        return str_starts_with($path, '/') ? $path : $baseDir . '/' . $path;
    }

    /**
     * Explicit `--ignore=<path>` first, then the conventional
     * `<templates>/.styleguide-lintignore.yaml`.
     *
     * The convention lives INSIDE the templates root because that is the only
     * path this command is guaranteed to know (`--templates` / the
     * `STYLEGUIDE_TEMPLATES` env var / `./templates`); anchoring it to a
     * "project root" would mean guessing where that is. The file travels with
     * the tree it describes, and the scanner only ever reads `*.twig`, so it is
     * inert to everything else.
     *
     * A path passed explicitly must exist — a typo'd `--ignore` silently
     * ignoring nothing is the failure mode this whole feature exists to avoid.
     * The conventional location is optional by design.
     *
     * @return list<LintIgnore>
     */
    private function resolveIgnores(string $templates, string|bool|null $override): array
    {
        if (is_string($override) && $override !== '') {
            if (!is_file($override)) {
                throw new \RuntimeException(sprintf('lint ignore file not found: %s', $override));
            }
            return LintIgnore::fromFile($override);
        }

        $conventional = rtrim($templates, '/') . '/.styleguide-lintignore.yaml';
        if (is_file($conventional)) {
            return LintIgnore::fromFile($conventional);
        }

        return [];
    }

    private function helpText(): string
    {
        return <<<TXT
        Usage: styleguide <command> [options]

        Commands:
          list                List all components (or pages/docs with --type=page|doc) as JSON.
          show <id>           Show full metadata for a single component, page, or doc.
          maintenance:render  Render the outage screen to one self-contained HTML file
                              (inlined CSS, no webfont, no script) for a CMS drop-in
                              to serve while the site is down.
          lint                Report metadata quality issues: unindexed templates, dead
                              `styleguide:` content, broken `usage:` refs, unknown `render:`
                              values, empty descriptions. Non-zero exit for CI.

        Options:
          --type=component|page|doc  Select the catalogue type (default: component;
                                     lint scans all three when omitted).
          --format=text|json     lint only — output format (default: text).
          --templates=<path>     Override the templates/ directory location.
                                 Default: \$STYLEGUIDE_TEMPLATES, then ./templates.
          --ignore=<path>        lint only — ignore file. Default:
                                 <templates>/.styleguide-lintignore.yaml when present.
          --pretty               Indent JSON output (use for terminals).
          --config=<path>        maintenance:render only — styleguide.yaml location.
                                 Default: ./styleguide.yaml, then ./static/styleguide.yaml.
          --locale=<code>        maintenance:render only — catalogue to render
                                 (default: bootstrap.default_locale, then project.locale).
          --css=<path>           maintenance:render only — stylesheet to inline
                                 (default: iframe.css from styleguide.yaml).
          --out=<path>           maintenance:render only — output file (default:
                                 <templates_path>/component/maintenance/maintenance.html).
          --check                maintenance:render only — report whether the rendered
                                 file still matches the templates and translations it
                                 came from. Writes nothing. Non-zero when stale.
          -h, --help             Show this help.

        Examples:
          vendor/bin/styleguide list
          vendor/bin/styleguide list --type=page --pretty
          vendor/bin/styleguide show button
          vendor/bin/styleguide lint
          vendor/bin/styleguide lint --type=component --format=json
          vendor/bin/styleguide maintenance:render
          vendor/bin/styleguide maintenance:render --locale=en_US --css=/dist/css/style.min.css

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
