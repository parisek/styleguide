<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\RenderObserver;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers `Styleguide::renderObserved()` / `Styleguide::inventory()` (issue
 * parisek/styleguide#119) — the render-observation API returning `{ html,
 * calls, unobservable }`, and the fixture inventory it's meant to be
 * cross-referenced against.
 *
 * Fixtures live under `tests/fixtures/trace/templates/`, mirroring the shape
 * of `tests/fixtures/nested/templates/` used by `NestedRenderFailureTest`.
 */
final class RenderObservedTest extends TestCase
{
    private function styleguide(): Styleguide
    {
        return new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/trace/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => __DIR__ . '/fixtures/nonexistent.yaml',
        ]);
    }

    #[Test]
    public function a_direct_component_call_is_recorded(): void
    {
        $trace = $this->styleguide()->renderObserved('component', 'simple');

        self::assertStringContainsString('<div class="simple">hello</div>', $trace['html']);
        self::assertCount(1, $trace['calls']);
        self::assertSame('simple', $trace['calls'][0]['component']);
        self::assertSame(['label' => 'hello'], $trace['calls'][0]['arguments']);
        self::assertSame('direct', $trace['calls'][0]['position']);
        self::assertNull($trace['calls'][0]['parent']);
        self::assertSame(
            ['kind' => 'component', 'slug' => 'simple', 'variant' => null],
            $trace['calls'][0]['fixture'],
        );
        self::assertSame([], $trace['unobservable']);
    }

    #[Test]
    public function a_nested_component_call_is_recorded_with_its_parent(): void
    {
        $trace = $this->styleguide()->renderObserved('component', 'wrapper');

        self::assertCount(2, $trace['calls']);

        $direct = $trace['calls'][0];
        self::assertSame('wrapper', $direct['component']);
        self::assertSame('direct', $direct['position']);
        self::assertNull($direct['parent']);

        $nested = $trace['calls'][1];
        self::assertSame('leaf', $nested['component']);
        self::assertSame('nested', $nested['position']);
        self::assertSame('wrapper', $nested['parent']);
        self::assertSame(['label' => 'nested hello'], $nested['arguments']);
        // Both calls originate from the SAME top-level fixture being
        // rendered — the fixture that triggered the whole render tree, not
        // "leaf"'s own (unrelated) fixture.
        self::assertSame(
            ['kind' => 'component', 'slug' => 'wrapper', 'variant' => null],
            $nested['fixture'],
        );
    }

    #[Test]
    public function an_include_of_a_component_template_is_declared_unobservable(): void
    {
        $trace = $this->styleguide()->renderObserved('component', 'includer');

        // The include renders leaf's markup directly — html is non-empty —
        // but bypasses component_* entirely, so no call is recorded for it.
        self::assertStringContainsString('<div class="leaf">included directly</div>', $trace['html']);
        self::assertSame([], $trace['calls']);

        self::assertCount(1, $trace['unobservable']);
        self::assertSame('leaf', $trace['unobservable'][0]['component']);
        self::assertSame('component', $trace['unobservable'][0]['kind']);
        self::assertSame(
            ['kind' => 'component', 'slug' => 'includer', 'variant' => null],
            $trace['unobservable'][0]['fixture'],
        );
        self::assertStringContainsString('includer', $trace['unobservable'][0]['source']);
    }

    #[Test]
    public function inventory_order_is_stable_across_two_separate_calls(): void
    {
        $sg = $this->styleguide();

        $first = $sg->inventory();
        $second = $sg->inventory();

        self::assertNotEmpty($first);
        self::assertSame($first, $second);

        // Also stable across a freshly constructed instance — the ordering
        // comes from ComponentParser::parseAll()'s own deterministic sort,
        // not from any per-instance state.
        $third = $this->styleguide()->inventory();
        self::assertSame($first, $third);
    }

    #[Test]
    public function inventory_rows_are_always_their_own_fixture(): void
    {
        // See Styleguide::inventory()'s docblock: `ownFixture` is always
        // true because this method only enumerates fixtures that exist as
        // real kind/slug demo files — every row it can produce already is
        // that kind/slug's own fixture.
        $rows = $this->styleguide()->inventory();

        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertTrue($row['ownFixture'], sprintf('%s/%s should be ownFixture', $row['kind'], $row['slug']));
        }

        $slugs = array_map(static fn(array $row): string => $row['kind'] . '/' . $row['slug'], $rows);
        self::assertContains('component/simple', $slugs);
        self::assertContains('component/wrapper', $slugs);
        self::assertContains('component/leaf', $slugs);
        self::assertContains('component/includer', $slugs);
    }

    #[Test]
    public function render_observed_rejects_an_unsupported_kind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->styleguide()->renderObserved('foundations', 'whatever');
    }

    #[Test]
    public function assertion_fires_on_the_constructed_contradiction(): void
    {
        // The invariant this method guards against — non-empty HTML with an
        // empty trace — cannot currently be provoked through the public
        // renderObserved() surface: the recorder is unconditionally wired
        // into component_*/page_* by registerBundledHelpers(), which every
        // Styleguide construction runs, so there is no code path left where
        // a REAL render produces this combination other than a fixture with
        // no component_*/page_*/include call at all (a legitimate, if rare,
        // shape — see the docblock on RenderObserver::assertNotContradictory()
        // for why that specific case is accepted rather than special-cased
        // away). The pure invariant is therefore tested directly here rather
        // than by engineering a broken-wiring scenario that no longer exists.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/not.*wired/i');

        RenderObserver::assertNotContradictory('<div>content</div>', [], []);
    }

    #[Test]
    public function assertion_does_not_fire_when_calls_are_present(): void
    {
        RenderObserver::assertNotContradictory('<div>content</div>', [['component' => 'x']], []);
        $this->addToAssertionCount(1); // no exception thrown
    }

    #[Test]
    public function assertion_does_not_fire_when_only_unobservable_calls_are_present(): void
    {
        RenderObserver::assertNotContradictory('<div>content</div>', [], [['component' => 'x']]);
        $this->addToAssertionCount(1); // no exception thrown
    }

    #[Test]
    public function assertion_does_not_fire_on_genuinely_empty_html(): void
    {
        // An empty render (nothing rendered at all) pairs naturally with an
        // empty trace — not a contradiction.
        RenderObserver::assertNotContradictory('', [], []);
        $this->addToAssertionCount(1); // no exception thrown
    }

    #[Test]
    public function nesting_is_derived_from_a_call_stack_not_a_bare_depth_counter(): void
    {
        // RenderObserver::enter()/exit() must correctly pop back to "direct"
        // after a nested call returns, so a SECOND direct call sitting after
        // a nested one in the same render is not misreported as nested too.
        // wrapper.twig itself only calls component_leaf once, so this is
        // exercised indirectly by asserting the full call count/order above;
        // this test pins the stack behaviour explicitly via RenderObserver.
        $observer = new RenderObserver();
        $observer->arm(['kind' => 'component', 'slug' => 'x', 'variant' => null]);

        $observer->enter('outer', []);
        $observer->enter('inner', []);
        $observer->exit(); // inner returns
        $observer->enter('sibling', []);
        $observer->exit(); // sibling returns
        $observer->exit(); // outer returns

        $calls = $observer->disarm();

        self::assertCount(3, $calls);
        self::assertSame('direct', $calls[0]['position']);
        self::assertNull($calls[0]['parent']);
        self::assertSame('nested', $calls[1]['position']);
        self::assertSame('outer', $calls[1]['parent']);
        // "sibling" is recorded while "inner" has already exited but "outer"
        // has not — it must nest under "outer", not "inner".
        self::assertSame('nested', $calls[2]['position']);
        self::assertSame('outer', $calls[2]['parent']);
    }
}
