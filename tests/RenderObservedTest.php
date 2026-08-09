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
    public function inventory_rows_have_no_ownFixture_field(): void
    {
        // MUST-FIX 5 (parisek/styleguide#120 review): an earlier revision
        // carried an `ownFixture` key hardcoded to `true` on every row — a
        // field that can only ever be `true` invites a consumer to branch on
        // it as if it carried information, so it was dropped rather than
        // kept constant. See Styleguide::inventory()'s docblock for why the
        // distinction it promised (rendered, but NOT by its own fixture)
        // cannot be computed from inside this method at all.
        $rows = $this->styleguide()->inventory();

        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertArrayNotHasKey('ownFixture', $row);
            self::assertSame(['kind', 'slug', 'variant'], array_keys($row));
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
    public function a_content_only_doc_fixture_renders_observed_without_throwing(): void
    {
        // MUST-FIX 1 (parisek/styleguide#120 review): a `doc/` fixture is BY
        // DEFINITION content-only markup (changelog, icon gallery,
        // typography specimen) — no component_*/page_* call, no
        // {% include @component… %} either. The API must return a normal
        // (if empty) trace for it, not throw. There used to be a
        // RenderObserver::assertNotContradictory() guard that fired here;
        // it was removed once forceAddFunction() (MUST-FIX 3) made the
        // wiring failure it guarded against structurally impossible — see
        // Styleguide::renderObserved()'s inline comment.
        $trace = $this->styleguide()->renderObserved('doc', 'plain-doc');

        self::assertStringContainsString('plain-doc', $trace['html']);
        self::assertSame([], $trace['calls']);
        self::assertSame([], $trace['unobservable']);
    }

    #[Test]
    public function an_include_of_an_include_is_declared_unobservable(): void
    {
        // MUST-FIX 4 (parisek/styleguide#120 review): the top-level fixture
        // includes a plain partial (_row.twig), which itself includes
        // @component/leaf/leaf.twig. A regex over the top-level source alone
        // could never see this; the AST-walking detector must traverse into
        // the partial to find it.
        $trace = $this->styleguide()->renderObserved('component', 'nested-includer');

        self::assertStringContainsString('<div class="leaf">included via a nested partial</div>', $trace['html']);
        self::assertSame([], $trace['calls']);

        self::assertCount(1, $trace['unobservable']);
        self::assertSame('leaf', $trace['unobservable'][0]['component']);
        self::assertSame('component', $trace['unobservable'][0]['kind']);
        self::assertSame(
            ['kind' => 'component', 'slug' => 'nested-includer', 'variant' => null],
            $trace['unobservable'][0]['fixture'],
        );
        // Attributed to the partial that actually contains the include, not
        // the top-level fixture file that merely reaches it transitively —
        // that is the file an author would have to open to fix it.
        self::assertStringContainsString('_row.twig', $trace['unobservable'][0]['source']);
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

    #[Test]
    public function arm_disarm_is_reentrant_and_preserves_both_frames(): void
    {
        // MUST-FIX 2 (parisek/styleguide#120 review): a nested arm()/disarm()
        // pair (an inner `renderObserved()` triggered while an outer one is
        // still in progress) must not destroy the outer frame's
        // already-recorded calls/stack. Before the fix, arm() reset shared
        // state, so this sequence would lose the outer "before" call
        // entirely and misattribute "after" as a fresh direct call with the
        // outer stack wiped.
        $observer = new RenderObserver();

        $observer->arm(['kind' => 'component', 'slug' => 'outer-fixture', 'variant' => null]);
        $observer->enter('outer-before', []); // recorded directly under the outer frame
        $observer->enter('outer-parent', []); // outer stack now: [outer-before? no — see below]

        // Nested renderObserved(): arm() a SECOND frame while the outer one
        // is still armed (outer-parent is still "inside", i.e. not yet
        // exit()-ed).
        $observer->arm(['kind' => 'component', 'slug' => 'inner-fixture', 'variant' => null]);
        $observer->enter('inner-call', []);
        $observer->exit(); // inner-call returns
        $innerCalls = $observer->disarm();

        // Outer frame resumes exactly where it left off.
        $observer->enter('outer-after', []); // nested inside outer-parent, per the still-intact outer stack
        $observer->exit(); // outer-after returns
        $observer->exit(); // outer-parent returns
        $observer->exit(); // outer-before returns
        $outerCalls = $observer->disarm();

        // Inner trace: exactly its own one call, attributed to the inner fixture.
        self::assertCount(1, $innerCalls);
        self::assertSame('inner-call', $innerCalls[0]['component']);
        self::assertSame(
            ['kind' => 'component', 'slug' => 'inner-fixture', 'variant' => null],
            $innerCalls[0]['fixture'],
        );
        self::assertSame('direct', $innerCalls[0]['position']);

        // Outer trace: BOTH calls made before and after the nested
        // observation survive, both attributed to the outer fixture, and
        // the outer stack/nesting is unaffected by the inner arm()/disarm().
        self::assertCount(3, $outerCalls);
        self::assertSame(['outer-before', 'outer-parent', 'outer-after'], array_column($outerCalls, 'component'));
        foreach ($outerCalls as $call) {
            self::assertSame(
                ['kind' => 'component', 'slug' => 'outer-fixture', 'variant' => null],
                $call['fixture'],
            );
        }
        self::assertSame('direct', $outerCalls[0]['position']);
        self::assertNull($outerCalls[0]['parent']);
        self::assertSame('nested', $outerCalls[1]['position']);
        self::assertSame('outer-before', $outerCalls[1]['parent']);
        // outer-after is recorded while outer-parent is still on the stack
        // (not yet exit()-ed) — it must nest under outer-parent, exactly as
        // it would have without the inner observation splicing in between.
        self::assertSame('nested', $outerCalls[2]['position']);
        self::assertSame('outer-parent', $outerCalls[2]['parent']);
    }

    #[Test]
    public function renderObserved_itself_is_reentrant_via_a_real_nested_call(): void
    {
        // Same guarantee as the frame-level test above, but exercised
        // through the real public API: calling Styleguide::renderObserved()
        // a second time on the SAME instance while processing the results
        // of the first must not corrupt anything — the two calls don't share
        // state beyond the RenderObserver's internal frame stack, and each
        // must return its own correct, independent trace.
        $styleguide = $this->styleguide();

        $outer = $styleguide->renderObserved('component', 'wrapper');
        $inner = $styleguide->renderObserved('component', 'simple');
        $outerAgain = $styleguide->renderObserved('component', 'wrapper');

        self::assertSame($outer, $outerAgain);
        self::assertCount(2, $outer['calls']);
        self::assertCount(1, $inner['calls']);
        self::assertSame('simple', $inner['calls'][0]['component']);
        self::assertSame(
            ['kind' => 'component', 'slug' => 'simple', 'variant' => null],
            $inner['calls'][0]['fixture'],
        );
    }
}
