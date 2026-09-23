<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Twig;

use Parisek\Styleguide\Renderer;
use Parisek\Styleguide\RenderObserver;
use Parisek\Styleguide\Styleguide;
use Parisek\Styleguide\Translation\TranslationCatalog;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * @internal Wiring detail behind the bundled Twig helpers. Not part of the
 *           SemVer-covered surface.
 *
 * Holds everything the bundled helpers need that is NOT fixed at
 * construction: the render observer, the `Renderer` that is currently
 * rendering, the locale this request resolved to, and the minted-id bag.
 *
 * That split is the whole point. Until now every helper was a closure built
 * inside {@see Styleguide::registerBundledHelpers()}, capturing `$this` and
 * the environment. A closure can only be built once its state exists, which
 * forces registration to happen per request — and a Symfony Twig service may
 * already have locked its extension set by then. `Styleguide::tryAddFunction()`
 * swallows exactly that failure, so the helpers vanish without a word.
 *
 * With the mutable state here, {@see StyleguideTwigExtension} holds nothing
 * but immutable config and can be registered when the container compiles.
 * Twig resolves this class lazily through a runtime loader.
 *
 * The line is mutability, not statefulness in general: `cachebust` reads
 * `static_path` and still lives on the extension, because that value cannot
 * change after construction. Anything a request can move lives here.
 */
final class StyleguideRuntime implements RuntimeExtensionInterface
{
    /**
     * Minted ids, kept across calls for one environment lifetime.
     *
     * `bin2hex(random_bytes(3))` is 24 bits per call, so a same-render
     * collision is vanishingly unlikely — the bag is free insurance for
     * templates that mint dozens of ids (galleries, accordions) on one page.
     *
     * @var array<string, true>
     */
    private array $uniqueIds = [];

    /**
     * The `Renderer` currently rendering, or null between renders.
     *
     * Deliberately a setter rather than a constructor argument: a `Renderer`
     * is built per render and this runtime outlives it. `Renderer` sets it
     * when a render begins and clears it in the same `finally` that restores
     * its own current-fixture pointer — without that clear, a long-running
     * worker would let one request's fixture context answer the next one's
     * `styleguide_data()`.
     */
    private ?Renderer $renderer = null;

    /**
     * @param \Closure(): string|null $localeResolver
     *   Returns the locale this render is in, invoked fresh on every
     *   translator call. A resolver rather than a value because the locale is
     *   only known after the route is parsed, which happens AFTER this runtime
     *   is built — `dispatchRender()` narrows `Styleguide`'s own
     *   `$requestLocale` from `?locale=`.
     *
     *   A setter would work too, and would be wrong: it would have to be
     *   called beside every assignment to that property, and the day someone
     *   adds a fourth assignment without the pairing, the translators quietly
     *   answer in the previous locale. Reading through a resolver leaves one
     *   source of truth and nothing to keep in sync. `registerBundledExtensions()`
     *   already hands `TypographyExtension` its locale the same way.
     */
    public function __construct(
        private readonly RenderObserver $observer,
        private readonly ?TranslationCatalog $catalog = null,
        private readonly ?\Closure $localeResolver = null,
    ) {}

    public function setRenderer(?Renderer $renderer): void
    {
        $this->renderer = $renderer;
    }

    private function locale(): string
    {
        return $this->localeResolver === null ? 'en_US' : ($this->localeResolver)();
    }

    public function observer(): RenderObserver
    {
        return $this->observer;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $content
     */
    public function renderComponent(
        Environment $env,
        array $context,
        string $template_name,
        array $content = [],
    ): string {
        return Styleguide::renderNamespaced(
            $env,
            $context,
            '@component',
            $template_name,
            $content,
            'Component',
            $this->observer,
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $content
     */
    public function renderPage(
        Environment $env,
        array $context,
        string $template_name,
        array $content = [],
    ): string {
        return Styleguide::renderNamespaced(
            $env,
            $context,
            '@page',
            $template_name,
            $content,
            'Page',
            $this->observer,
        );
    }

    /**
     * Resolves the sidecar of whichever fixture is rendering right now.
     *
     * Forwards to the active `Renderer` so the "currently rendering"
     * directory is read at CALL time, from inside the template — the seam
     * that lets a no-arg `styleguide_data()` in any fixture find its own
     * sidecar without a fresh Twig function per render.
     *
     * @return array<string, mixed>
     */
    public function styleguideData(?string $ref = null): array
    {
        if ($this->renderer === null) {
            throw new \RuntimeException(
                'styleguide_data(): no active render context. The function is only '
                . 'callable from inside a fixture being rendered by Renderer.',
            );
        }

        return $this->renderer->resolveStyleguideData($ref);
    }

    public function translate(string $text, string $domain = 'default'): string
    {
        return $this->catalog?->lookup($this->locale(), $text) ?? $text;
    }

    public function translateWithContext(string $text, string $context = '', string $domain = 'default'): string
    {
        return $this->catalog?->lookup($this->locale(), $text, $context) ?? $text;
    }

    public function translatePlural(
        string $single,
        string $plural,
        int $number = 1,
        string $domain = 'default',
    ): string {
        return $this->catalog?->lookupPlural($this->locale(), $single, $plural, $number)
            ?? ($number === 1 ? $single : $plural);
    }

    public function translatePluralWithContext(
        string $single,
        string $plural,
        int $number,
        string $context = '',
        string $domain = 'default',
    ): string {
        $resolved = $this->catalog?->lookupPlural($this->locale(), $single, $plural, $number, $context)
            ?? ($number === 1 ? $single : $plural);

        return sprintf($resolved, $number);
    }

    /**
     * Typography-aware translation aliases (`…t` = "translate + typography").
     *
     * These resolve `__`/`_x`/`_n`/`_nx` and the `|typography` filter from the
     * environment at CALL time rather than calling this class's own methods
     * directly. That is deliberate and load-bearing: a WordPress consumer
     * registers the real `__()` before `Styleguide` is constructed, and a
     * project may register a tuned `TypographyExtension`. Going through the
     * environment lets both compose in automatically; calling
     * `$this->translate()` here would silently bypass the host's translator
     * and quietly ship untranslated copy.
     */
    public function translateTypography(Environment $env, string $text, string $domain = 'default'): string
    {
        return $this->typography($env, Styleguide::invokeTwigFunction($env, '__', [$text, $domain], $text));
    }

    public function translateWithContextTypography(
        Environment $env,
        string $text,
        string $context,
        string $domain = 'default',
    ): string {
        return $this->typography(
            $env,
            Styleguide::invokeTwigFunction($env, '_x', [$text, $context, $domain], $text),
        );
    }

    public function translatePluralTypography(
        Environment $env,
        string $single,
        string $plural,
        int $number,
        string $domain = 'default',
    ): string {
        return $this->typography($env, Styleguide::invokeTwigFunction(
            $env,
            '_n',
            [$single, $plural, $number, $domain],
            $number === 1 ? $single : $plural,
        ));
    }

    public function translatePluralWithContextTypography(
        Environment $env,
        string $single,
        string $plural,
        int $number,
        string $context,
        string $domain = 'default',
    ): string {
        return $this->typography($env, Styleguide::invokeTwigFunction(
            $env,
            '_nx',
            [$single, $plural, $number, $context, $domain],
            sprintf($number === 1 ? $single : $plural, $number),
        ));
    }

    /**
     * Mints an HTML-id-safe token.
     *
     * Letter prefix because HTML4 forbade ids starting with a digit, and a
     * CSS selector like `#1foo` still needs escaping — a letter front keeps
     * the result drop-in for both.
     */
    public function uniqueId(): string
    {
        do {
            $id = chr(random_int(97, 122)) . bin2hex(random_bytes(3));
        } while (isset($this->uniqueIds[$id]));

        $this->uniqueIds[$id] = true;

        return $id;
    }

    private function typography(Environment $env, string $value): string
    {
        $callable = $env->getFilter('typography')?->getCallable();

        return is_callable($callable) ? (string) $callable($value) : $value;
    }
}
