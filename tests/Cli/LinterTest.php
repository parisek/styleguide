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
    public function full_fixture_tree_produces_exactly_nine_findings(): void
    {
        $findings = (new Linter($this->fixtures))->run();
        self::assertCount(9, $findings);
    }

    #[Test]
    public function flags_a_catalogue_entry_that_nothing_renders(): void
    {
        // The entry parses perfectly, so every metadata rule passes it — and
        // then it shows an empty frame in the sidebar and no visual or
        // behavioural test can reach it. Nothing else reported this.
        $findings = (new Linter($this->fixtures))->run();
        $noFixture = $this->findingsFor($findings, 'no-fixture');

        self::assertCount(1, $noFixture);
        self::assertSame(LintSeverity::Notice, $noFixture[0]->severity);
        self::assertSame('component/no-fixture-demo/no-fixture-demo.twig', $noFixture[0]->file);
    }


    #[Test]
    public function a_null_valued_styleguide_key_does_not_count_as_a_fixture(): void
    {
        // The runtime uses isset(), so a bare `styleguide:` with no value is
        // NOT a fixture there. array_key_exists() would call it one and
        // silently exempt the component from this rule — a false negative that
        // looks like coverage.
        $root = sys_get_temp_dir() . '/sg-null-sg-' . bin2hex(random_bytes(6));
        mkdir($root . '/component/null-key', 0777, true);
        file_put_contents(
            $root . '/component/null-key/null-key.twig',
            "{#\nname: \"Null Key\"\ndescription: \"x\"\nstyleguide:\n#}\n<div></div>\n",
        );

        $findings = (new Linter($root))->run();

        self::assertCount(1, $this->findingsFor($findings, 'no-fixture'));

        unlink($root . '/component/null-key/null-key.twig');
        rmdir($root . '/component/null-key');
        rmdir($root . '/component');
        rmdir($root);
    }

    #[Test]
    public function a_non_canonical_variant_filename_does_not_count_as_a_fixture(): void
    {
        // `styleguide.WIDE.twig` is excluded from the catalogue walk (it looks
        // like a fixture) but discoverVariants() rejects it — uppercase is not
        // a canonical variant id — so the component really does render nothing.
        // Matching the loose sibling pattern here would exempt exactly that
        // component from the rule.
        $root = sys_get_temp_dir() . '/sg-badvariant-' . bin2hex(random_bytes(6));
        mkdir($root . '/component/shouty', 0777, true);
        file_put_contents(
            $root . '/component/shouty/shouty.twig',
            "{#\nname: \"Shouty\"\ndescription: \"x\"\n#}\n<div></div>\n",
        );
        file_put_contents($root . '/component/shouty/styleguide.WIDE.twig', "<div></div>\n");

        $findings = (new Linter($root))->run();

        self::assertCount(1, $this->findingsFor($findings, 'no-fixture'));

        unlink($root . '/component/shouty/styleguide.WIDE.twig');
        unlink($root . '/component/shouty/shouty.twig');
        rmdir($root . '/component/shouty');
        rmdir($root . '/component');
        rmdir($root);
    }

    #[Test]
    public function kind_utility_declared_in_the_yaml_sidecar_also_exempts(): void
    {
        // The exemption reads `kind`, and after the precedence fix that value
        // can legitimately live only in `<id>.yaml`. Before it, the linter read
        // the twig comment and the exemption silently did not apply.
        $root = sys_get_temp_dir() . '/sg-utilyaml-' . bin2hex(random_bytes(6));
        mkdir($root . '/component/util', 0777, true);
        file_put_contents(
            $root . '/component/util/util.yaml',
            "name: Util\nkind: utility\ncategory: Basic\ndescription: no stable appearance\n",
        );
        file_put_contents($root . '/component/util/util.twig', "<div></div>\n");

        $findings = (new Linter($root))->run();

        self::assertSame([], $this->findingsFor($findings, 'no-fixture'));

        unlink($root . '/component/util/util.yaml');
        unlink($root . '/component/util/util.twig');
        rmdir($root . '/component/util');
        rmdir($root . '/component');
        rmdir($root);
    }

    #[Test]
    public function a_utility_component_is_exempt_from_the_no_fixture_rule(): void
    {
        // A utility renders whatever it is handed, so it has no stable
        // appearance a demo page could pin — the fixture-less state is correct,
        // not a gap. Without this exemption the rule would fire on exactly the
        // components whose authors did the right thing.
        $findings = (new Linter($this->fixtures))->run();

        self::assertSame([], array_values(array_filter(
            $findings,
            static fn(LintFinding $f): bool => str_contains($f->file, 'utility-no-fixture'),
        )));
    }

    #[Test]
    public function a_variant_sibling_alone_satisfies_the_no_fixture_rule(): void
    {
        // Mirrors ComponentParser's has_styleguide derivation: a component that
        // ships only named variant siblings and no bare styleguide.twig is
        // still renderable. Reporting it would contradict the runtime.
        $root = sys_get_temp_dir() . '/sg-variant-only-' . bin2hex(random_bytes(6));
        mkdir($root . '/component/only-variants', 0777, true);
        file_put_contents(
            $root . '/component/only-variants/only-variants.twig',
            "{#\nname: \"Only Variants\"\ndescription: \"x\"\n#}\n<div></div>\n",
        );
        file_put_contents($root . '/component/only-variants/styleguide.wide.twig', "<div></div>\n");

        $findings = (new Linter($root))->run();

        self::assertSame([], $this->findingsFor($findings, 'no-fixture'));

        unlink($root . '/component/only-variants/styleguide.wide.twig');
        unlink($root . '/component/only-variants/only-variants.twig');
        rmdir($root . '/component/only-variants');
        rmdir($root . '/component');
        rmdir($root);
    }


    #[Test]
    public function a_component_migrated_to_a_yaml_sidecar_is_linted_from_the_sidecar(): void
    {
        // tailwind-base ADR-0007 retires the twig front-comment per component as its
        // `<id>.yaml` lands, so once a project starts migrating, the two
        // sources disagree BY DESIGN — the twig file's first comment is then
        // just an ordinary code comment. Reading it directly linted a document
        // the catalogue never reads: downstream, a correct migration produced
        // 29 phantom `metadata-yaml-invalid` errors, one per migrated
        // component. The fixture's comment is deliberately not valid YAML.
        $findings = (new Linter(__DIR__ . '/../fixtures/lint-sidecar/templates'))->run();

        self::assertSame([], $findings, 'a migrated component must produce no findings at all');
    }


    #[Test]
    public function a_malformed_canonical_sidecar_is_reported_even_though_the_runtime_survives_it(): void
    {
        // ComponentParser SWALLOWS a broken `<id>.yaml` and falls back to the
        // twig front-comment — right for the renderer (one bad file must not
        // blank a component) but it means the canonical document can be broken
        // while the component renders perfectly and nothing anywhere says so.
        // Adopting the runtime's precedence without this would have traded one
        // blind spot for another.
        $findings = (new Linter(__DIR__ . '/../fixtures/lint-sidecar-broken/templates'))->run();
        $broken = $this->findingsFor($findings, 'sidecar-yaml-invalid');

        self::assertCount(1, $broken);
        self::assertSame(LintSeverity::Error, $broken[0]->severity);
        // Attributed to the .yaml — the file to fix — not the .twig we walked.
        self::assertSame('component/broken-sidecar/broken-sidecar.yaml', $broken[0]->file);
    }

    #[Test]
    public function a_dead_twig_front_comment_next_to_a_winning_sidecar_is_reported(): void
    {
        // The silent no-op tailwind-base ADR-0007 creates during migration: both documents
        // present, the sidecar wins, and edits to the twig block change
        // nothing. Downstream this had already produced three components whose
        // CORRECTED descriptions lived in the dead block and had never been
        // visible.
        $findings = (new Linter(__DIR__ . '/../fixtures/lint-sidecar-broken/templates'))->run();
        $dead = $this->findingsFor($findings, 'redundant-twig-metadata');

        self::assertCount(1, $dead);
        self::assertSame(LintSeverity::Warning, $dead[0]->severity);
        // Attributed to the .twig — that is the file to edit.
        self::assertSame('component/dead-twig-block/dead-twig-block.twig', $dead[0]->file);
    }

    #[Test]
    public function an_ordinary_code_comment_next_to_a_sidecar_is_not_mistaken_for_dead_metadata(): void
    {
        // The mirror of the bug this class fixed: after migration a template's
        // leading comment is usually prose about the markup. Reporting that as
        // redundant metadata would send authors to delete their code comments.
        $findings = (new Linter(__DIR__ . '/../fixtures/lint-sidecar/templates'))->run();

        self::assertSame([], $this->findingsFor($findings, 'redundant-twig-metadata'));
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
    public function flags_unknown_kind_value(): void
    {
        // Parity with unknown-render. `normaliseKind()` swallows an unrecognised
        // value into '' with no other signal, so a typo would otherwise reach the
        // API silently — the exact failure the render rule already guards against.
        $findings = (new Linter($this->fixtures))->run();
        $kind = $this->findingsFor($findings, 'unknown-kind');

        self::assertCount(1, $kind);
        self::assertSame(LintSeverity::Error, $kind[0]->severity);
        self::assertStringContainsString('sectoin', $kind[0]->message);
        self::assertStringContainsString('block|section|element|part|utility', $kind[0]->message);
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
