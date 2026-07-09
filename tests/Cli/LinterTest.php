<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Cli;

use Parisek\Styleguide\Cli\Linter;
use Parisek\Styleguide\Cli\LintFinding;
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
        return array_values(array_filter($findings, static fn(LintFinding $f): bool => $f->rule === $rule));
    }

    #[Test]
    public function full_fixture_tree_produces_exactly_seven_findings(): void
    {
        $findings = (new Linter($this->fixtures))->run();
        self::assertCount(7, $findings);
    }

    #[Test]
    public function flags_metadata_yaml_invalid_as_error_instead_of_crashing(): void
    {
        // Regression guard for the ParseException propagation change: the
        // CLI must convert malformed metadata YAML into a finding, never
        // crash mid-walk. Distinct from `unindexed` (no metadata at all).
        $findings = (new Linter($this->fixtures))->run();
        $invalid = $this->findingsFor($findings, 'metadata-yaml-invalid');

        self::assertCount(1, $invalid);
        self::assertSame(LintSeverity::Error, $invalid[0]->severity);
        self::assertSame('component/yaml-broken/yaml-broken.twig', $invalid[0]->file);
        self::assertStringContainsString('not valid YAML', $invalid[0]->message);
    }

    #[Test]
    public function clean_component_has_no_findings(): void
    {
        $findings = (new Linter($this->fixtures))->run();
        $forClean = array_values(array_filter(
            $findings,
            static fn(LintFinding $f): bool => str_starts_with($f->file, 'component/clean/'),
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
        $files = array_map(static fn(LintFinding $f): string => $f->file, $findings);
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
