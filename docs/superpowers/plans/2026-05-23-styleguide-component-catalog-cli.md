# Component Catalog CLI — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a `vendor/bin/styleguide` CLI that lists components/pages and shows one component's full metadata, by wrapping the existing `ComponentParser`. No new business logic, no new dependencies.

**Architecture:** A single `Parisek\Styleguide\Cli\Command` class owns argv parsing, templates-path resolution, dispatch, and JSON output (testable in-process with mock streams). A thin `bin/styleguide` script bootstraps Composer's autoloader and calls `Command::run()` with the real `argv`/`STDOUT`/`STDERR`. The `ComponentParser` already returns the canonical record shape — the CLI only re-serialises it.

**Tech Stack:** PHP 8.3+, PHPUnit 11/12, PHPStan level 5. No new dependencies (no `symfony/console`, no `symfony/process`). Hand-rolled argv parser is enough for two subcommands and four flags.

**Spec:** `docs/superpowers/specs/2026-05-23-styleguide-component-catalog-cli-design.md`

---

## File map

| File | Status | Responsibility |
| --- | --- | --- |
| `src/Cli/Command.php` | Create | Argv parsing, templates resolution, dispatch (list/show), JSON output, exit codes |
| `bin/styleguide` | Create | Executable shim: find autoloader, call `Command::run()` |
| `composer.json` | Modify | Add `"bin": ["bin/styleguide"]` |
| `tests/Cli/CommandTest.php` | Create | Unit tests for `Command::run()` with mock streams |
| `tests/Cli/BinSmokeTest.php` | Create | End-to-end smoke test invoking `bin/styleguide` as a subprocess |
| `tests/fixtures/templates/page/landing/landing.twig` | Create | Fixture for `--type=page` testing |
| `README.md` | Modify | Add "Programmatic catalogue (CLI)" section |

The new namespace `Parisek\Styleguide\Cli\` mirrors `Parisek\Styleguide\Api\` — both are transport layers wrapping the same parser.

---

## Task 1: Bootstrap `Command` class with `list` happy path (TDD)

**Files:**
- Create: `tests/Cli/CommandTest.php`
- Create: `src/Cli/Command.php`

- [ ] **Step 1.1: Write the failing test**

Create `tests/Cli/CommandTest.php` with:

```php
<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Cli;

use Parisek\Styleguide\Cli\Command;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommandTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = __DIR__ . '/../fixtures/templates';
    }

    /**
     * @param array<int,string> $argv
     * @return array{0:int, 1:string, 2:string}  [exitCode, stdout, stderr]
     */
    private function runCli(array $argv): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $exit = (new Command())->run($argv, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);
        return [
            $exit,
            (string) stream_get_contents($stdout),
            (string) stream_get_contents($stderr),
        ];
    }

    #[Test]
    public function list_returns_all_components_as_json(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'list',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(0, $exit, "stderr: $stderr");
        self::assertSame('', $stderr);

        $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(2, $decoded);
        self::assertSame('Another', $decoded[0]['name'], 'weight 10 first');
        self::assertSame('Sample', $decoded[1]['name'], 'weight 20 second');
    }
}
```

- [ ] **Step 1.2: Run the test, verify it fails for the expected reason**

Run: `composer test -- --filter CommandTest`
Expected: FAIL with `Class "Parisek\Styleguide\Cli\Command" not found`.

- [ ] **Step 1.3: Create minimal `Command` class**

Create `src/Cli/Command.php`:

```php
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
    /**
     * @param array<int,string> $argv  Arguments excluding program name.
     * @param resource $stdout
     * @param resource $stderr
     */
    public function run(array $argv, $stdout, $stderr): int
    {
        if ($argv === []) {
            fwrite($stderr, "Missing command. Run: styleguide --help\n");
            return 1;
        }

        $command = $argv[0];
        [$flags, $positional] = $this->parseFlags(array_slice($argv, 1));

        $templates = $this->resolveTemplatesPath($flags['templates'] ?? null);
        if ($templates === null) {
            fwrite($stderr, "templates/ directory not found. Use --templates=<path>.\n");
            return 1;
        }

        $parser = new ComponentParser($templates);
        $type = (string) ($flags['type'] ?? 'component');
        $pretty = isset($flags['pretty']);

        if ($command === 'list') {
            $data = $parser->parseAll($type);
            fwrite($stdout, $this->encodeJson($data, $pretty) . "\n");
            return 0;
        }

        fwrite($stderr, sprintf("Unknown command: %s\n", $command));
        return 1;
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

    private function resolveTemplatesPath(string|true|null $override): ?string
    {
        $envValue = getenv('STYLEGUIDE_TEMPLATES');
        $cwd = (string) getcwd();
        $candidates = [];
        if (is_string($override) && $override !== '') {
            $candidates[] = $override;
        }
        if ($envValue !== false && $envValue !== '') {
            $candidates[] = $envValue;
        }
        $candidates[] = $cwd . '/templates';
        if (str_ends_with($cwd, '/templates')) {
            $candidates[] = $cwd;
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
     */
    private function encodeJson(array $data, bool $pretty): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return (string) json_encode($data, $flags);
    }
}
```

- [ ] **Step 1.4: Run the test, verify it passes**

Run: `composer test -- --filter CommandTest`
Expected: PASS — 1 test, multiple assertions, 0 failures.

- [ ] **Step 1.5: Commit**

```bash
git add src/Cli/Command.php tests/Cli/CommandTest.php
git commit -m "feat(cli): list command wraps ComponentParser::parseAll

First slice of the catalog CLI: a Command class that parses argv, resolves
the templates directory, and emits the same normalised JSON as the existing
HTTP endpoint. No new business logic - parser is the single source of truth.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `show` subcommand (happy path + not-found) (TDD)

**Files:**
- Modify: `tests/Cli/CommandTest.php`
- Modify: `src/Cli/Command.php`

- [ ] **Step 2.1: Write the failing tests**

Append to `tests/Cli/CommandTest.php` inside the class:

```php
    #[Test]
    public function show_returns_single_component_as_json(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'show',
            'sample',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(0, $exit, "stderr: $stderr");
        self::assertSame('', $stderr);

        $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('sample', $decoded['id']);
        self::assertSame('Sample', $decoded['name']);
        self::assertSame('Block', $decoded['category']);
        self::assertSame(20, $decoded['weight']);
    }

    #[Test]
    public function show_returns_exit_1_and_stderr_message_when_not_found(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'show',
            'nonexistent',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Component "nonexistent" not found', $stderr);
    }

    #[Test]
    public function show_returns_exit_1_when_id_is_missing(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'show',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Missing component id', $stderr);
    }
```

- [ ] **Step 2.2: Run the tests, verify they fail**

Run: `composer test -- --filter CommandTest`
Expected: 3 new failures — show returns "Unknown command: show".

- [ ] **Step 2.3: Add the `show` branch to `Command::run()`**

In `src/Cli/Command.php`, replace the `if ($command === 'list') { … }` block plus the unknown-command fallback with:

```php
        if ($command === 'list') {
            $data = $parser->parseAll($type);
            fwrite($stdout, $this->encodeJson($data, $pretty) . "\n");
            return 0;
        }

        if ($command === 'show') {
            if ($positional === []) {
                fwrite($stderr, "Missing component id. Usage: styleguide show <id>\n");
                return 1;
            }
            $id = $positional[0];
            $data = $parser->parse($type, $id);
            if ($data === null) {
                $label = ucfirst($type);
                fwrite($stderr, sprintf("%s \"%s\" not found.\n", $label, $id));
                return 1;
            }
            fwrite($stdout, $this->encodeJson($data, $pretty) . "\n");
            return 0;
        }

        fwrite($stderr, sprintf("Unknown command: %s\n", $command));
        return 1;
```

- [ ] **Step 2.4: Run tests, verify they pass**

Run: `composer test -- --filter CommandTest`
Expected: PASS — 4 tests total.

- [ ] **Step 2.5: Commit**

```bash
git add src/Cli/Command.php tests/Cli/CommandTest.php
git commit -m "feat(cli): show command + not-found / missing-id errors

Adds 'show <id>' subcommand returning the full normalised record from
ComponentParser::parse(). Missing component and missing id both exit 1
with a stderr message and empty stdout, so 'cmd 2>/dev/null | jq' stays
predictable.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: `--type=page` support (TDD, requires new fixture)

**Files:**
- Create: `tests/fixtures/templates/page/landing/landing.twig`
- Modify: `tests/Cli/CommandTest.php`
- Modify (only if existing test breaks): `tests/ComponentParserTest.php`

- [ ] **Step 3.1: Add a page fixture**

Create `tests/fixtures/templates/page/landing/landing.twig`:

```twig
{#
name: "Landing"
category: "Marketing"
description: "Landing page fixture for CLI tests"
weight: 30
#}
<div class="landing">Landing page</div>
```

- [ ] **Step 3.2: Verify the existing parser test breaks (we know it will)**

Run: `composer test -- --filter ComponentParserTest`
Expected: FAIL on `returns_empty_array_for_missing_directory` — it asserted `parseAll('page')` returned `[]` while `templates/page/` was missing. The new fixture populates that directory, so the assertion is now invalid.

- [ ] **Step 3.3: Repoint the failing assertion at a templates path that has no `/page/` subtree**

In `tests/ComponentParserTest.php`, find the `returns_empty_array_for_missing_directory` test and change the `ComponentParser` argument so that no `page/` subdirectory exists at the supplied path. The fixtures tree has `tests/fixtures/asset-server/` (CSS asset only) — pass that path:

```php
    #[Test]
    public function returns_empty_array_for_missing_directory(): void
    {
        $parser = new ComponentParser(__DIR__ . '/fixtures/asset-server');
        self::assertSame([], $parser->parseAll('page'));
    }
```

Run: `composer test -- --filter ComponentParserTest`
Expected: PASS — the assertion now exercises a path that genuinely has no `page/` subdirectory.

- [ ] **Step 3.4: Write the new CLI tests**

Append to `tests/Cli/CommandTest.php`:

```php
    #[Test]
    public function list_with_type_page_returns_pages(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'list',
            '--type=page',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(0, $exit, "stderr: $stderr");
        $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded);
        self::assertSame('Landing', $decoded[0]['name']);
    }

    #[Test]
    public function show_with_type_page_returns_single_page(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'show',
            'landing',
            '--type=page',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(0, $exit, "stderr: $stderr");
        $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('landing', $decoded['id']);
        self::assertSame('Marketing', $decoded['category']);
    }

    #[Test]
    public function show_page_not_found_uses_page_in_message(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'show',
            'ghost',
            '--type=page',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Page "ghost" not found', $stderr);
    }
```

- [ ] **Step 3.5: Run the suites, verify they pass**

Run: `composer test`
Expected: PASS — all tests across all suites.

The `--type=page` plumbing already works because Task 1 routes `$flags['type']` straight into `ComponentParser::parseAll($type)` and `parse($type, $id)`. No new code in `src/Cli/Command.php` is required.

- [ ] **Step 3.6: Commit**

```bash
git add tests/fixtures/templates/page/landing/landing.twig tests/Cli/CommandTest.php tests/ComponentParserTest.php
git commit -m "feat(cli): --type=page selects page templates

Threads the --type flag through to ComponentParser. Adds a landing page
fixture and repoints the missing-directory assertion in ComponentParserTest
at an unrelated fixtures subtree now that templates/page/ is populated.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Templates path resolution priority + env var (TDD)

**Files:**
- Modify: `tests/Cli/CommandTest.php`

- [ ] **Step 4.1: Write tests covering the resolution order**

Append to `tests/Cli/CommandTest.php`:

```php
    #[Test]
    public function templates_path_from_env_var(): void
    {
        $originalEnv = getenv('STYLEGUIDE_TEMPLATES');
        putenv('STYLEGUIDE_TEMPLATES=' . $this->fixtures);
        try {
            [$exit, $stdout, $stderr] = $this->runCli(['list']);
            self::assertSame(0, $exit, "stderr: $stderr");
            self::assertNotSame('', $stdout, 'expected JSON on stdout');
        } finally {
            if ($originalEnv === false) {
                putenv('STYLEGUIDE_TEMPLATES');
            } else {
                putenv('STYLEGUIDE_TEMPLATES=' . $originalEnv);
            }
        }
    }

    #[Test]
    public function flag_overrides_env_var(): void
    {
        $originalEnv = getenv('STYLEGUIDE_TEMPLATES');
        putenv('STYLEGUIDE_TEMPLATES=/nonexistent/should/not/win');
        try {
            [$exit, $stdout, $stderr] = $this->runCli([
                'list',
                '--templates=' . $this->fixtures,
            ]);
            self::assertSame(0, $exit, "stderr: $stderr");
            $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(2, $decoded);
        } finally {
            if ($originalEnv === false) {
                putenv('STYLEGUIDE_TEMPLATES');
            } else {
                putenv('STYLEGUIDE_TEMPLATES=' . $originalEnv);
            }
        }
    }

    #[Test]
    public function returns_exit_1_when_no_templates_directory_resolvable(): void
    {
        $originalEnv = getenv('STYLEGUIDE_TEMPLATES');
        $originalCwd = getcwd();
        chdir(sys_get_temp_dir());
        putenv('STYLEGUIDE_TEMPLATES');
        try {
            [$exit, $stdout, $stderr] = $this->runCli(['list']);
            self::assertSame(1, $exit);
            self::assertSame('', $stdout);
            self::assertStringContainsString('templates/ directory not found', $stderr);
        } finally {
            if ($originalCwd !== false) {
                chdir($originalCwd);
            }
            if ($originalEnv !== false) {
                putenv('STYLEGUIDE_TEMPLATES=' . $originalEnv);
            }
        }
    }
```

- [ ] **Step 4.2: Run tests, verify they pass**

Run: `composer test -- --filter CommandTest`
Expected: PASS — `Command::resolveTemplatesPath()` already handles all three branches from Task 1.

If any fail, the resolution priority is wrong — confirm the candidate order in `resolveTemplatesPath()` is:
1. `$override` (from `--templates`)
2. `STYLEGUIDE_TEMPLATES` env var
3. `getcwd() . '/templates'`
4. `getcwd()` itself if it ends with `/templates`

- [ ] **Step 4.3: Commit**

```bash
git add tests/Cli/CommandTest.php
git commit -m "test(cli): lock in templates path resolution priority

Covers: flag > env var > cwd/templates > cwd-ends-in-templates. Also
covers the all-candidates-miss case where the CLI must exit 1 with a
clear stderr message.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: `--pretty` flag (TDD)

**Files:**
- Modify: `tests/Cli/CommandTest.php`

- [ ] **Step 5.1: Write the failing test**

Append to `tests/Cli/CommandTest.php`:

```php
    #[Test]
    public function pretty_flag_emits_indented_json(): void
    {
        [$exit, $stdout, ] = $this->runCli([
            'list',
            '--templates=' . $this->fixtures,
        ]);
        self::assertSame(0, $exit);

        [$exitPretty, $stdoutPretty, ] = $this->runCli([
            'list',
            '--templates=' . $this->fixtures,
            '--pretty',
        ]);
        self::assertSame(0, $exitPretty);

        self::assertStringNotContainsString("\n    ", $stdout);
        self::assertStringContainsString("\n    ", $stdoutPretty);

        self::assertSame(
            json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR),
            json_decode(trim($stdoutPretty), true, flags: JSON_THROW_ON_ERROR),
        );
    }
```

- [ ] **Step 5.2: Run the test, verify it passes**

Run: `composer test -- --filter CommandTest`
Expected: PASS — Task 1's `encodeJson()` already toggles `JSON_PRETTY_PRINT`.

- [ ] **Step 5.3: Commit**

```bash
git add tests/Cli/CommandTest.php
git commit -m "test(cli): lock in --pretty produces indented JSON

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: `--help` output + unknown-command error (TDD)

**Files:**
- Modify: `tests/Cli/CommandTest.php`
- Modify: `src/Cli/Command.php`

- [ ] **Step 6.1: Write failing tests**

Append to `tests/Cli/CommandTest.php`:

```php
    #[Test]
    public function help_flag_prints_usage_to_stdout(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli(['--help']);
        self::assertSame(0, $exit);
        self::assertSame('', $stderr);
        self::assertStringContainsString('Usage:', $stdout);
        self::assertStringContainsString('list', $stdout);
        self::assertStringContainsString('show', $stdout);
        self::assertStringContainsString('--type', $stdout);
        self::assertStringContainsString('--templates', $stdout);
        self::assertStringContainsString('--pretty', $stdout);
    }

    #[Test]
    public function dash_h_is_an_alias_for_help(): void
    {
        [$exit, $stdout, ] = $this->runCli(['-h']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('Usage:', $stdout);
    }

    #[Test]
    public function unknown_command_exits_1_with_stderr_hint(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'destroy-all-humans',
            '--templates=' . $this->fixtures,
        ]);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Unknown command', $stderr);
        self::assertStringContainsString('--help', $stderr);
    }
```

- [ ] **Step 6.2: Run tests, verify the help tests fail**

Run: `composer test -- --filter CommandTest`
Expected: FAIL on `help_flag_prints_usage_to_stdout` and `dash_h_is_an_alias_for_help` (currently treated as unknown commands). The unknown-command test passes the exit-code/stdout/"Unknown command" assertions but fails the `--help` hint check.

- [ ] **Step 6.3: Add help handling + improve unknown-command message**

In `src/Cli/Command.php`, replace the entire body of the `run()` method with:

```php
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

        $templates = $this->resolveTemplatesPath($flags['templates'] ?? null);
        if ($templates === null) {
            fwrite($stderr, "templates/ directory not found. Use --templates=<path>.\n");
            return 1;
        }

        $parser = new ComponentParser($templates);
        $type = (string) ($flags['type'] ?? 'component');
        $pretty = isset($flags['pretty']);

        if ($command === 'list') {
            $data = $parser->parseAll($type);
            fwrite($stdout, $this->encodeJson($data, $pretty) . "\n");
            return 0;
        }

        if ($command === 'show') {
            if ($positional === []) {
                fwrite($stderr, "Missing component id. Usage: styleguide show <id>\n");
                return 1;
            }
            $id = $positional[0];
            $data = $parser->parse($type, $id);
            if ($data === null) {
                $label = ucfirst($type);
                fwrite($stderr, sprintf("%s \"%s\" not found.\n", $label, $id));
                return 1;
            }
            fwrite($stdout, $this->encodeJson($data, $pretty) . "\n");
            return 0;
        }

        fwrite($stderr, sprintf("Unknown command: %s\nRun: styleguide --help\n", $command));
        return 1;
    }
```

Add the `helpText()` method to the class (private, place just below `encodeJson()`):

```php
    private function helpText(): string
    {
        return <<<TXT
        Usage: styleguide <command> [options]

        Commands:
          list                List all components (or pages with --type=page) as JSON.
          show <id>           Show full metadata for a single component or page.

        Options:
          --type=component|page  Select the catalogue type (default: component).
          --templates=<path>     Override the templates/ directory location.
                                 Default: \$STYLEGUIDE_TEMPLATES, then ./templates.
          --pretty               Indent JSON output (use for terminals).
          -h, --help             Show this help.

        Examples:
          vendor/bin/styleguide list
          vendor/bin/styleguide list --type=page --pretty
          vendor/bin/styleguide show card/promo

        TXT;
    }
```

Note: The previous empty-argv branch wrote a stderr message; the new version writes help to stdout and returns 1. The Task 1 test asserted that error message — that test was removed in Task 1's scope (only `list_returns_all_components_as_json` was the Task 1 test). No earlier test is invalidated by this change.

- [ ] **Step 6.4: Run tests, verify all pass**

Run: `composer test -- --filter CommandTest`
Expected: PASS — all CommandTest cases (13 total across Tasks 1-6).

- [ ] **Step 6.5: Commit**

```bash
git add src/Cli/Command.php tests/Cli/CommandTest.php
git commit -m "feat(cli): --help / -h prints usage; unknown command hints at help

Adds a single source of truth for usage text and wires --help/-h/no-command
to it. Unknown commands now point users at --help instead of failing
silently with just the bad command name.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: `bin/styleguide` entry script, Composer wiring, end-to-end smoke test

**Files:**
- Create: `bin/styleguide`
- Modify: `composer.json`
- Create: `tests/Cli/BinSmokeTest.php`

- [ ] **Step 7.1: Create the bin script**

Create `bin/styleguide` (no `.php` extension — it's an executable shim):

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

// Locate Composer's autoloader. Two layouts are supported:
//  1. Installed as a dep:           vendor/parisek/styleguide/bin/styleguide
//     -> autoload at ../../../autoload.php
//  2. Running inside the package:   bin/styleguide
//     -> autoload at ../vendor/autoload.php
$candidates = [
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
$loaded = false;
foreach ($candidates as $candidate) {
    if (file_exists($candidate)) {
        require $candidate;
        $loaded = true;
        break;
    }
}
if (!$loaded) {
    fwrite(STDERR, "Composer autoloader not found. Run `composer install`.\n");
    exit(1);
}

$command = new \Parisek\Styleguide\Cli\Command();
exit($command->run(array_slice($argv, 1), STDOUT, STDERR));
```

- [ ] **Step 7.2: Make it executable**

Run: `chmod +x bin/styleguide`
Expected: no output. Verify with `ls -l bin/styleguide` — the file's mode column should contain `x`.

- [ ] **Step 7.3: Add `bin` entry to `composer.json`**

In `composer.json`, after the `"autoload-dev"` block and before `"scripts"`, add a `"bin"` key. The resulting fragment should look like:

```jsonc
    "autoload-dev": {
        "psr-4": {
            "Parisek\\Styleguide\\Tests\\": "tests/"
        }
    },
    "bin": ["bin/styleguide"],
    "scripts": {
```

- [ ] **Step 7.4: Validate composer.json**

Run: `composer validate --no-check-publish`
Expected: `./composer.json is valid`.

- [ ] **Step 7.5: Write the smoke test**

Create `tests/Cli/BinSmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Cli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke: run bin/styleguide as a real subprocess and assert it
 * boots autoload + executes the Command. Complements CommandTest, which
 * exercises Command::run() in-process with mock streams.
 */
final class BinSmokeTest extends TestCase
{
    private string $bin;
    private string $fixtures;

    protected function setUp(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/styleguide');
        $fixtures = realpath(__DIR__ . '/../fixtures/templates');
        self::assertNotFalse($bin, 'bin/styleguide missing');
        self::assertNotFalse($fixtures, 'fixtures path missing');
        $this->bin = $bin;
        $this->fixtures = $fixtures;
    }

    /**
     * @return array{0:int, 1:string, 2:string}
     */
    private function runBin(string $argString): array
    {
        $cmd = sprintf(
            '%s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->bin),
            $argString,
        );
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        self::assertIsResource($proc);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [$exit, $stdout, $stderr];
    }

    #[Test]
    public function bin_list_returns_components_json(): void
    {
        $args = sprintf('list --templates=%s', escapeshellarg($this->fixtures));
        [$exit, $stdout, $stderr] = $this->runBin($args);

        self::assertSame(0, $exit, "stderr: $stderr");
        $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(2, $decoded);
    }

    #[Test]
    public function bin_help_works_without_templates(): void
    {
        [$exit, $stdout, $stderr] = $this->runBin('--help');
        self::assertSame(0, $exit, "stderr: $stderr");
        self::assertStringContainsString('Usage:', $stdout);
    }
}
```

- [ ] **Step 7.6: Run the full test suite**

Run: `composer test`
Expected: PASS — all suites green, including the new `BinSmokeTest`.

- [ ] **Step 7.7: Run PHPStan**

Run: `composer phpstan`
Expected: `[OK] No errors`. If errors surface in `src/Cli/Command.php` (likely candidates: stream `resource` typing, array shapes returned by `parseFlags`), fix them by tightening PHPDoc annotations rather than weakening types.

- [ ] **Step 7.8: Commit**

```bash
git add bin/styleguide composer.json tests/Cli/BinSmokeTest.php
git commit -m "feat(cli): ship vendor/bin/styleguide entry script

Adds the executable shim that locates Composer's autoloader in either
layout (path repo / installed dep) and forwards argv to Command::run.
Smoke test exercises the binary end-to-end via proc_open, so a regression
in autoload wiring fails CI rather than only being caught by a downstream
project.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: README documentation + final verification

**Files:**
- Modify: `README.md`

- [ ] **Step 8.1: Inspect current README to find an appropriate insertion point**

Run: `grep -n '^##' README.md`
Expected: a list of top-level section headings. Insert the new section AFTER the last installation/bootstrap section and BEFORE any "Development" / "Contributing" / "Testing" sections. If a "Usage" section exists, place the CLI block at its end.

- [ ] **Step 8.2: Add the documentation block**

Insert this Markdown into `README.md` at the insertion point chosen in Step 8.1:

````markdown
### Programmatic catalogue (CLI)

After install, `vendor/bin/styleguide` exposes the component catalogue without needing the SPA. Useful for AI coding assistants and scripted tooling.

```bash
vendor/bin/styleguide list                       # all components (compact JSON)
vendor/bin/styleguide list --pretty              # indented for terminals
vendor/bin/styleguide list --type=page           # pages instead of components
vendor/bin/styleguide show card/promo            # one component, full detail
vendor/bin/styleguide show landing --type=page   # one page
```

The CLI wraps `ComponentParser` — it returns the same normalised records as `GET /styleguide/api/components`, but without a running webserver. Run it from the consumer's repo root, or set `STYLEGUIDE_TEMPLATES=<path>` / pass `--templates=<path>` to override the templates directory location.

Stdout is JSON; stderr carries error messages. Pipe to `jq` for filtering (`vendor/bin/styleguide list | jq '.[] | select(.category == "forms")'`).
````

- [ ] **Step 8.3: Run the full suite one more time**

Run: `composer test && composer phpstan`
Expected: both green.

- [ ] **Step 8.4: Manual smoke-check the binary against this repo's own fixtures**

Run from the repo root:

```bash
php bin/styleguide list --templates=tests/fixtures/templates
php bin/styleguide list --templates=tests/fixtures/templates --pretty
php bin/styleguide show sample --templates=tests/fixtures/templates --pretty
php bin/styleguide show missing --templates=tests/fixtures/templates; echo "exit=$?"
php bin/styleguide --help
```

Expected outputs:
1. Single-line JSON array with two components (`Another`, `Sample`).
2. Pretty-printed JSON: `[`, indented objects, `]` on separate lines.
3. Pretty-printed single object with `"id": "sample"` and `"name": "Sample"`.
4. stderr `Component "missing" not found.`, no stdout, `exit=1`.
5. Usage block on stdout, exit 0.

- [ ] **Step 8.5: Commit**

```bash
git add README.md
git commit -m "docs(readme): document the vendor/bin/styleguide CLI

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Out-of-scope follow-ups (separate work in consumer repos)

These are NOT part of this plan — they live in downstream projects, not in `parisek/styleguide`:

1. **`tailwind-base` `AGENTS.md` / `CLAUDE.md` update:** Add a 3-line note pointing assistants at `vendor/bin/styleguide list` / `show` when building UI. Do this AFTER this plan is merged and the consumer picks up the change via its `dev-local` path repo.
2. **Optional WP-CLI shim** (per-WordPress-consumer): three lines registering `wp styleguide` that forwards to `vendor/bin/styleguide`.
3. **Optional Drush shim** (per-Drupal-consumer): analogous to the WP-CLI shim.

---

## Self-review notes

- **Spec coverage:** Every "Goal" and "CLI surface" item from the spec maps to a task above (list → Task 1, show → Task 2, `--type` → Task 3, `--templates`/env → Task 4, `--pretty` → Task 5, `--help`/unknown → Task 6, bin + composer wiring → Task 7, README → Task 8). Non-goals are honoured: no MCP, no render, no WP-CLI, no filtering, no separate `presets` command.
- **Type consistency:** `Command::run()`, `parseFlags()`, `resolveTemplatesPath()`, `encodeJson()`, `helpText()` keep their signatures from Task 1 onwards; later tasks only extend `run()`.
- **TDD discipline:** Every code-changing step is preceded by a failing test and followed by a green run. Tasks 4 and 5 deliberately verify that earlier code already covers the behaviour rather than re-implementing it.
