<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * @internal Wiring detail behind `Styleguide::renderObserved()`. Not part of
 *           the SemVer-covered surface — see `Styleguide::renderObserved()`
 *           for the public contract this class exists to serve.
 *
 * Records every `component_*` / `page_*` invocation `Styleguide::
 * renderNamespaced()` produces while the observer is ARMED — always wired
 * into the closures `Styleguide::registerBundledHelpers()` registers, but a
 * no-op (single boolean check, no allocation) whenever it isn't armed. This
 * is what lets `renderObserved()` return a trace without the recorder being
 * a side effect of correct project wiring: the recorder is baked into the
 * `component_*`/`page_*` Twig functions themselves, not layered on top of
 * them by a consumer that has to register in the right order.
 *
 * Nesting is derived from a call-name stack rather than a bare depth
 * counter, so each recorded call can also report WHICH parent it nested
 * inside — the depth-only version would tell you a call was nested but not
 * where.
 */
final class RenderObserver
{
    private bool $armed = false;

    /** @var array{kind: string, slug: string, variant: string|null}|null */
    private ?array $fixture = null;

    /** @var list<string> Names of the calls currently "inside" — last element is the immediate parent. */
    private array $stack = [];

    /** @var list<array{component: string, arguments: array<string, mixed>, fixture: array{kind: string, slug: string, variant: string|null}, position: 'direct'|'nested', parent: string|null}> */
    private array $calls = [];

    /**
     * Arms the observer for one `renderObserved()` call and records which
     * top-level fixture the resulting calls originate from. Resets any
     * leftover state from a previous (already-disarmed) observation.
     *
     * @param array{kind: string, slug: string, variant: string|null} $fixture
     */
    public function arm(array $fixture): void
    {
        $this->armed = true;
        $this->fixture = $fixture;
        $this->stack = [];
        $this->calls = [];
    }

    /**
     * Disarms the observer and returns everything recorded since `arm()`.
     * Safe to call even when never armed (returns `[]`) — a defensive
     * complement to `arm()`, not a documented usage path.
     *
     * @return list<array{component: string, arguments: array<string, mixed>, fixture: array{kind: string, slug: string, variant: string|null}, position: 'direct'|'nested', parent: string|null}>
     */
    public function disarm(): array
    {
        $calls = $this->calls;
        $this->armed = false;
        $this->fixture = null;
        $this->stack = [];
        $this->calls = [];

        return $calls;
    }

    /**
     * Called by `Styleguide::renderNamespaced()` immediately BEFORE a
     * resolved component/page template renders. Cheap no-op when unarmed —
     * the stack still needs to track nesting depth regardless of arm state,
     * so a mid-render arm()/disarm() toggle (not a supported usage, but not
     * one this class needs to guard against either) can't desync `exit()`.
     *
     * @param array<string, mixed> $content Raw, unflattened `$content` array as passed to `component_*`/`page_*`.
     */
    public function enter(string $name, array $content): void
    {
        if ($this->armed && $this->fixture !== null) {
            $parent = $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
            $this->calls[] = [
                'component' => $name,
                'arguments' => $content,
                'fixture' => $this->fixture,
                'position' => $this->stack === [] ? 'direct' : 'nested',
                'parent' => $parent,
            ];
        }
        $this->stack[] = $name;
    }

    /**
     * Called by `Styleguide::renderNamespaced()` in a `finally` block around
     * the render call `enter()` preceded — pops the call stack regardless of
     * whether the render succeeded or threw, mirroring `Renderer::
     * renderInner()`'s own finally-reset pattern for `$currentKind`/
     * `$currentSlug`.
     */
    public function exit(): void
    {
        array_pop($this->stack);
    }

    /**
     * The internal invariant `renderObserved()` is built to make impossible:
     * non-empty HTML paired with an empty trace (no recorded calls AND no
     * declared unobservable includes) can only mean the recorder was not
     * actually wired into the render that produced the HTML — a
     * *structural* contradiction once `component_*`/`page_*` always thread
     * through this class, not a state a consumer needs to guard against.
     *
     * Not called unconditionally from `renderObserved()` — a fixture whose
     * template is plain markup with no `component_*`/`page_*`/`{% include
     * @component… %}` call at all is a legitimate (if unusual) render that
     * would otherwise trip this assertion on every observation. It exists
     * as a directly testable pure invariant instead; see
     * `RenderObserverTest::assertion_fires_on_the_constructed_contradiction()`.
     *
     * @param list<array<string, mixed>> $calls
     * @param list<array<string, mixed>> $unobservable
     */
    public static function assertNotContradictory(string $html, array $calls, array $unobservable): void
    {
        if ($html !== '' && $calls === [] && $unobservable === []) {
            throw new \LogicException(
                'Render observation produced non-empty HTML with an empty trace (no recorded calls, no '
                . 'declared unobservable includes) — that combination means the recorder was not wired '
                . 'into the render that produced this HTML, which should be structurally impossible when '
                . 'Styleguide::renderObserved() is used as documented.',
            );
        }
    }
}
