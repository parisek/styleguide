<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Twig;

use Parisek\Styleguide\Styleguide;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @internal Not part of the SemVer-covered surface yet. It becomes public when
 *           the Symfony bundle ships and has something to point consumers at.
 *
 * Every bundled Twig helper, declared once.
 *
 * This class holds NO mutable state — that is the whole reason it exists.
 * Anything a request can move (the render observer, the active `Renderer`, the
 * resolved locale, the minted-id bag) lives in {@see StyleguideRuntime}, which
 * Twig resolves lazily through a runtime loader. What stays here is config
 * fixed at construction, such as `static_path` for `|cachebust`.
 *
 * Because of that split this class can be registered when a Symfony container
 * COMPILES, tagged `twig.extension`, instead of being mutated onto an
 * environment per request. Request-time mutation is what
 * {@see Styleguide::tryAddFunction()} exists to survive, and it survives it by
 * swallowing Twig's "extensions have already been initialized" — so on a
 * booted Symfony Twig service the helpers would silently never arrive.
 *
 * Two registration strategies, one implementation:
 *
 * - **Library mode** iterates {@see getFunctions()} / {@see getFilters()}
 *   through the tolerant per-name path, so a WordPress host's own `__()` keeps
 *   winning, exactly as before.
 * - **Bundle mode** adds the class whole, where a name collision is a loud
 *   container error — which is the correct behaviour there, and the only
 *   behavioural difference between the two.
 *
 * The bodies of the stateless helpers deliberately stay on `Styleguide` as
 * named static methods rather than moving here. `tests/BundledHelpersTest.php`
 * reaches `Styleguide::classifyAspect()` by reflection and `|resizer` depends
 * on it, so relocating them would break a test that has to keep passing
 * untouched to prove this refactor changed nothing.
 */
final class StyleguideTwigExtension extends AbstractExtension
{
    /**
     * @param array<string, mixed> $config The `Styleguide` config array. Only
     *        values fixed at construction are read; anything a request can
     *        change belongs in {@see StyleguideRuntime}.
     */
    public function __construct(private readonly array $config) {}

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            // component_* / page_* resolve `<name>` to
            // `@component/<name>/<name>.twig`, underscores rewritten to
            // hyphens. They route through the runtime because each call is
            // recorded by the RenderObserver that `renderObserved()` reads,
            // and that observer is per-Styleguide state.
            new TwigFunction(
                'component_*',
                [StyleguideRuntime::class, 'renderComponent'],
                ['needs_environment' => true, 'needs_context' => true, 'is_safe' => ['html']],
            ),
            new TwigFunction(
                'page_*',
                [StyleguideRuntime::class, 'renderPage'],
                ['needs_environment' => true, 'needs_context' => true, 'is_safe' => ['html']],
            ),

            // Translators. Not stateless: they read the locale the request
            // resolved to, which is assigned after registration once the route
            // is parsed. Signatures match the WordPress originals so templates
            // passing extra context / domain / number arguments do not trip
            // ArgumentCountError. A host that registers the real `__()` first
            // keeps it — see the class docblock.
            new TwigFunction('__', [StyleguideRuntime::class, 'translate']),
            new TwigFunction('_x', [StyleguideRuntime::class, 'translateWithContext']),
            new TwigFunction('_n', [StyleguideRuntime::class, 'translatePlural']),
            new TwigFunction('_nx', [StyleguideRuntime::class, 'translatePluralWithContext']),

            // Typography-aware aliases (`…t` = "translate + typography"): the
            // matching translator, piped through `|typography`, so long-form
            // copy gets consistent treatment without `|typography` on every
            // callsite. Opting in is a one-character template edit.
            // `is_safe: ['html']` mirrors the filter's own contract — it emits
            // markup — so the aliases do not double-escape. See
            // parisek/styleguide#21.
            new TwigFunction(
                '__t',
                [StyleguideRuntime::class, 'translateTypography'],
                ['needs_environment' => true, 'is_safe' => ['html']],
            ),
            new TwigFunction(
                '_xt',
                [StyleguideRuntime::class, 'translateWithContextTypography'],
                ['needs_environment' => true, 'is_safe' => ['html']],
            ),
            new TwigFunction(
                '_nt',
                [StyleguideRuntime::class, 'translatePluralTypography'],
                ['needs_environment' => true, 'is_safe' => ['html']],
            ),
            new TwigFunction(
                '_nxt',
                [StyleguideRuntime::class, 'translatePluralWithContextTypography'],
                ['needs_environment' => true, 'is_safe' => ['html']],
            ),

            // Stateful: keeps a collision bag across calls for one environment
            // lifetime.
            new TwigFunction('uniqueId', [StyleguideRuntime::class, 'uniqueId']),

            // Reads whichever fixture is rendering right now, so it needs the
            // active Renderer. Previously registered by Renderer itself, with
            // its own copy of the swallow-and-defer pattern.
            new TwigFunction('styleguide_data', [StyleguideRuntime::class, 'styleguideData']),

            // Stateless. `placeholder` delegates to the bundled Placeholder
            // class; projects wanting a tuned palette register their own
            // before constructing Styleguide and keep it.
            new TwigFunction('placeholder', [Styleguide::class, 'placeholder']),
            new TwigFunction('merge_resizer', [Styleguide::class, 'mergeResizer']),
        ];
    }

    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        $staticPath = (string) ($this->config['static_path'] ?? '');

        return [
            new TwigFilter('resizer', [Styleguide::class, 'resizer']),

            // Cache-buster for `iframe.css` / `iframe.js` / `iframe.fonts[]`
            // URLs. The iframe loads the consumer's entry files (typically
            // `dist/css/style.css` + `dist/js/script.js`) which are referenced
            // in `styleguide.yaml` WITHOUT a build hash — so a long HTTP
            // `Cache-Control: max-age=…` on those entry files keeps the browser
            // serving the previous build's content, which then dynamically
            // imports stale-hashed bundles → 404 → broken iframe scripts.
            //
            // Appending `?v=<file_mtime>` makes every rebuild's entry URL
            // unique — browsers re-fetch on first request after a rebuild
            // (filemtime changes), then cache aggressively until the next
            // rebuild. Zero work for the consumer; works for WordPress,
            // Drupal, and standalone layouts because the algorithm walks up
            // from `static_path` to find the docroot the URL is rooted at.
            //
            // Pass-through cases: non-string values, empty strings, external
            // http(s)://, data: / mailto: / tel:, anchor (`#…`), or any URL
            // that doesn't resolve to a real file on disk. Existing query
            // strings are preserved (the buster is appended with `&`).
            //
            // `static_path` is bound here rather than reached for at call
            // time: it cannot change after construction, which is exactly what
            // keeps this filter off the runtime.
            new TwigFilter(
                'cachebust',
                static fn(mixed $url): mixed => Styleguide::cachebust($url, $staticPath),
            ),

            new TwigFilter('format_date', [Styleguide::class, 'formatDate']),
            new TwigFilter('custom_price_format', [Styleguide::class, 'customPriceFormat']),
        ];
    }
}
