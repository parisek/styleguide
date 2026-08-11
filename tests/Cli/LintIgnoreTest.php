<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Cli;

use Parisek\Styleguide\Cli\Linter;
use Parisek\Styleguide\Cli\LintIgnore;
use Parisek\Styleguide\Cli\LintSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The ignore list, and the two guards that keep it from becoming a place where
 * checks go to die quietly: every entry must carry a reason, and an entry that
 * matches nothing reports itself.
 */
final class LintIgnoreTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = __DIR__ . '/../fixtures/lint/templates';
    }

    private function writeIgnoreFile(string $yaml): string
    {
        $path = sys_get_temp_dir() . '/sg-lintignore-' . bin2hex(random_bytes(6)) . '.yaml';
        file_put_contents($path, $yaml);

        return $path;
    }

    #[Test]
    public function an_ignored_finding_is_removed_from_the_result_and_kept_for_reporting(): void
    {
        // Not component/_partials/* — #112 made the walk skip underscore
        // directories outright, so that path produces no finding to suppress
        // and the entry itself would read as stale.
        $ignores = [new LintIgnore('component/nameless/*', 'unindexed', 'deliberately nameless')];
        $linter = new Linter($this->fixtures, $ignores);

        $findings = $linter->run();

        self::assertSame([], array_values(array_filter(
            $findings,
            static fn($f): bool => $f->rule === 'unindexed'
                && str_starts_with($f->file, 'component/nameless/'),
        )));
        // Suppression is retained, not discarded — the CLI announces the count,
        // because a hidden finding and an absent check look identical otherwise.
        self::assertCount(1, $linter->suppressedFindings());
        self::assertSame('unindexed', $linter->suppressedFindings()[0]->rule);
    }

    #[Test]
    public function ignoring_is_scoped_to_the_named_rule_not_the_whole_file(): void
    {
        // The entry below targets a file that DOES have a finding, but names a
        // different rule. Muting the file wholesale would hide the real one.
        $ignores = [new LintIgnore('component/dead-styleguide/dead-styleguide.twig', 'unindexed', 'wrong rule on purpose')];

        $findings = (new Linter($this->fixtures, $ignores))->run();

        $dead = array_values(array_filter($findings, static fn($f): bool => $f->rule === 'dead-styleguide-content'));
        self::assertCount(1, $dead);
    }

    #[Test]
    public function an_entry_that_matches_nothing_reports_itself_as_stale(): void
    {
        $ignores = [new LintIgnore('component/long-gone/long-gone.twig', 'unindexed', 'deleted last year')];

        $findings = (new Linter($this->fixtures, $ignores))->run();
        $stale = array_values(array_filter($findings, static fn($f): bool => $f->rule === 'stale-ignore'));

        self::assertCount(1, $stale);
        // Notice, not warning: a stale entry is untidy, never wrong, and must
        // not break the build of a project mid-refactor.
        self::assertSame(LintSeverity::Notice, $stale[0]->severity);
        self::assertStringContainsString('deleted last year', $stale[0]->message);
    }

    #[Test]
    public function a_scoped_run_does_not_call_another_type_stale(): void
    {
        // A page entry cannot match anything in a component-only run, because
        // that run never walks page templates. Judging it against those partial
        // findings told the reader to delete an entry that is doing its job.
        $ignores = [new LintIgnore('page/home/home.twig', 'unindexed', 'expected on the home page')];

        $findings = (new Linter($this->fixtures, $ignores))->run(['component']);
        $stale = array_values(array_filter($findings, static fn($f): bool => $f->rule === 'stale-ignore'));

        self::assertSame([], $stale);
    }

    #[Test]
    public function a_scoped_run_still_reports_a_stale_entry_of_its_own_type(): void
    {
        // The narrowing must not cost the signal where the run can see it.
        $ignores = [new LintIgnore('component/long-gone/long-gone.twig', 'unindexed', 'deleted last year')];

        $findings = (new Linter($this->fixtures, $ignores))->run(['component']);
        $stale = array_values(array_filter($findings, static fn($f): bool => $f->rule === 'stale-ignore'));

        self::assertCount(1, $stale);
    }

    #[Test]
    public function a_pattern_spanning_types_is_judged_not_skipped(): void
    {
        // The first segment is a pattern, so the entry can reach any type. A
        // skipped entry is never checked at all — worse than a false notice.
        $ignores = [new LintIgnore('*/long-gone/long-gone.twig', 'unindexed', 'wildcard type segment')];

        $findings = (new Linter($this->fixtures, $ignores))->run(['component']);
        $stale = array_values(array_filter($findings, static fn($f): bool => $f->rule === 'stale-ignore'));

        self::assertCount(1, $stale);
    }

    #[Test]
    public function a_stale_ignore_finding_cannot_be_silenced_by_the_ignore_list(): void
    {
        // Otherwise the anti-rot mechanism defeats itself: one entry excusing
        // its own staleness would let the whole list outlive its subject.
        $ignores = [
            new LintIgnore('component/long-gone/long-gone.twig', 'unindexed', 'deleted last year'),
            new LintIgnore('*', 'stale-ignore', 'trying to mute the guard'),
        ];

        $findings = (new Linter($this->fixtures, $ignores))->run();

        self::assertNotSame([], array_values(array_filter(
            $findings,
            static fn($f): bool => $f->rule === 'stale-ignore',
        )));
    }

    #[Test]
    public function a_glob_covers_a_whole_fragment_subtree(): void
    {
        $ignore = new LintIgnore('page/_partials/*', 'unindexed', 'fragments');
        $finding = new \Parisek\Styleguide\Cli\LintFinding(
            LintSeverity::Warning,
            'page/_partials/deep/nested.twig',
            'unindexed',
            'x',
        );

        // Nested, not just one level down: a fragment directory is a subtree.
        self::assertTrue($ignore->matches($finding));
    }

    #[Test]
    public function an_entry_without_a_reason_is_rejected(): void
    {
        $path = $this->writeIgnoreFile("ignore:\n  - file: a.twig\n    rule: unindexed\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing a non-empty `reason`/');
        LintIgnore::fromFile($path);
    }

    #[Test]
    public function malformed_yaml_throws_instead_of_degrading_to_no_ignores(): void
    {
        // Silently degrading would surface as a sudden flood of findings, which
        // reads like a regression in the templates rather than in this file.
        $path = $this->writeIgnoreFile("ignore:\n  - file: \"unclosed\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid YAML/');
        LintIgnore::fromFile($path);
    }

    #[Test]
    public function a_file_without_the_ignore_key_throws(): void
    {
        $path = $this->writeIgnoreFile("something_else: true\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no `ignore:` key/');
        LintIgnore::fromFile($path);
    }

    #[Test]
    public function an_empty_file_is_accepted_as_no_ignores(): void
    {
        self::assertSame([], LintIgnore::fromFile($this->writeIgnoreFile("\n")));
    }
}
