<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Cli;

use Parisek\Styleguide\Cli\Command;
use Parisek\Styleguide\Cli\Linter;
use Parisek\Styleguide\Cli\LintIgnore;
use Parisek\Styleguide\Cli\LintSeverity;
use Parisek\Styleguide\Cli\NullContentCheck;
use Parisek\Styleguide\RenderObserver;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `null-content` lint rule, and the observer channel under it.
 *
 * tests/fixtures/null-content: `card` passes `content.pagination` and
 * `content.badge` straight through. Its default fixture sets neither (two
 * nulls), `full` sets both, `empty` sets both to an intentional empty value.
 */
final class NullContentLintTest extends TestCase
{
    private string $project;

    private string|false $previousLog = false;

    protected function setUp(): void
    {
        $this->project = __DIR__ . '/../fixtures/null-content';
        // The runtime writes a line per null call site; keep it out of the
        // test runner's output.
        $this->previousLog = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog === false ? '' : $this->previousLog);
    }

    private function styleguide(): Styleguide
    {
        return Styleguide::fromYaml($this->project . '/styleguide.yaml');
    }

    /**
     * @param list<string> $argv
     * @return array{0:int, 1:string, 2:string}
     */
    private function lint(array $argv): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        $exit = (new Command())->run(
            ['lint', '--templates=' . $this->project . '/templates', '--config=' . $this->project . '/styleguide.yaml', ...$argv],
            $stdout,
            $stderr,
        );
        rewind($stdout);
        rewind($stderr);

        return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    #[Test]
    public function the_observer_keeps_a_null_call_apart_from_an_empty_array(): void
    {
        $observer = new RenderObserver();
        $fixture = ['kind' => 'component', 'slug' => 'card', 'variant' => null];
        $observer->arm($fixture);
        $observer->nullContent('component', 'pagination', '@component/card/card.twig', 6);
        $observer->nullContent('component', 'pagination', '@component/card/card.twig', 6);
        $observer->enter('pagination', []);
        $observer->exit();
        $observed = $observer->disarmWithNullContent();

        // The call itself reads [] as before; the null list is the difference.
        self::assertSame([], $observed['calls'][0]['arguments']);
        self::assertSame(
            [['component' => 'pagination', 'kind' => 'component', 'fixture' => $fixture, 'caller' => ['template' => '@component/card/card.twig', 'line' => 6]]],
            $observed['nullContent'],
        );
    }

    #[Test]
    public function an_unarmed_observer_records_nothing(): void
    {
        $observer = new RenderObserver();
        $observer->nullContent('component', 'pagination', 'x.twig', 1);

        self::assertSame(['calls' => [], 'nullContent' => []], $observer->disarmWithNullContent());
    }

    #[Test]
    public function render_observed_returns_null_calls_in_a_new_key_and_leaves_the_rest(): void
    {
        $trace = $this->styleguide()->renderObserved('component', 'card');

        self::assertSame(['html', 'status', 'calls', 'unobservable', 'null_content'], array_keys($trace));
        self::assertSame(200, $trace['status']);
        self::assertSame(['card', 'pagination', 'badge'], array_column($trace['calls'], 'component'));
        self::assertSame(['pagination', 'badge'], array_column($trace['null_content'], 'component'));
        self::assertSame('@component/card/card.twig', $trace['null_content'][0]['caller']['template']);
        self::assertSame([6, 7], array_column(array_column($trace['null_content'], 'caller'), 'line'));
    }

    #[Test]
    public function a_second_render_on_the_same_runtime_reports_again(): void
    {
        // The log line is once per call site; the observer must not share that.
        $styleguide = $this->styleguide();
        $styleguide->renderObserved('component', 'card');
        $second = $styleguide->renderObserved('page', 'home');

        self::assertSame(['pagination', 'badge'], array_column($second['null_content'], 'component'));
        self::assertSame('page', $second['null_content'][0]['fixture']['kind']);
    }

    #[Test]
    public function an_array_an_empty_array_and_a_missing_argument_are_not_null_calls(): void
    {
        $styleguide = $this->styleguide();

        self::assertSame([], $styleguide->renderObserved('component', 'card', 'full')['null_content']);
        self::assertSame([], $styleguide->renderObserved('component', 'card', 'empty')['null_content']);
        self::assertSame([], $styleguide->renderObserved('component', 'pagination')['null_content']);
    }

    #[Test]
    public function a_finding_names_fixture_caller_line_and_component(): void
    {
        $findings = array_values(array_filter(
            (new NullContentCheck())->run($this->styleguide(), ['component']),
            static fn($f): bool => $f->rule === 'null-content',
        ));

        self::assertCount(2, $findings);
        self::assertSame(LintSeverity::Warning, $findings[0]->severity);
        self::assertSame('component/card/card.twig', $findings[0]->file);
        self::assertSame('pagination', $findings[0]->component);
        self::assertSame(6, $findings[0]->line);
        self::assertSame('component/card', $findings[0]->fixture);
        self::assertStringContainsString('component_pagination() gets null content at line 6', $findings[0]->message);
        self::assertSame(
            ['severity' => 'warning', 'file' => 'component/card/card.twig', 'rule' => 'null-content', 'message' => $findings[0]->message, 'component' => 'pagination', 'line' => 6, 'fixture' => 'component/card'],
            $findings[0]->toArray(),
        );
    }

    #[Test]
    public function types_limit_the_fixtures_that_render(): void
    {
        $fixtures = array_unique(array_map(
            static fn($f): ?string => $f->fixture,
            array_filter((new NullContentCheck())->run($this->styleguide(), ['page']), static fn($f): bool => $f->rule === 'null-content'),
        ));

        self::assertSame(['page/home'], array_values($fixtures));
    }

    #[Test]
    public function a_fixture_that_does_not_render_is_its_own_finding(): void
    {
        $failed = array_values(array_filter(
            (new NullContentCheck())->run($this->styleguide(), ['component']),
            static fn($f): bool => $f->rule === 'render-failed',
        ));

        self::assertCount(1, $failed);
        self::assertSame('component/broken', $failed[0]->fixture);
        self::assertSame(LintSeverity::Warning, $failed[0]->severity);
    }

    #[Test]
    public function the_cli_reports_the_rule_and_fails_the_build(): void
    {
        [$exit, $stdout] = $this->lint(['--type=component', '--format=json']);

        self::assertSame(1, $exit);
        $rules = array_column(json_decode($stdout, true, 512, JSON_THROW_ON_ERROR), 'rule');
        self::assertContains('null-content', $rules);
    }

    #[Test]
    public function no_render_skips_the_rule(): void
    {
        [, $stdout, $stderr] = $this->lint(['--no-render', '--format=json']);

        self::assertNotContains('null-content', array_column(json_decode($stdout, true, 512, JSON_THROW_ON_ERROR), 'rule'));
        self::assertSame('', $stderr);
    }

    #[Test]
    public function without_a_styleguide_yaml_the_rule_is_skipped_out_loud(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        $cwd = getcwd();
        chdir(sys_get_temp_dir());
        try {
            (new Command())->run(['lint', '--templates=' . $this->project . '/templates', '--format=json'], $stdout, $stderr);
        } finally {
            chdir((string) $cwd);
        }
        rewind($stdout);
        rewind($stderr);

        self::assertNotContains('null-content', array_column(json_decode((string) stream_get_contents($stdout), true, 512, JSON_THROW_ON_ERROR), 'rule'));
        self::assertStringContainsString('null-content skipped: no styleguide.yaml', (string) stream_get_contents($stderr));
    }

    #[Test]
    public function an_ignore_entry_matches_caller_template_and_component(): void
    {
        $all = (new NullContentCheck())->run($this->styleguide(), ['component']);
        $ignores = [new LintIgnore('component/card/card.twig', 'null-content', 'pagination is optional', 'pagination')];
        $linter = new Linter($this->project . '/templates', $ignores);

        $kept = array_values(array_filter(
            $linter->run(['component'], $all),
            static fn($f): bool => $f->rule === 'null-content',
        ));

        // `badge` is called from the same template and stays reported.
        self::assertCount(1, $kept);
        self::assertSame('badge', $kept[0]->component);
        self::assertCount(1, array_filter($linter->suppressedFindings(), static fn($f): bool => $f->component === 'pagination'));
    }

    #[Test]
    public function an_ignore_entry_that_matches_nothing_is_stale(): void
    {
        $all = (new NullContentCheck())->run($this->styleguide(), ['component']);
        $ignores = [new LintIgnore('component/card/card.twig', 'null-content', 'was optional', 'gone')];
        $linter = new Linter($this->project . '/templates', $ignores);

        $stale = array_values(array_filter(
            $linter->run(null, $all),
            static fn($f): bool => $f->rule === 'stale-ignore',
        ));

        self::assertCount(1, $stale);
        self::assertStringContainsString('component `gone`', $stale[0]->message);
    }

    #[Test]
    public function an_entry_for_the_rule_is_not_judged_when_the_rule_did_not_run(): void
    {
        $ignores = [new LintIgnore('component/card/card.twig', 'null-content', 'optional', 'pagination')];

        foreach ([[null, null], [['component'], []]] as [$types, $renderFindings]) {
            $findings = (new Linter($this->project . '/templates', $ignores))->run($types, $renderFindings);

            self::assertSame([], array_filter($findings, static fn($f): bool => $f->rule === 'stale-ignore'));
        }
    }

    #[Test]
    public function the_ignore_file_needs_a_component_for_this_rule_and_refuses_one_elsewhere(): void
    {
        $write = static function (string $yaml): string {
            $path = sys_get_temp_dir() . '/sg-nc-' . bin2hex(random_bytes(6)) . '.yaml';
            file_put_contents($path, $yaml);

            return $path;
        };

        $ok = LintIgnore::fromFile($write("ignore:\n  - file: component/card/card.twig\n    rule: null-content\n    component: component_pagination\n    reason: optional\n"));
        self::assertSame('component-pagination', $ok[0]->component);

        foreach ([
            "ignore:\n  - file: a.twig\n    rule: null-content\n    reason: r\n",
            "ignore:\n  - file: a.twig\n    rule: unindexed\n    component: x\n    reason: r\n",
        ] as $yaml) {
            try {
                LintIgnore::fromFile($write($yaml));
                self::fail('Expected a RuntimeException.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('component', $e->getMessage());
            }
        }
    }
}
