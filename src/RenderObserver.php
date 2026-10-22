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
 *
 * RE-ENTRANT by construction: `arm()`/`disarm()` push/pop a FRAME rather
 * than resetting a single set of fields. A `renderObserved()` call can
 * legitimately nest inside another one on the same `Styleguide`/`Renderer`
 * instance (e.g. a fixture render that itself invokes `renderObserved()`
 * for a sub-render), and a reset-based `arm()` would destroy the outer
 * frame's already-recorded calls and stack the moment the inner one armed,
 * leaving the outer trace truncated and unable to resume once the inner
 * `disarm()` finished. With a frame stack, `enter()`/`exit()` always act on
 * the TOP (innermost currently-armed) frame, and disarming the inner frame
 * simply exposes the outer one again, fully intact.
 */
final class RenderObserver
{
    /**
     * @var list<array{
     *   fixture: array{kind: string, slug: string, variant: string|null},
     *   stack: list<string>,
     *   calls: list<array{component: string, arguments: array<string, mixed>, fixture: array{kind: string, slug: string, variant: string|null}, position: 'direct'|'nested', parent: string|null}>,
     *   nullContent: array<string, array{component: string, kind: 'component'|'page', fixture: array{kind: string, slug: string, variant: string|null}, caller: array{template: string|null, line: int|null}}>,
     * }>
     * Last element is the innermost currently-armed observation — the one
     * `enter()`/`exit()` operate on. Empty means "not armed at all".
     */
    private array $frames = [];

    /**
     * Arms the observer for one `renderObserved()` call and records which
     * top-level fixture the resulting calls originate from. Pushes a NEW
     * frame rather than resetting shared state, so a re-entrant `arm()`
     * (called while an outer frame is still armed) leaves the outer frame's
     * calls/stack untouched underneath — see the class docblock.
     *
     * @param array{kind: string, slug: string, variant: string|null} $fixture
     */
    public function arm(array $fixture): void
    {
        $this->frames[] = ['fixture' => $fixture, 'stack' => [], 'calls' => [], 'nullContent' => []];
    }

    /**
     * Disarms the innermost frame and returns everything recorded since its
     * matching `arm()`, restoring whichever frame was armed before it (if
     * any) as the new top. Safe to call even when never armed (returns
     * `[]`) — a defensive complement to `arm()`, not a documented usage
     * path.
     *
     * @return list<array{component: string, arguments: array<string, mixed>, fixture: array{kind: string, slug: string, variant: string|null}, position: 'direct'|'nested', parent: string|null}>
     */
    public function disarm(): array
    {
        return $this->disarmWithNullContent()['calls'];
    }

    /**
     * Like {@see disarm()}, and also returns the calls that received `null`
     * content. They are a separate list, not a flag on `calls`: a consumer that
     * reads `calls[].arguments` as an array keeps working, and a null call
     * still shows there as `[]`, the value it rendered with.
     *
     * @return array{
     *   calls: list<array{component: string, arguments: array<string, mixed>, fixture: array{kind: string, slug: string, variant: string|null}, position: 'direct'|'nested', parent: string|null}>,
     *   nullContent: list<array{component: string, kind: 'component'|'page', fixture: array{kind: string, slug: string, variant: string|null}, caller: array{template: string|null, line: int|null}}>,
     * }
     */
    public function disarmWithNullContent(): array
    {
        $frame = array_pop($this->frames);

        return [
            'calls' => $frame['calls'] ?? [],
            'nullContent' => array_values($frame['nullContent'] ?? []),
        ];
    }

    /**
     * Records a `component_*()` / `page_*()` call whose content was `null`.
     *
     * Called BEFORE the call's own `enter()`, by the Twig runtime, which is the
     * only place that still sees the `null`: it renders as `[]` from there on.
     * One entry per fixture, component and call site, so a loop that passes
     * `null` twelve times is one entry, not twelve. Cheap no-op when unarmed.
     *
     * @param 'component'|'page' $kind
     */
    public function nullContent(string $kind, string $name, ?string $callerTemplate, ?int $callerLine): void
    {
        if ($this->frames === []) {
            return;
        }

        $top = array_key_last($this->frames);
        $key = $kind . '|' . $name . '|' . ($callerTemplate ?? '') . '|' . ($callerLine ?? '');
        $this->frames[$top]['nullContent'][$key] ??= [
            'component' => $name,
            'kind' => $kind,
            'fixture' => $this->frames[$top]['fixture'],
            'caller' => ['template' => $callerTemplate, 'line' => $callerLine],
        ];
    }

    /**
     * Called by `Styleguide::renderNamespaced()` immediately BEFORE a
     * resolved component/page template renders. Cheap no-op when unarmed
     * (`$this->frames === []`) — a single array-emptiness check, no
     * allocation. Operates on the innermost frame only, so nesting depth for
     * an outer frame is never disturbed by an inner `arm()`/`disarm()` pair
     * that happened in between two of the outer frame's own calls.
     *
     * @param array<string, mixed> $content Raw, unflattened `$content` array as passed to `component_*`/`page_*`.
     */
    public function enter(string $name, array $content): void
    {
        if ($this->frames === []) {
            return;
        }

        $top = array_key_last($this->frames);
        $stack = $this->frames[$top]['stack'];
        $parent = $stack === [] ? null : $stack[array_key_last($stack)];
        $this->frames[$top]['calls'][] = [
            'component' => $name,
            'arguments' => $content,
            'fixture' => $this->frames[$top]['fixture'],
            'position' => $stack === [] ? 'direct' : 'nested',
            'parent' => $parent,
        ];
        $this->frames[$top]['stack'][] = $name;
    }

    /**
     * Called by `Styleguide::renderNamespaced()` in a `finally` block around
     * the render call `enter()` preceded — pops the innermost frame's call
     * stack regardless of whether the render succeeded or threw, mirroring
     * `Renderer::renderInner()`'s own finally-restore pattern for
     * `$currentKind`/`$currentSlug`. No-op when unarmed, matching `enter()`.
     */
    public function exit(): void
    {
        if ($this->frames === []) {
            return;
        }

        $top = array_key_last($this->frames);
        array_pop($this->frames[$top]['stack']);
    }
}
