<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * scripts/changelog-links.py runs in the Stamp Release workflow (`sync`) and in
 * CI (`check`). Each test runs it as a real subprocess on a temp CHANGELOG.
 */
final class ChangelogLinksTest extends TestCase
{
    private const REPO = 'https://github.com/o/r';

    private string $dir;
    private string $script;

    protected function setUp(): void
    {
        $script = realpath(__DIR__ . '/../scripts/changelog-links.py');
        self::assertNotFalse($script, 'scripts/changelog-links.py missing');
        $this->script = $script;
        $this->dir = sys_get_temp_dir() . '/changelog-links-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * @param list<string> $headings versions that have a `## [x]` heading
     * @param list<string> $refs     full reference lines, after `[Unreleased]`
     */
    private function changelog(array $headings, array $refs, string $unreleasedFrom): string
    {
        $out = "# Changelog\n\n## [Unreleased]\n\n";
        foreach ($headings as $v) {
            $out .= "## [$v] - 2026-01-01\n\n- entry\n\n";
        }
        $out .= '[Unreleased]: ' . self::REPO . "/compare/v$unreleasedFrom...HEAD\n";
        foreach ($refs as $ref) {
            $out .= $ref . "\n";
        }

        return $out;
    }

    private function ref(string $from, string $to): string
    {
        return "[$to]: " . self::REPO . "/compare/v$from...v$to";
    }

    /**
     * @param list<string> $tags without the v prefix
     * @param list<string> $extra
     * @return array{int, string, string, string} exit code, stdout, stderr, resulting CHANGELOG
     */
    private function runScript(string $command, string $changelog, array $tags, array $extra = []): array
    {
        $cl = $this->dir . '/CHANGELOG.md';
        $tf = $this->dir . '/tags.txt';
        file_put_contents($cl, $changelog);
        file_put_contents($tf, implode("\n", array_map(static fn(string $t) => "v$t", $tags)) . "\n");

        $cmd = array_merge(['python3', '-I', $this->script, $command, '--changelog', $cl, '--tags-file', $tf], $extra);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $code = proc_close($proc);

        return [$code, $stdout, $stderr, (string) file_get_contents($cl)];
    }

    #[Test]
    public function sync_adds_missing_refs_newest_first_above_the_existing_ones(): void
    {
        $in = $this->changelog(['1.2.0', '1.1.0', '1.0.0'], [$this->ref('0.9.0', '1.0.0')], '1.0.0');
        [$code, , , $out] = $this->runScript('sync', $in, ['0.9.0', '1.0.0', '1.1.0', '1.2.0']);

        self::assertSame(0, $code);
        $expected = $this->changelog(
            ['1.2.0', '1.1.0', '1.0.0'],
            [$this->ref('1.1.0', '1.2.0'), $this->ref('1.0.0', '1.1.0'), $this->ref('0.9.0', '1.0.0')],
            '1.2.0',
        );
        self::assertSame($expected, $out);
    }

    #[Test]
    public function previous_tag_is_the_newest_lower_tag_even_without_a_heading(): void
    {
        // 1.1.0 and 1.2.0 are tags without headings: 1.3.0 ranges from 1.2.0.
        $in = $this->changelog(['1.3.0', '1.0.0'], [$this->ref('0.9.0', '1.0.0')], '1.0.0');
        [$code, , , $out] = $this->runScript('sync', $in, ['0.9.0', '1.0.0', '1.1.0', '1.2.0', '1.3.0']);

        self::assertSame(0, $code);
        self::assertStringContainsString($this->ref('1.2.0', '1.3.0') . "\n", $out);
    }

    #[Test]
    public function previous_tag_compares_numbers_not_strings(): void
    {
        $in = $this->changelog(['1.10.0', '1.9.0'], [], '1.9.0');
        [$code, , , $out] = $this->runScript('sync', $in, ['1.2.0', '1.9.0', '1.10.0']);

        self::assertSame(0, $code);
        self::assertStringContainsString($this->ref('1.9.0', '1.10.0') . "\n", $out);
        self::assertStringContainsString('[Unreleased]: ' . self::REPO . "/compare/v1.10.0...HEAD\n", $out);
    }

    /**
     * @return array<string, array{string, list<string>, string, string}>
     */
    public static function releaseKinds(): array
    {
        // version being stamped, existing tags, expected range start, expected [Unreleased] start
        return [
            'patch' => ['1.2.4', ['1.2.2', '1.2.3'], '1.2.3', '1.2.4'],
            'minor' => ['1.3.0', ['1.2.0', '1.2.3'], '1.2.3', '1.3.0'],
            'major' => ['2.0.0', ['1.0.0', '1.9.9'], '1.9.9', '2.0.0'],
            'patch on an older line' => ['1.1.1', ['1.1.0', '1.2.0'], '1.1.0', '1.2.0'],
        ];
    }

    /**
     * @param list<string> $existingTags
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('releaseKinds')]
    public function stamp_with_version_adds_the_ref_and_moves_unreleased(string $version, array $existingTags, string $rangeFrom, string $unreleasedFrom): void
    {
        // The heading is already stamped; the tag does not exist yet.
        $in = $this->changelog([$version, ...array_reverse($existingTags)], [], end($existingTags));
        [$code, , , $out] = $this->runScript('sync', $in, $existingTags, ['--version', $version]);

        self::assertSame(0, $code);
        self::assertStringContainsString($this->ref($rangeFrom, $version) . "\n", $out);
        self::assertStringContainsString('[Unreleased]: ' . self::REPO . "/compare/v$unreleasedFrom...HEAD\n", $out);
    }

    #[Test]
    public function sync_is_idempotent(): void
    {
        $in = $this->changelog(['1.1.0', '1.0.0'], [$this->ref('0.9.0', '1.0.0')], '1.0.0');
        [, , , $once] = $this->runScript('sync', $in, ['0.9.0', '1.0.0', '1.1.0']);
        [$code, , , $twice] = $this->runScript('sync', $once, ['0.9.0', '1.0.0', '1.1.0']);

        self::assertSame(0, $code);
        self::assertSame($once, $twice);

        [, , , $withVersion] = $this->runScript('sync', $in, ['0.9.0', '1.0.0'], ['--version', '1.1.0']);
        [, , , $again] = $this->runScript('sync', $withVersion, ['0.9.0', '1.0.0', '1.1.0'], ['--version', '1.1.0']);
        self::assertSame($withVersion, $again);
    }

    #[Test]
    public function sync_never_rewrites_an_existing_ref_it_fails_on_a_wrong_one(): void
    {
        $odd = '[1.0.0]: ' . self::REPO . '/compare/v0.5.0...v1.0.0';
        $in = $this->changelog(['1.0.0'], [$odd], '1.0.0');
        [$code, , $stderr, $out] = $this->runScript('sync', $in, ['0.5.0', '0.9.0', '1.0.0']);

        self::assertSame(1, $code);
        self::assertStringContainsString('[1.0.0]: invalid range', $stderr);
        self::assertSame($in, $out);
    }

    #[Test]
    public function the_lowest_tag_links_to_its_release_page(): void
    {
        $in = $this->changelog(['1.0.0'], [], '1.0.0');
        [$code, , , $out] = $this->runScript('sync', $in, ['1.0.0']);

        self::assertSame(0, $code);
        self::assertStringContainsString('[1.0.0]: ' . self::REPO . "/releases/tag/v1.0.0\n", $out);
        [$checkCode] = $this->runScript('check', $out, ['1.0.0']);
        self::assertSame(0, $checkCode);
    }

    #[Test]
    public function sync_fails_loudly_when_the_reference_block_is_missing(): void
    {
        $in = "# Changelog\n\n## [Unreleased]\n\n## [1.0.0] - 2026-01-01\n\n- entry\n";
        [$code, , $stderr, $out] = $this->runScript('sync', $in, ['0.9.0', '1.0.0'], ['--version', '1.0.1']);

        self::assertSame(1, $code);
        self::assertStringContainsString('reference block is missing', $stderr);
        self::assertSame($in, $out, 'a failed run must not touch the file');
    }

    #[Test]
    public function sync_fails_when_a_heading_has_no_tag(): void
    {
        $in = $this->changelog(['1.1.0', '1.0.0'], [$this->ref('0.9.0', '1.0.0')], '1.0.0');
        [$code, , $stderr, $out] = $this->runScript('sync', $in, ['0.9.0', '1.0.0']);

        self::assertSame(1, $code);
        self::assertStringContainsString('1.1.0', $stderr);
        self::assertSame($in, $out);
    }

    #[Test]
    public function sync_fails_without_tags(): void
    {
        $in = $this->changelog(['1.0.0'], [], '1.0.0');
        [$code, , $stderr] = $this->runScript('sync', $in, []);

        self::assertSame(1, $code);
        self::assertStringContainsString('no vX.Y.Z tags', $stderr);
    }

    #[Test]
    public function check_passes_on_a_complete_changelog(): void
    {
        $in = $this->changelog(['1.1.0', '1.0.0'], [$this->ref('1.0.0', '1.1.0'), $this->ref('0.9.0', '1.0.0')], '1.1.0');
        [$code] = $this->runScript('check', $in, ['0.9.0', '1.0.0', '1.1.0']);

        self::assertSame(0, $code);
    }

    #[Test]
    public function check_reports_a_heading_without_a_ref(): void
    {
        $in = $this->changelog(['1.1.0', '1.0.0'], [$this->ref('0.9.0', '1.0.0')], '1.1.0');
        [$code, , $stderr, $out] = $this->runScript('check', $in, ['0.9.0', '1.0.0', '1.1.0']);

        self::assertSame(1, $code);
        self::assertStringContainsString('[1.1.0]: heading has no link reference', $stderr);
        self::assertSame($in, $out, 'check must not write');
    }

    #[Test]
    public function check_reports_a_stale_unreleased(): void
    {
        $in = $this->changelog(['1.0.0'], [$this->ref('0.9.0', '1.0.0')], '0.9.0');
        [$code, , $stderr] = $this->runScript('check', $in, ['0.9.0', '1.0.0']);

        self::assertSame(1, $code);
        self::assertStringContainsString('[Unreleased] starts at v0.9.0 but the newest tag is v1.0.0', $stderr);
    }

    #[Test]
    public function check_reports_ranges_that_are_not_valid(): void
    {
        foreach ([
            'reversed' => $this->ref('1.0.0', '0.9.0'),
            'unknown from tag' => $this->ref('0.8.0', '1.0.0'),
            'other target' => '[1.0.0]: ' . self::REPO . '/compare/v0.9.0...v1.1.0',
            'not a compare link' => '[1.0.0]: ' . self::REPO . '/releases/tag/v1.0.0',
        ] as $case => $ref) {
            $in = $this->changelog(['1.0.0'], [$ref], '1.0.0');
            [$code, , $stderr] = $this->runScript('check', $in, ['0.9.0', '1.0.0']);

            self::assertSame(1, $code, $case);
            self::assertStringContainsString('[1.0.0]:', $stderr, $case);
        }
    }

    #[Test]
    public function check_fails_when_the_reference_block_is_missing(): void
    {
        [$code, , $stderr] = $this->runScript('check', "# Changelog\n\n## [1.0.0] - 2026-01-01\n", ['1.0.0']);

        self::assertSame(1, $code);
        self::assertStringContainsString('reference block is missing', $stderr);
    }

    #[Test]
    public function sync_with_a_version_fails_when_its_heading_is_missing(): void
    {
        $in = $this->changelog(['1.3.0'], [$this->ref('1.2.0', '1.3.0')], '1.3.0');
        [$code, , $stderr, $out] = $this->runScript('sync', $in, ['1.2.0', '1.3.0'], ['--version', '1.4.0']);

        self::assertSame(1, $code);
        self::assertStringContainsString('no `## [1.4.0]` heading', $stderr);
        self::assertSame($in, $out);
    }

    #[Test]
    public function sync_fails_instead_of_keeping_a_wrong_ref_for_the_new_version(): void
    {
        $in = $this->changelog(['1.4.0', '1.3.0'], [$this->ref('0.0.1', '1.4.0'), $this->ref('1.2.0', '1.3.0')], '1.3.0');
        [$code, , $stderr, $out] = $this->runScript('sync', $in, ['1.2.0', '1.3.0'], ['--version', '1.4.0']);

        self::assertSame(1, $code);
        self::assertStringContainsString('invalid range', $stderr);
        self::assertSame($in, $out);
    }

    #[Test]
    public function check_requires_the_range_to_start_at_the_previous_tag(): void
    {
        $in = $this->changelog(['1.3.0'], [$this->ref('1.0.0', '1.3.0')], '1.3.0');
        [$code, , $stderr] = $this->runScript('check', $in, ['1.0.0', '1.2.0', '1.3.0']);

        self::assertSame(1, $code);
        self::assertStringContainsString('[1.3.0]: invalid range', $stderr);
    }

    #[Test]
    public function a_changelog_without_version_headings_fails(): void
    {
        $in = "# Changelog\n\n## [Unreleased]\n\n## Version 1.0.0\n\n[Unreleased]: " . self::REPO . "/compare/v1.0.0...HEAD\n";

        self::assertSame(1, $this->runScript('check', $in, ['1.0.0'])[0]);
        self::assertSame(1, $this->runScript('sync', $in, ['1.0.0'])[0]);
    }

    #[Test]
    public function a_version_with_a_leading_zero_is_rejected(): void
    {
        $in = $this->changelog(['1.0.0'], [], '1.0.0');
        [$code, , $stderr] = $this->runScript('sync', $in, ['1.0.0'], ['--version', '01.4.0']);

        self::assertSame(1, $code);
        self::assertStringContainsString('--version must be X.Y.Z', $stderr);
    }

    #[Test]
    public function a_ref_below_every_existing_ref_goes_after_the_last_one(): void
    {
        $in = $this->changelog(['1.1.0', '1.0.0'], [$this->ref('1.0.0', '1.1.0')], '1.1.0');
        [$code, , , $out] = $this->runScript('sync', $in, ['0.9.0', '1.0.0', '1.1.0']);

        self::assertSame(0, $code);
        $expected = $this->changelog(['1.1.0', '1.0.0'], [$this->ref('1.0.0', '1.1.0'), $this->ref('0.9.0', '1.0.0')], '1.1.0');
        self::assertSame($expected, $out);
    }
}
