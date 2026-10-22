<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Twig;

use Parisek\Styleguide\Renderer;
use Parisek\Styleguide\RenderObserver;
use Parisek\Styleguide\Styleguide;
use Parisek\Styleguide\Translation\TranslationCatalog;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;
use Twig\Template;

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
     * Minted ids, kept for the duration of one render.
     *
     * `bin2hex(random_bytes(3))` is 24 bits per call, so a same-render
     * collision is vanishingly unlikely — the bag is free insurance for
     * templates that mint dozens of ids (galleries, accordions) on one page.
     *
     * Cleared when the outermost render finishes ({@see popRenderer()}),
     * because uniqueness is only ever needed within one document. Nested
     * renders share the bag, which is right: they produce one document.
     *
     * @var array<string, true>
     */
    private array $uniqueIds = [];

    /**
     * The stack of `Renderer`s currently rendering, innermost last.
     *
     * A stack rather than a single slot, which a review caught. Renders NEST —
     * `renderObserved()` is re-entrant, and two `Styleguide` objects sharing
     * one environment share this runtime while owning different `Renderer`s.
     * With one slot, an inner render by a DIFFERENT `Renderer` overwrote the
     * outer one and then cleared it on the way out, so the outer template's
     * next `styleguide_data()` found no active context. A stack cannot get
     * that wrong: whoever finishes pops only themselves.
     *
     * Empty between renders. `Renderer` pushes as a render begins and pops in
     * the same `finally` that restores its own fixture pointers, so a
     * long-running worker never lets one request's context answer the next
     * one's.
     *
     * @var list<Renderer>
     */
    private array $renderers = [];

    /**
     * Null-content warnings already logged, keyed by message.
     *
     * @var array<string, true>
     */
    private array $reportedNullContent = [];

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
     *
     * @param string $translationFingerprint
     *   The translation configuration this runtime answers for, compared by
     *   {@see answersTo()} when another `Styleguide` on the same environment
     *   considers adopting it. A value rather than the objects, because the
     *   objects are rebuilt per construction.
     */
    public function __construct(
        private readonly RenderObserver $observer,
        private readonly ?TranslationCatalog $catalog = null,
        private readonly ?\Closure $localeResolver = null,
        private readonly string $translationFingerprint = '',
    ) {}

    public function pushRenderer(Renderer $renderer): void
    {
        $this->renderers[] = $renderer;
    }

    public function popRenderer(): void
    {
        array_pop($this->renderers);

        // The outermost render just finished, so the minted ids have served
        // their purpose: they only ever needed to be unique within ONE
        // rendered document. Keeping them was harmless while run() ended the
        // process after a single request; a reusable Styleguide behind
        // handle() would have grown this array for the life of the worker,
        // once per uniqueId() call, forever. Found by review.
        if ($this->renderers === []) {
            $this->uniqueIds = [];
        }
    }

    private function locale(): string
    {
        return $this->localeResolver === null ? 'en_US' : ($this->localeResolver)();
    }

    /**
     * Would this runtime translate the same way as the given configuration?
     *
     * Asked by {@see Styleguide} before adopting a runtime an earlier
     * construction left on a shared environment. The catalogue and the locale
     * live here, so adopting silently hands this object the earlier one's
     * translations — fine when the two configs match, which is the only reason
     * anyone constructs twice, and wrong without a word when they do not.
     *
     * Compares a fingerprint of the CONFIGURATION, not the objects. A review
     * caught the first attempt comparing `TranslationCatalog` instances by
     * identity: every construction builds a new one, so two objects configured
     * identically never matched and the supported path was refused along with
     * the unsupported one.
     */
    public function answersTo(string $fingerprint): bool
    {
        return $this->translationFingerprint === $fingerprint;
    }

    public function observer(): RenderObserver
    {
        return $this->observer;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $content
     */
    public function renderComponent(
        Environment $env,
        array $context,
        string $template_name,
        ?array $content = [],
    ): string {
        if ($content === null) {
            $this->reportNullContent('component', $template_name);
            $content = [];
        }

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
     * @param array<string, mixed>|null $content
     */
    public function renderPage(
        Environment $env,
        array $context,
        string $template_name,
        ?array $content = [],
    ): string {
        if ($content === null) {
            $this->reportNullContent('page', $template_name);
            $content = [];
        }

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
     * Logs a `component_*()` / `page_*()` call that received `null` content.
     *
     * `null` is accepted and rendered as `[]`, the way Drupal's untyped
     * `$content = []` accepts it: an optional sub-object passed straight
     * through (`component_pagination(content.pagination)`) is a normal state
     * on the live site. The same `null` can also be a forgotten fixture key,
     * and the two look identical from here. So the call renders, and the log
     * names the caller. A scalar still throws a `TypeError` at the signature.
     *
     * The caller is read off the PHP backtrace: Twig compiles the call into
     * the calling template's `doDisplay()`, and `getDebugInfo()` maps the
     * compiled line back to the template line. `Template` is internal to Twig,
     * so a change there degrades the message (no line) and never breaks the
     * render. `error_log()` rather than a
     * throw, like the other render diagnostics.
     */
    private function reportNullContent(string $prefix, string $templateName): void
    {
        $message = sprintf(
            '[parisek/styleguide] %s_%s() called with null content in %s; rendered as [].',
            $prefix,
            $templateName,
            self::callerLocation(),
        );

        // Once per call site and runtime: a loop over twelve teasers that each
        // pass an optional sub-object would otherwise write twelve identical
        // lines on every render.
        if (isset($this->reportedNullContent[$message])) {
            return;
        }
        $this->reportedNullContent[$message] = true;

        error_log($message);
    }

    private static function callerLocation(): string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 12);
        foreach ($frames as $i => $frame) {
            $object = $frame['object'] ?? null;
            if (!$object instanceof Template) {
                continue;
            }

            // A frame's `line` is where ITS function was called from, so the
            // line inside the template's compiled file lives on the frame
            // before it (the call to the runtime method).
            $compiledLine = $i > 0 ? ($frames[$i - 1]['line'] ?? null) : null;
            $line = null;
            if ($compiledLine !== null) {
                foreach ($object->getDebugInfo() as $compiled => $twigLine) {
                    if ($compiled <= $compiledLine) {
                        $line = $twigLine;
                        break;
                    }
                }
            }

            return sprintf('"%s"%s', $object->getTemplateName(), $line === null ? '' : ' at line ' . $line);
        }

        return 'an unknown template';
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
        if ($this->renderers === []) {
            throw new \RuntimeException(
                'styleguide_data(): no active render context. The function is only '
                . 'callable from inside a fixture being rendered by Renderer.',
            );
        }

        return $this->renderers[array_key_last($this->renderers)]->resolveStyleguideData($ref);
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
