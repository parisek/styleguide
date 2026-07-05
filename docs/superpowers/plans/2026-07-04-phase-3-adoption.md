# Phase 3: Cross-Project Adoption Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the cross-project adoption enablers from Phase 3 of the Styleguide 2.0 roadmap (`docs/superpowers/specs/2026-07-04-storybook-lite-2.0-design.md`, target `v0.8.0`): a `styleguide lint` CLI subcommand that surfaces metadata-quality issues `ComponentParser` currently swallows silently; a `docs/MIGRATION.md` guide that turns the three bespoke-styleguide fleet projects (`centrumocnichvad`, `suys-static`, `bootstrap-base`) into `parisek/styleguide` consumers; and documentation that promotes the sibling `styleguide.twig` file to the package's one official fixture convention (the `styleguide:` YAML key becomes explicitly legacy, backward-compatible, and lint-flaggable).

**Architecture:** A new `Linter` (`src/Cli/Linter.php`) wraps `ComponentParser` by reading the same raw per-file YAML through the existing public `ComponentParser::parseTwigComment()` method — deliberately *not* going through `ComponentParser::parseAll()`, because `parseAll()`'s normalisation is exactly what coerces the bad values `lint` needs to detect (an invalid `render:` silently becomes `'inset'`; a template missing `name:` is silently dropped from the catalogue). `Linter::run()` returns `list<LintFinding>` — a new immutable value object — each carrying a new `LintSeverity` backed enum (`Notice`/`Warning`/`Error`). `Cli\Command::run()` gains a `lint` branch that dispatches to a new private `runLint()` method with its own flag parsing (`--type`, `--format`) and its own three-tier exit-code contract (`0`/`1`/`2`) — the existing `list`/`show` commands and their `0`/`1` contract are untouched. No PHP surface outside `src/Cli/` changes; `ComponentParser` is read-only consumed, never modified.

**Tech Stack:** PHP 8.3, PHPUnit (`#[Test]` attribute style, matching `tests/Cli/CommandTest.php`), PHPStan level 8 (`phpstan.neon`), Symfony Yaml (already a dependency, used transitively via `ComponentParser::parseTwigComment()`). No frontend/JS touched — this phase is backend + docs only.

## Global Constraints

- **Full backward compatibility.** The `styleguide:` YAML key stays functional as a presence-only flag exactly as today (`ComponentParser`'s `isset($metadata['styleguide'])` check is untouched) — no deprecation warning, no behavior change. Nothing in Phase 3 rewrites a consumer template.
- **`lint` only reports.** It never mutates a template, a YAML front comment, or a `styleguide.twig` file. No `--fix` flag in this phase.
- **PHP**: PSR-12, `declare(strict_types=1)` at the top of every new file, `final` classes by default.
- **PHPStan level 8 green** (`composer phpstan`) after every task that touches `src/`.
- **TDD**: a red test before the green implementation for every code task; `composer test` green before moving to the next task.
- **Docs land in the same PR as the code** (AGENTS.md documentation gate) — `docs/API.md` and `README.md` are updated inside Task 1 and Task 3, not deferred to a follow-up.
- **`CHANGELOG.md` `[Unreleased]`** gets an entry for each task that changes consumer-visible behavior (Tasks 1–3).
- **English** in all docs and code comments.
- **No emoji** anywhere — code, docs, commit messages.

---

### Task 1: `styleguide lint` CLI subcommand

**Files:**
- Create: `tests/fixtures/lint/templates/component/clean/clean.twig`
- Create: `tests/fixtures/lint/templates/component/_partials/fragment.twig`
- Create: `tests/fixtures/lint/templates/component/dead-styleguide/dead-styleguide.twig`
- Create: `tests/fixtures/lint/templates/component/flagged-render/flagged-render.twig`
- Create: `tests/fixtures/lint/templates/component/blank-description/blank-description.twig`
- Create: `tests/fixtures/lint/templates/component/referencer/referencer.twig`
- Create: `tests/fixtures/lint/templates/page/landing/landing.twig`
- Create: `tests/fixtures/lint-notice-only/templates/component/blank-description/blank-description.twig`
- Create: `tests/fixtures/lint-clean/templates/component/clean/clean.twig`
- Create: `tests/Cli/LinterTest.php`
- Create: `tests/Cli/LintCommandTest.php`
- Create: `src/Cli/LintSeverity.php`
- Create: `src/Cli/LintFinding.php`
- Create: `src/Cli/Linter.php`
- Modify: `src/Cli/Command.php`
- Modify: `tests/Cli/CommandTest.php` (extend `help_flag_prints_usage_to_stdout`)
- Modify: `tests/Cli/BinSmokeTest.php` (add end-to-end `lint` smoke test)
- Modify: `README.md` (§ Command-line catalogue — new `lint` subsection)
- Modify: `docs/API.md` (§ CLI — new table row + exit-code note)
- Modify: `CHANGELOG.md` (`[Unreleased]`)

**Interfaces:**
- `Parisek\Styleguide\Cli\LintSeverity` — backed enum `string`, cases `Notice = 'notice'`, `Warning = 'warning'`, `Error = 'error'`; method `failsBuild(): bool`.
- `Parisek\Styleguide\Cli\LintFinding` — `__construct(LintSeverity $severity, string $file, string $rule, string $message)`; `toArray(): array{severity: string, file: string, rule: string, message: string}`.
- `Parisek\Styleguide\Cli\Linter::__construct(string $templatesPath)`; `run(?array $types = null): array` returning `list<LintFinding>`.
- `Cli\Command`: new `lint` subcommand accepting `--type=component|page|doc` (default: all three), `--format=text|json` (default: `text`), `--templates=<path>`, `--pretty`. Exit codes: `0` clean/notice-only, `1` any `warning`/`error` finding present, `2` usage/internal error (invalid flag, templates dir not found, JSON-encode failure). `list`/`show` exit codes are unchanged.

Five findings to implement — each gets its own fixture and its own assertion:

| Rule | Severity | Trigger |
|---|---|---|
| `unindexed` | Warning | `.twig` file (excluding `styleguide.twig` / `styleguide.<variant>.twig`) whose first `{# #}` comment doesn't parse to YAML, or parses without a `name:` key |
| `dead-styleguide-content` | Warning | Raw metadata has a `styleguide:` key whose value is present and NOT a plain boolean (an array, string, or null under it — anything the renderer's presence-only check never reads) |
| `broken-usage-ref` | Error | A `usage:` id (comma-separated) that doesn't exist in the combined component+page id namespace (mirrors `frontend/components/usage.js`'s resolution order) |
| `unknown-render` | Error | Raw metadata has a `render:` key whose value isn't one of `ComponentParser::RENDER_MODES` (`inset`\|`bleed`\|`chrome`\|`overlay`) |
| `empty-description` | Notice | Normalised `description` is missing or an empty/whitespace-only string |

- [x] **Step 1: Create the lint fixture tree**

  `tests/fixtures/lint/templates/component/clean/clean.twig` — zero-findings control fixture:
  ```twig
  {#
  name: "Clean"
  category: "Block"
  description: "Component with complete metadata — the lint suite's zero-findings control fixture."
  render: inset
  #}
  <div class="clean">Clean</div>
  ```

  `tests/fixtures/lint/templates/component/_partials/fragment.twig` — reproduces the real-world "unindexed header partial" case from the Phase 3 design doc (no parseable `name:`):
  ```twig
  {# Internal partial included by other components — never meant to be catalogued directly. #}
  <div class="fragment">{{ content.label }}</div>
  ```

  `tests/fixtures/lint/templates/component/dead-styleguide/dead-styleguide.twig` — reproduces the real `breadcrumb` case (sample data under `styleguide:`, no sibling `styleguide.twig`):
  ```twig
  {#
  name: "Dead Styleguide"
  category: "Block"
  description: "Reproduces the breadcrumb case — sample data under styleguide: that the renderer never reads."
  styleguide:
    content:
      items:
        -
          title: "One"
        -
          title: "Two"
  #}
  <div class="dead-styleguide">Dead Styleguide</div>
  ```

  `tests/fixtures/lint/templates/component/flagged-render/flagged-render.twig`:
  ```twig
  {#
  name: "Flagged Render"
  category: "Block"
  description: "Has a render: value outside the four canonical modes."
  render: fullwidth
  #}
  <div class="flagged-render">Flagged Render</div>
  ```

  `tests/fixtures/lint/templates/component/blank-description/blank-description.twig`:
  ```twig
  {#
  name: "Blank Description"
  category: "Block"
  description: ""
  #}
  <div class="blank-description">Blank Description</div>
  ```

  `tests/fixtures/lint/templates/component/referencer/referencer.twig` — one valid usage id (`clean`), one broken (`ghost-id`):
  ```twig
  {#
  name: "Referencer"
  category: "Block"
  description: "usage: lists one valid id and one that does not exist."
  usage: clean,ghost-id
  #}
  <div class="referencer">Referencer</div>
  ```

  `tests/fixtures/lint/templates/page/landing/landing.twig` — proves usage resolution spans component+page:
  ```twig
  {#
  name: "Landing"
  category: "Marketing"
  description: "Page-side usage: cross-references a component id and a missing one."
  usage: clean,missing-component
  #}
  <div class="landing">Landing</div>
  ```

  `tests/fixtures/lint-notice-only/templates/component/blank-description/blank-description.twig` — isolated single-file tree so the CLI's "notice-only → exit 0" path has a fixture with zero warnings/errors:
  ```twig
  {#
  name: "Blank Description"
  category: "Block"
  description: ""
  #}
  <div class="blank-description">Blank Description</div>
  ```

  `tests/fixtures/lint-clean/templates/component/clean/clean.twig` — isolated single-file tree so the CLI's "clean → exit 0, empty stdout" path has nothing else in its scan:
  ```twig
  {#
  name: "Clean"
  category: "Block"
  description: "Component with complete metadata and no lint findings."
  render: inset
  #}
  <div class="clean">Clean</div>
  ```

  Commit: `git add tests/fixtures/lint tests/fixtures/lint-notice-only tests/fixtures/lint-clean && git commit -m "test(fixtures): add styleguide-lint fixture trees for the 5 finding types"`

- [x] **Step 2: Write the failing `LinterTest`**

  Create `tests/Cli/LinterTest.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Parisek\Styleguide\Tests\Cli;

  use Parisek\Styleguide\Cli\LintFinding;
  use Parisek\Styleguide\Cli\Linter;
  use Parisek\Styleguide\Cli\LintSeverity;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  final class LinterTest extends TestCase
  {
      private string $fixtures;

      protected function setUp(): void
      {
          $this->fixtures = __DIR__ . '/../fixtures/lint/templates';
      }

      /**
       * @param list<LintFinding> $findings
       * @return list<LintFinding>
       */
      private function findingsFor(array $findings, string $rule): array
      {
          return array_values(array_filter($findings, static fn (LintFinding $f): bool => $f->rule === $rule));
      }

      #[Test]
      public function full_fixture_tree_produces_exactly_six_findings(): void
      {
          $findings = (new Linter($this->fixtures))->run();
          self::assertCount(6, $findings);
      }

      #[Test]
      public function clean_component_has_no_findings(): void
      {
          $findings = (new Linter($this->fixtures))->run();
          $forClean = array_values(array_filter(
              $findings,
              static fn (LintFinding $f): bool => str_starts_with($f->file, 'component/clean/'),
          ));
          self::assertSame([], $forClean);
      }

      #[Test]
      public function flags_unindexed_template_without_parseable_name(): void
      {
          $findings = (new Linter($this->fixtures))->run();
          $unindexed = $this->findingsFor($findings, 'unindexed');

          self::assertCount(1, $unindexed);
          self::assertSame(LintSeverity::Warning, $unindexed[0]->severity);
          self::assertSame('component/_partials/fragment.twig', $unindexed[0]->file);
      }

      #[Test]
      public function flags_dead_styleguide_content(): void
      {
          $findings = (new Linter($this->fixtures))->run();
          $dead = $this->findingsFor($findings, 'dead-styleguide-content');

          self::assertCount(1, $dead);
          self::assertSame(LintSeverity::Warning, $dead[0]->severity);
          self::assertSame('component/dead-styleguide/dead-styleguide.twig', $dead[0]->file);
      }

      #[Test]
      public function flags_unknown_render_value(): void
      {
          $findings = (new Linter($this->fixtures))->run();
          $render = $this->findingsFor($findings, 'unknown-render');

          self::assertCount(1, $render);
          self::assertSame(LintSeverity::Error, $render[0]->severity);
          self::assertStringContainsString('fullwidth', $render[0]->message);
          self::assertStringContainsString('inset|bleed|chrome|overlay', $render[0]->message);
      }

      #[Test]
      public function flags_empty_description_as_notice(): void
      {
          $findings = (new Linter($this->fixtures))->run();
          $blank = $this->findingsFor($findings, 'empty-description');

          self::assertCount(1, $blank);
          self::assertSame(LintSeverity::Notice, $blank[0]->severity);
          self::assertSame('component/blank-description/blank-description.twig', $blank[0]->file);
      }

      #[Test]
      public function flags_broken_usage_reference_and_leaves_valid_ids_alone(): void
      {
          $findings = (new Linter($this->fixtures))->run();
          $broken = $this->findingsFor($findings, 'broken-usage-ref');

          self::assertCount(2, $broken);
          $byFile = [];
          foreach ($broken as $finding) {
              $byFile[$finding->file] = $finding->message;
          }
          self::assertStringContainsString('ghost-id', $byFile['component/referencer/referencer.twig']);
          self::assertStringContainsString('missing-component', $byFile['page/landing/landing.twig']);
          // "clean" is a valid component id referenced by referencer.twig's usage: —
          // it must never appear in a broken-usage-ref message.
          self::assertStringNotContainsString('"clean"', $byFile['component/referencer/referencer.twig']);
      }

      #[Test]
      public function type_filter_restricts_the_files_walked_but_not_the_known_id_namespace(): void
      {
          // Scanning only "page" must still resolve landing's usage against the
          // component catalogue — knownIds() always spans component+page.
          $findings = (new Linter($this->fixtures))->run(['page']);

          self::assertCount(1, $findings);
          self::assertSame('broken-usage-ref', $findings[0]->rule);
          self::assertSame('page/landing/landing.twig', $findings[0]->file);
      }

      #[Test]
      public function missing_type_directory_returns_no_findings_without_error(): void
      {
          $findings = (new Linter($this->fixtures))->run(['doc']);
          self::assertSame([], $findings);
      }

      #[Test]
      public function findings_are_sorted_by_file(): void
      {
          $findings = (new Linter($this->fixtures))->run();
          $files = array_map(static fn (LintFinding $f): string => $f->file, $findings);
          $sorted = $files;
          sort($sorted);
          self::assertSame($sorted, $files);
      }

      #[Test]
      public function to_array_returns_the_json_shape(): void
      {
          $finding = new LintFinding(LintSeverity::Warning, 'component/x/x.twig', 'unindexed', 'msg');
          self::assertSame([
              'severity' => 'warning',
              'file' => 'component/x/x.twig',
              'rule' => 'unindexed',
              'message' => 'msg',
          ], $finding->toArray());
      }
  }
  ```

  Run: `vendor/bin/phpunit --filter LinterTest`
  Expected: fatal error — `Class "Parisek\Styleguide\Cli\Linter" not found`. This is the red state.

- [x] **Step 3: Implement `LintSeverity`**

  Create `src/Cli/LintSeverity.php`:
  ```php
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
  ```

- [x] **Step 4: Implement `LintFinding`**

  Create `src/Cli/LintFinding.php`:
  ```php
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
      ) {
      }

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
  ```

- [x] **Step 5: Implement `Linter`**

  Create `src/Cli/Linter.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Parisek\Styleguide\Cli;

  use Parisek\Styleguide\ComponentParser;

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
       * ComponentParser::parseAll() excludes the bare filename for the same
       * reason. This excludes the whole family so lint never flags a fixture
       * file as "unindexed".
       */
      private const STYLEGUIDE_SIBLING_PATTERN = '/^styleguide(\.[A-Za-z0-9_-]+)?\.twig$/';

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
              static fn (LintFinding $a, LintFinding $b): int => [$a->file, $a->rule] <=> [$b->file, $b->rule],
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
       * @return array<string, array<string,mixed>|false>  relative path => raw YAML metadata (or false when unparseable)
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
              $found[$relPath] = $this->parser->parseTwigComment($content);
          }
          return $found;
      }

      /**
       * @param array<string,mixed>|false $metadata
       * @param array<string,true> $knownIds
       * @return list<LintFinding>
       */
      private function lintEntry(string $relPath, array|false $metadata, array $knownIds): array
      {
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
              $ids = array_filter(
                  array_map(static fn (string $id): string => trim($id), explode(',', (string) $metadata['usage'])),
                  static fn (string $id): bool => $id !== '',
              );
              foreach ($ids as $id) {
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
  ```

  Run: `vendor/bin/phpunit --filter LinterTest`
  Expected: `OK (11 tests, ...)`. Green.

  Commit: `git add src/Cli/Linter.php src/Cli/LintFinding.php src/Cli/LintSeverity.php tests/Cli/LinterTest.php && git commit -m "feat(cli): add Linter — pure metadata-quality scan over templates/"`

- [x] **Step 6: Write the failing `LintCommandTest`**

  Create `tests/Cli/LintCommandTest.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Parisek\Styleguide\Tests\Cli;

  use Parisek\Styleguide\Cli\Command;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  final class LintCommandTest extends TestCase
  {
      private string $fixtures;
      private string $noticeOnlyFixtures;
      private string $cleanFixtures;

      protected function setUp(): void
      {
          $this->fixtures = __DIR__ . '/../fixtures/lint/templates';
          $this->noticeOnlyFixtures = __DIR__ . '/../fixtures/lint-notice-only/templates';
          $this->cleanFixtures = __DIR__ . '/../fixtures/lint-clean/templates';
      }

      /**
       * @param array<int,string> $argv
       * @return array{0:int, 1:string, 2:string}
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
      public function text_format_prints_one_line_per_finding_as_severity_file_message(): void
      {
          [$exit, $stdout, $stderr] = $this->runCli([
              'lint',
              '--templates=' . $this->fixtures,
          ]);

          self::assertSame(1, $exit, "stderr: $stderr");
          self::assertSame('', $stderr);
          self::assertStringContainsString(
              'WARNING  component/_partials/fragment.twig  No parseable `name:` key',
              $stdout,
          );
          self::assertStringContainsString(
              'ERROR  component/referencer/referencer.twig  usage: references unknown id "ghost-id".',
              $stdout,
          );
          self::assertCount(6, array_filter(explode("\n", trim($stdout))));
      }

      #[Test]
      public function json_format_returns_an_array_of_severity_file_rule_message_objects(): void
      {
          [$exit, $stdout, $stderr] = $this->runCli([
              'lint',
              '--format=json',
              '--templates=' . $this->fixtures,
          ]);

          self::assertSame(1, $exit, "stderr: $stderr");
          $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
          self::assertIsArray($decoded);
          self::assertCount(6, $decoded);
          foreach ($decoded as $entry) {
              self::assertArrayHasKey('severity', $entry);
              self::assertArrayHasKey('file', $entry);
              self::assertArrayHasKey('rule', $entry);
              self::assertArrayHasKey('message', $entry);
          }
      }

      #[Test]
      public function exit_code_0_when_only_notice_findings_are_present(): void
      {
          [$exit, $stdout, $stderr] = $this->runCli([
              'lint',
              '--templates=' . $this->noticeOnlyFixtures,
          ]);

          self::assertSame(0, $exit, "stderr: $stderr");
          self::assertStringContainsString('NOTICE', $stdout);
      }

      #[Test]
      public function exit_code_0_and_empty_stdout_when_clean(): void
      {
          [$exit, $stdout, $stderr] = $this->runCli([
              'lint',
              '--templates=' . $this->cleanFixtures,
          ]);

          self::assertSame(0, $exit, "stderr: $stderr");
          self::assertSame('', $stdout);
          self::assertSame('', $stderr);
      }

      #[Test]
      public function type_flag_restricts_scan_to_the_requested_type(): void
      {
          [$exit, $stdout, $stderr] = $this->runCli([
              'lint',
              '--type=page',
              '--format=json',
              '--templates=' . $this->fixtures,
          ]);

          self::assertSame(1, $exit, "stderr: $stderr");
          $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
          self::assertCount(1, $decoded);
          self::assertSame('page/landing/landing.twig', $decoded[0]['file']);
      }

      #[Test]
      public function invalid_type_flag_exits_2(): void
      {
          [$exit, $stdout, $stderr] = $this->runCli([
              'lint',
              '--type=widget',
              '--templates=' . $this->fixtures,
          ]);

          self::assertSame(2, $exit);
          self::assertSame('', $stdout);
          self::assertStringContainsString('Invalid --type', $stderr);
      }

      #[Test]
      public function invalid_format_flag_exits_2(): void
      {
          [$exit, $stdout, $stderr] = $this->runCli([
              'lint',
              '--format=xml',
              '--templates=' . $this->fixtures,
          ]);

          self::assertSame(2, $exit);
          self::assertSame('', $stdout);
          self::assertStringContainsString('Invalid --format', $stderr);
      }

      #[Test]
      public function missing_templates_dir_exits_2(): void
      {
          $originalCwd = getcwd();
          $originalEnv = getenv('STYLEGUIDE_TEMPLATES');
          chdir(sys_get_temp_dir());
          putenv('STYLEGUIDE_TEMPLATES');
          try {
              [$exit, $stdout, $stderr] = $this->runCli(['lint']);
              self::assertSame(2, $exit);
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

      #[Test]
      public function pretty_flag_indents_json_output(): void
      {
          [, $stdout, ] = $this->runCli([
              'lint',
              '--format=json',
              '--templates=' . $this->fixtures,
              '--pretty',
          ]);

          self::assertStringContainsString("\n    ", $stdout);
      }
  }
  ```

  Run: `vendor/bin/phpunit --filter LintCommandTest`
  Expected: failures — `lint` currently falls through to "Unknown command" (exit 1, not 2/1/0 as asserted). Red.

- [x] **Step 7: Wire `lint` into `Command`**

  Modify `src/Cli/Command.php`. In `run()`, insert the new branch right after the `--help` check and before the existing `$rawType` default (list/show keep defaulting `--type` to `'component'`; `lint` needs `null` = "all three types", so it must branch away before that default is applied):
  ```php
          if (isset($flags['help'])) {
              fwrite($stdout, $this->helpText());
              return 0;
          }

          if ($command === 'lint') {
              return $this->runLint($flags, $stdout, $stderr);
          }

          $rawType = $flags['type'] ?? 'component';
  ```

  Add the new private method (place it after `run()`, before `helpText()`):
  ```php
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
  ```

  `Linter`, `LintFinding`, and the command already share the `Parisek\Styleguide\Cli` namespace — no new `use` import needed.

  Update `helpText()`:
  ```php
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
  ```

- [x] **Step 8: Extend `CommandTest` and `BinSmokeTest`**

  In `tests/Cli/CommandTest.php`, extend the existing `help_flag_prints_usage_to_stdout` test with two more assertions (append, don't replace):
  ```php
          self::assertStringContainsString('lint', $stdout);
          self::assertStringContainsString('--format', $stdout);
  ```

  In `tests/Cli/BinSmokeTest.php`, add a real-subprocess smoke test for `lint`:
  ```php
      #[Test]
      public function bin_lint_runs_end_to_end(): void
      {
          $fixtures = realpath(__DIR__ . '/../fixtures/lint/templates');
          self::assertNotFalse($fixtures, 'lint fixtures path missing');

          $args = sprintf('lint --templates=%s', escapeshellarg($fixtures));
          [$exit, $stdout, $stderr] = $this->runBin($args);

          self::assertSame(1, $exit, "stderr: $stderr");
          self::assertStringContainsString('WARNING', $stdout);
      }
  ```

- [x] **Step 9: Full suite green**

  Run: `composer test`
  Expected: all tests pass, including `LinterTest`, `LintCommandTest`, the extended `CommandTest`, and `BinSmokeTest`.

  Run: `composer phpstan`
  Expected: no errors at level 8. If PHPStan flags the `(string) $metadata['usage']` cast or the spread in `knownIds()`, add narrower type guards rather than suppressing — e.g. an explicit `is_string($metadata['usage']) ? $metadata['usage'] : (is_scalar($metadata['usage']) ? (string) $metadata['usage'] : '')` if the implicit mixed-to-string cast trips a rule.

- [x] **Step 10: Docs — README § Command-line catalogue**

  In `README.md`, immediately after the existing `show <id> ... --type=doc` example block (and before the `jq` filtering example, or after it — keep it inside the same "Command-line catalogue (CLI)" section, right before the `---` separator that follows), add:
  ````markdown
  ### `lint` — metadata quality report

  ```bash
  vendor/bin/styleguide lint                       # scan component + page + doc, text output
  vendor/bin/styleguide lint --type=component       # scan just one type
  vendor/bin/styleguide lint --format=json --pretty # machine-readable, indented
  ```

  Reports five issue types: templates with no parseable `name:` (dropped from
  the catalogue — `unindexed`), a `styleguide:` YAML key carrying content that
  the renderer never reads (`dead-styleguide-content` — see *Fixtures & sample
  data*), `usage:` references to ids that don't exist (`broken-usage-ref`),
  `render:` values outside the four canonical modes (`unknown-render`), and
  empty `description` strings (`empty-description`, informational only).

  Text output is one line per finding: `SEVERITY  file  message`. JSON output
  is an array of `{ severity, file, rule, message }` objects. Exit code: `0`
  clean (or notice-only), `1` when any `warning`/`error` finding is present,
  `2` on a usage/internal error — run it in CI to catch metadata regressions
  before they ship. See `docs/MIGRATION.md` for a worked "fix the gap report"
  walkthrough.
  ````

- [x] **Step 11: Docs — `docs/API.md` § CLI**

  In `docs/API.md`, replace the CLI table:
  ```markdown
  | Command | Purpose |
  |---|---|
  | `list [--type=component\|page\|doc] [--templates=<path>] [--pretty]` | List all components / pages / docs as JSON. Shape matches `/api/components` / `/api/pages` / `/api/docs`. |
  | `show <id> [--type=component\|page\|doc] [--templates=<path>] [--pretty]` | Same but for a single id. |
  | `--help` / `-h` | Usage |
  ```
  with:
  ```markdown
  | Command | Purpose |
  |---|---|
  | `list [--type=component\|page\|doc] [--templates=<path>] [--pretty]` | List all components / pages / docs as JSON. Shape matches `/api/components` / `/api/pages` / `/api/docs`. |
  | `show <id> [--type=component\|page\|doc] [--templates=<path>] [--pretty]` | Same but for a single id. |
  | `lint [--type=component\|page\|doc] [--format=text\|json] [--templates=<path>] [--pretty]` | Report metadata quality issues (unindexed templates, dead `styleguide:` content, broken `usage:` refs, unknown `render:` values, empty descriptions). See README § Command-line catalogue. |
  | `--help` / `-h` | Usage |

  `lint` has its own exit-code contract, distinct from `list`/`show`: `0`
  clean (or notice-only findings), `1` when a `warning`/`error` finding is
  present, `2` on a usage/internal error (bad flag, templates dir not
  found). `list` and `show` keep their existing `0`/`1` contract.
  ```

- [x] **Step 12: `CHANGELOG.md`**

  Under `## [Unreleased]`, add:
  ```markdown
  ### Added

  - **`styleguide lint` CLI subcommand.** Reports metadata quality issues across `templates/`: unindexed templates (no parseable `name:`), dead `styleguide:` YAML content, broken `usage:` cross-references, unknown `render:` values, and empty `description` strings. `--type=component|page|doc` (default: all three), `--format=text|json` (default: text), reuses `--templates`/`--pretty`. Exit `0` clean, `1` warning/error findings present, `2` usage/internal error — a three-tier contract specific to `lint` (`list`/`show` keep their existing `0`/`1` codes). See `docs/API.md` § CLI and `README.md` § Command-line catalogue.
  ```

- [x] **Step 13: Commit**

  `git add src/Cli/Command.php tests/Cli/LintCommandTest.php tests/Cli/CommandTest.php tests/Cli/BinSmokeTest.php README.md docs/API.md CHANGELOG.md && git commit -m "feat(cli): wire 'styleguide lint' subcommand with text/json output and its own exit-code contract"`

---

### Task 2: `docs/MIGRATION.md` — replacing a hand-rolled styleguide

**Files:**
- Create: `docs/MIGRATION.md`

**Interfaces:** None — documentation-only task.

- [x] **Step 1: Write `docs/MIGRATION.md`**

  Create the file with exactly this content (grounded in the current state of the three target repos as of this plan — `centrumocnichvad` has two CSS bundles at `dist/css/style.css` + `dist/css/gutenberg.css`; `suys-static`'s `breadcrumb`/`content`/`pagination`/`cookieconsent`/`404` components carry dead `styleguide:` content with no sibling fixture file; `bootstrap-base`'s own README already recommends `picsum.photos`, and its bespoke router already prefers a sibling `styleguide.twig` over the YAML key — same precedence this package uses):

  ````markdown
  # Migration guide: replacing a hand-rolled styleguide

  Three projects in the fleet ship a **bespoke** styleguide today: routing PHP
  plus a `static/styleguide/templates/` set of chrome Twig templates,
  hand-rolled per project. All three already use the convention this package
  expects — a sibling `styleguide.twig` next to the component/page it demos —
  so migration is mostly deletion, not rewriting.

  This guide covers the common steps once, then the per-project deltas.

  ## Common steps

  ### 1. Require the package

  ```bash
  composer require parisek/styleguide
  ```

  ### 2. Wire the bootstrap

  Add this near the top of whichever public PHP file already fronts every
  request — for the fleet's `static/index.php` convention, right after Twig is
  built and before any legacy `$prefix = 'styleguide'` routing block:

  ```php
  (new \Parisek\Styleguide\Styleguide([
      'templates_path' => __DIR__ . '/templates',
      'static_path'    => __DIR__,
      'config_yaml'    => __DIR__ . '/styleguide.yaml',
      'default_locale' => 'cs',
      'twig'           => $twig,   // reuse the project's Twig env — component_*()/_x()/placeholder() must resolve
      'twig_context'   => [
          'homeUrl'     => '/styleguide/',
          'templateUrl' => '',
          'langcode'    => 'cs',
      ],
  ]))->run();
  ```

  `run()` exits for any `/styleguide/*` request; everything else falls through
  unchanged, so the legacy router still handles the rest of the site while you
  verify the new one side by side.

  ### 3. Minimal `styleguide.yaml`

  ```yaml
  project:
    name: "<Project name>"

  iframe:
    css: "/dist/css/style.css"
    js:  "/dist/js/script.js"
  ```

  Every other `styleguide.yaml` block (`logo`, `colors`, `typography`,
  `labels`) is optional — add them incrementally; see `README.md` §
  `styleguide.yaml` for the full schema.

  ### 4. Web-server rewrite

  ```apache
  RewriteRule ^styleguide(/.*)?$ /index.php [L]
  ```

  ```nginx
  location /styleguide { try_files $uri /index.php?$query_string; }
  ```

  ### 5. Run the gap report

  ```bash
  vendor/bin/styleguide lint
  ```

  Expect a wall of `NOTICE  …  empty-description` on first run — most fleet
  templates carry only `name:`. That's fine; notices don't fail CI (exit code
  stays `0` unless a `WARNING`/`ERROR` is present). Fix the warnings/errors
  first (unindexed templates, dead `styleguide:` content, broken `usage:`
  refs, unknown `render:` values); treat the description backfill as an
  incremental follow-up (see § Partial metadata is fine, and the Follow-ups
  note in the Phase 3 design doc).

  ### 6. Delete the legacy styleguide

  Once `/styleguide/...` renders correctly against the package for a sample of
  components, pages, and at least one 404, delete the bespoke router block and
  its chrome templates (per-project paths below) and drop the legacy Twig
  namespace registration for it.

  ---

  ## centrumocnichvad (Tailwind v3 + SCSS)

  - Stack: Tailwind CSS 3.4 + SCSS, built to `dist/css/style.css` and a
    **second**, separate bundle `dist/css/gutenberg.css` (Gutenberg/editor
    block styles) — both need to load in the iframe, or content-heavy
    components (e.g. `content`) will preview unstyled:

    ```yaml
    iframe:
      css:
        - "/dist/css/style.css"
        - "/dist/css/gutenberg.css"
      js: "/dist/js/script.js"
    ```

    (`iframe.css` accepts a string or a list — see README § `styleguide.yaml`.)
  - Fixtures are **already compatible**: `templates/component/<name>/styleguide.twig`
    siblings already exist for the components that need sample data (e.g.
    `footer`, `alert`, `faq`) — nothing to rewrite there.
  - Delete `styleguide/templates/` (the bespoke `styleguide-{base,layout,page,
    homepage,component,sidebar,404}.twig` set) and whatever `index.php` block
    registers the legacy `@styleguide` namespace and dispatches
    `$prefix = 'styleguide'` requests to it, once step 5's `lint` pass and a
    manual spot-check both look clean.

  ## suys-static (Drupal-backed Twig)

  - Stack: Drupal-backed Twig, `static/templates/component/<name>/<name>.twig`
    + `static/templates/page/<name>/<name>.twig` — the same `<id>/<id>.twig`
    convention this package expects.
  - **`styleguide:` content is dead weight.** Five components carry sample
    data under the front-comment `styleguide:` key with **no** sibling
    `styleguide.twig` (so nothing ever renders it beyond a bare component
    preview): `breadcrumb`, `content`, `pagination`, `cookieconsent`, `404`.
    `styleguide lint` reports each as `dead-styleguide-content`. Move the data
    into a sibling file. Concrete example —
    `static/templates/component/breadcrumb/breadcrumb.twig`:

    Before (dead YAML, front comment):
    ```yaml
    styleguide:
      content:
        items:
          - { title: "Úvod", url: '#' }
          - { title: "Služby", url: '#' }
          - { title: "Detail služby", url: '#' }
        container: "container"
    ```

    After — delete that block from the front comment, add
    `static/templates/component/breadcrumb/styleguide.twig`:
    ```twig
    {{ component_breadcrumb({
      container: 'container',
      items: [
        { title: 'Úvod', url: '#' },
        { title: 'Služby', url: '#' },
        { title: 'Detail služby', url: '#' },
      ],
    }) }}
    ```
    (`tailwind-base`'s `breadcrumb` already made this exact move — its
    `templates/component/breadcrumb/styleguide.twig` is a ready-made
    reference.)
  - Several existing `styleguide.twig` siblings already use `picsum.photos`
    URLs for fixture images (`article-featured`, `jumbotron-image`, …) — see
    README § Fixtures & sample data for the `placeholder()` replacement.
  - Delete `static/styleguide/` (the bespoke `styleguide-{404,page,sidebar,
    base,homepage,component,layout}.twig` set) once migrated.

  ## bootstrap-base (Bootstrap 5)

  - Stack: Bootstrap 5 — no Tailwind assumptions needed anywhere in this
    package; `iframe.css` just points at the Bootstrap bundle:
    ```yaml
    iframe:
      css: "/dist/css/style.css"
      js:  "/dist/js/script.js"
    ```
  - Metadata is bare `name:` (+ occasional `description`/`weight`) across the
    fleet — that's fine as-is (see § Partial metadata is fine). Category
    backfill is optional, not a migration blocker.
  - The existing bespoke router (`static/index.php`) already prefers a
    sibling `styleguide.twig` and falls back to a `styleguide.content` YAML
    key — the **same** precedence this package uses — so no template changes
    are needed purely to satisfy this package's conventions. The one thing
    worth fixing while you're in there: the project's own `README.md`
    recommends `picsum.photos` for fixture images (§ *Images for
    styleguide*) — swap those for `placeholder()` per this guide's
    `suys-static` asset-migration example, since `picsum.photos` is an
    external network dependency the styleguide preview shouldn't need.
  - Delete `static/styleguide/` (same `styleguide-*.twig` chrome set as the
    other two) once migrated.

  ## Partial metadata is fine

  The package tolerates a template with **only** `name:` — it still renders,
  still appears in the sidebar, still shows up in `/api/components`. What you
  lose by skipping the optional keys:

  | Key you skip | What you lose |
  |---|---|
  | `category` | Falls into the sidebar's default bucket instead of a named one. |
  | `description` | Sidebar tooltip + Overview card are blank. Flagged by `lint` as `empty-description` (notice, non-blocking). |
  | `weight` | Sorts at the default `50` alongside every other unweighted entry (then falls back to alphabetical). |
  | `usage` | No cross-reference chips on that entry's preview. |
  | `render` | Defaults to `inset` (24px-padded wrapper) — wrong for a hero/slider/page-chrome component, fine for everything else. |
  | `fields` | No entry in the Fields inspector / `/api/fields`. |
  | `styleguide` / sibling `styleguide.twig` | The bare component/page template renders directly — fine if it doesn't need CMS-shaped sample data to render meaningfully. |

  None of these block adoption. Backfilling `category`/`description` at
  scale is intentionally **not** part of this package — it's handled by
  extending the `styleguide-render-tagger` Claude skill, a separate piece of
  tooling outside this repo (tracked in the Follow-ups section of the Phase 3
  design doc).
  ````

- [x] **Step 2: Verify**

  There is no build/lint tooling for Markdown in this repo. Verify manually:
  - `grep -c '^## ' docs/MIGRATION.md` → `5` (Common steps, centrumocnichvad, suys-static, bootstrap-base, Partial metadata is fine — the H1 title doesn't count as `##`).
  - Every fenced code block opens and closes (`grep -c '^```' docs/MIGRATION.md` is even).
  - Every `styleguide.yaml`, `.php`, `.twig`, `bash`, `apache`, `nginx` block is syntactically plausible on a read-through (no unclosed `{{ }}`, no missing YAML colons).

- [x] **Step 3: Commit**

  `git add docs/MIGRATION.md && git commit -m "docs: add MIGRATION.md — replacing a hand-rolled styleguide with parisek/styleguide"`

---

### Task 3: Official fixture convention documentation

**Files:**
- Modify: `README.md` (Per-template metadata table row; new "Fixtures & sample data" section)
- Modify: `docs/API.md` (Component YAML metadata table row; `placeholder(opts)` Twig function row)
- Modify: `CHANGELOG.md` (`[Unreleased]`)

**Interfaces:** None — documentation-only task. No YAML schema, JSON shape, or Twig function changes; this task only changes prose recommending an existing, already-preferred behavior.

- [x] **Step 1: `docs/API.md` — mark `styleguide` key as legacy**

  Replace the `styleguide` row in the "Component YAML metadata" table:
  ```markdown
  | `styleguide` | no | flag (presence-only) | absent | Forces a separate `styleguide.twig` demo file |
  ```
  with:
  ```markdown
  | `styleguide` | no | flag (presence-only) — **legacy** | absent | Forces a separate `styleguide.twig` demo file. **Convention going forward: use a sibling `styleguide.twig`** (auto-detected, no YAML key needed) — this key exists for templates written before that convention. Content placed under it (anything beyond a bare boolean) is never read by the renderer; `vendor/bin/styleguide lint` reports it as `dead-styleguide-content`. See README § Fixtures & sample data. |
  ```

  Update the `placeholder(opts)` row in the Twig functions table:
  ```markdown
  | `placeholder(opts)` | function | Generate a placeholder image URL — see `Placeholder::generate()` for opts |
  ```
  becomes:
  ```markdown
  | `placeholder(opts)` | function | Generate a placeholder image URL — see `Placeholder::generate()` for opts, and README § Fixtures & sample data for the full option table and migration examples away from `picsum.photos`-style URLs. |
  ```

- [x] **Step 2: `README.md` — update the Per-template metadata table**

  Replace:
  ```markdown
  | `styleguide` | optional flag — when set (or when a sibling `styleguide.twig` exists), the component exposes a separate styleguide-only render variant |
  ```
  with:
  ```markdown
  | `styleguide` | legacy presence-only flag — **prefer a sibling `styleguide.twig` file** (the renderer already prefers it; see *Fixtures & sample data* below). Content nested under this YAML key is never read; `vendor/bin/styleguide lint` reports it as `dead-styleguide-content`. |
  ```

- [x] **Step 3: `README.md` — add the "Fixtures & sample data" section**

  Insert this new `##` section right after "### Page wrapper" and before "## File layout (after install)":

  ````markdown
  ## Fixtures & sample data

  The **only supported convention** for demo content is a sibling
  `styleguide.twig` next to the component or page it demos:

  ```
  templates/component/breadcrumb/
  ├── breadcrumb.twig       # the component itself — receives content.* from the CMS in production
  └── styleguide.twig       # sample data, rendered ONLY in the styleguide preview
  ```

  ```twig
  {# templates/component/breadcrumb/styleguide.twig #}
  {{ component_breadcrumb({
      container: 'container',
      items: [
          { title: 'Úvod', url: '#' },
          { title: 'Služby', url: '#' },
          { title: 'Detail služby', url: '#' },
      ],
  }) }}
  ```

  `Renderer` auto-detects the sibling file and prefers it — no YAML key
  required. The `styleguide:` front-comment key (nested sample data under the
  YAML metadata) still works for backward compatibility, but content placed
  under it is **never read** — only its presence is checked. Run
  `vendor/bin/styleguide lint` to find leftover instances (reported as
  `dead-styleguide-content`) and move the data into a `styleguide.twig`
  sibling; see `docs/MIGRATION.md` for a worked before/after.

  ### Placeholder images — no external network calls

  Use the bundled `placeholder()` Twig function in `styleguide.twig` files
  instead of a service like `picsum.photos`. It's deterministic (the same
  `seed` always renders the same image), fully offline (an inline SVG data
  URL — no network round-trip, no rate limit, no dead links when a
  third-party service changes its API), and returns an image-array shape
  most `component_picture`-style helpers already expect:

  ```twig
  {# bare call — abstract subject, pastel mood, 3/2 aspect #}
  {{ component_picture({ image: placeholder() }) }}

  {# tuned for a hero — landscape subject, warm mood, explicit size #}
  {{ component_picture({
      image: placeholder({ subject: 'landscape', mood: 'warm', width: 1920, height: 1080, seed: 'hero-1' }),
  }) }}

  {# repeatable across a gallery loop — same subject, distinct seed per index avoids visually identical repeats #}
  {% for i in 1..4 %}
      {{ component_picture({ image: placeholder({ subject: 'product', seed: 'gallery-' ~ i }) }) }}
  {% endfor %}
  ```

  | Option | Values | Default |
  |---|---|---|
  | `subject` | `abstract` \| `landscape` \| `portrait` \| `product` \| `food` \| `architecture` \| `avatar` | `abstract` |
  | `mood` | `pastel` \| `vibrant` \| `monochrome` \| `warm` \| `cold` \| `natural` \| `vintage` | `pastel` |
  | `seed` | any string — same seed ⇒ same image | auto-incrementing counter |
  | `width` / `height` / `aspect` | pixels, or a `"w/h"` ratio string | `aspect: '3/2'`, 1200px wide |
  | `label` | `true` \| a string \| `false` | `false` |

  See `docs/API.md` § Twig functions for the full option list (`grain`,
  `vignette`, `alt`).
  ````

- [x] **Step 4: Verify**

  - `grep -n "picsum" README.md docs/API.md` → the only hits are inside the new prose explaining what `placeholder()` replaces (no actual `picsum.photos` URL is added to this package's own docs).
  - `grep -n "^## Fixtures" README.md` → one match.
  - Read the two edited tables end-to-end to confirm no dangling `|` breaks table rendering.

- [x] **Step 5: `CHANGELOG.md`**

  Under `## [Unreleased]`, append to the `### Added` block from Task 1 (or start one if Task 3 lands separately):
  ```markdown
  - **Sibling `styleguide.twig` is now documented as the official fixture convention** (`README.md` § Fixtures & sample data); the `styleguide:` YAML key stays functional as a presence-only flag for backward compatibility, but content under it is flagged by `lint` (`dead-styleguide-content`).
  ```

- [x] **Step 6: Commit**

  `git add README.md docs/API.md CHANGELOG.md && git commit -m "docs: document sibling styleguide.twig as the official fixture convention; add placeholder() migration examples"`

---

### Task 4: Follow-up note — metadata backfill skill extension (out of repo scope)

**Files:**
- Modify: `docs/superpowers/specs/2026-07-04-storybook-lite-2.0-design.md` (append a "Follow-ups" section)

**Interfaces:** None.

Phase 3 item 5 of the roadmap ("Metadata backfill (category/description) is delivered as an extension of the existing `styleguide-render-tagger` Claude skill, not package code") names a deliverable that lives **outside this git repository** — the skill is a user-level Claude Code skill (`~/.claude/skills/styleguide-render-tagger/`), not a file under `parisek/styleguide`. No task in this plan can execute it: there's no repo-relative path to edit, and this plan's scope is `/Users/pari/Sites/styleguide`. Record it so it isn't silently dropped from the roadmap.

- [x] **Step 1: Append the Follow-ups section**

  Append to the end of `docs/superpowers/specs/2026-07-04-storybook-lite-2.0-design.md` (after the existing "Compatibility contract" section, which currently ends the file):

  ```markdown

  ## Follow-ups (tracked, not executed by this plan)

  - **Metadata backfill (category/description) via `styleguide-render-tagger`.**
    Phase 3 item 5 of this roadmap. The skill lives at the user level
    (`~/.claude/skills/styleguide-render-tagger/`), outside this git
    repository, so it cannot be extended by a plan scoped to
    `parisek/styleguide`. Recorded here so the roadmap item isn't silently
    dropped: someone with access to the user-level skills directory needs to
    extend `styleguide-render-tagger` to also propose `category:`/
    `description:` backfills (using the same "classify, present a table,
    apply on approval" pattern it already uses for `render:` tagging),
    likely fed by the gap list `vendor/bin/styleguide lint`'s
    `empty-description` findings now produce (see Task 1 of the Phase 3
    adoption plan, `docs/superpowers/plans/2026-07-04-phase-3-adoption.md`).
    **Not executable inside this repo** — no code or doc change here can
    close this item.
  ```

- [x] **Step 2: Verify**

  `tail -20 docs/superpowers/specs/2026-07-04-storybook-lite-2.0-design.md` shows the new section as the last thing in the file, and the file still starts with its original `# Styleguide 2.0 — "Storybook lite" optimization roadmap` header (i.e. this was an append, not a rewrite).

- [x] **Step 3: Commit**

  `git add docs/superpowers/specs/2026-07-04-storybook-lite-2.0-design.md && git commit -m "docs: record styleguide-render-tagger backfill extension as a tracked, non-repo follow-up"`
