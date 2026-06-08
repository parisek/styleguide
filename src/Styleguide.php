<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Public bootstrap entry for the styleguide.
 *
 * @api This class and its public methods (`__construct`, `run`) are the only
 *      part of the PHP surface covered by SemVer. The config array shape passed
 *      to the constructor is part of the contract — see `docs/API.md` § PHP API.
 *      All other classes in `Parisek\Styleguide\*` (Router, Renderer,
 *      ComponentParser methods other than `RENDER_MODES`/`normaliseRender`,
 *      AssetServer, Placeholder, Api\*) are `@internal` — refactor without
 *      bumping major is allowed.
 *
 * Usage in project's static/index.php:
 *
 *     (new \Parisek\Styleguide\Styleguide([
 *         'templates_path'  => __DIR__ . '/templates',
 *         'static_path'     => __DIR__,
 *         'config_yaml'     => __DIR__ . '/styleguide.yaml',
 *         'default_locale'  => 'cs',
 *     ]))->run();
 *
 * `run()` inspects the request URI via {@see Router::parse()} and dispatches to:
 * - {@see AssetServer::serve()}      for /styleguide/assets/<path>
 * - {@see Renderer::render()}        for /styleguide/render/<kind>/<slug>
 * - {@see Api\*Endpoint::handle()}   for /styleguide/api/<endpoint>
 * - dist/index.html (SPA bootstrap)  for /styleguide and all view URLs
 *
 * If the request isn't a /styleguide/* URI, `run()` returns and the project's
 * own routing takes over.
 */
final class Styleguide
{
    /** @var array<string, mixed> */
    private array $config;
    /** @var array<string, mixed> */
    private array $yamlConfig;
    private Environment $twig;
    private ComponentParser $parser;
    private Renderer $renderer;
    private AssetServer $assetServer;
    private string $distRoot;

    /**
     * @param array{
     *   templates_path: string,
     *   static_path: string,
     *   config_yaml: string,
     *   default_locale?: string,
     *   base_url?: string,
     *   twig_context?: array<string,mixed>,
     *   twig?: Environment,
     *   twig_options?: array<string,mixed>,
     *   typography_config?: string|null,
     *   namespaces?: array<string,string>,
     * } $config
     *
     * If `twig` is provided, the package reuses the project's existing
     * environment — required when component templates depend on extensions /
     * functions / filters registered by the project (`component_*`, `_x()`,
     * `placeholder()`, `|resizer`, …). The package then layers its own
     * `templates/` directory and a `@project` namespace onto that environment's
     * loader, leaving everything else (filters, extensions) untouched.
     *
     * If `twig` is omitted, the package builds a pristine environment with
     * only the project's templates wired up — sufficient for unit tests and
     * for projects whose templates don't reach for extension-provided helpers.
     * The pristine env defaults to `cache: false`, `debug: true`,
     * `autoescape: false`. The first two mirror what every consumer wants
     * during local dev; the third matches the project-wide convention that
     * `|typography`, WYSIWYG content, and `|raw`-equivalent filters return
     * HTML that must NOT be re-escaped. Twig's own default of
     * `autoescape: 'html'` would mangle that markup on render, so the
     * package opts out at the env-construction layer rather than asking
     * every consumer to override it.
     *
     * Consumers that need different defaults (e.g. `cache: '/tmp/twig'` in
     * production, `autoescape: 'html'` for a project that opts back into
     * Twig's escaping) can pass `twig_options` — a map merged on top of the
     * three defaults. Only meaningful when `twig` is omitted; ignored when
     * a fully-built Environment is provided, since the package never
     * mutates options on a consumer-owned env.
     *
     * Conventional namespaces are auto-registered when the matching directory
     * exists. Under `templates_path`: `@component` (`/component`), `@macro`
     * (`/macro`), `@page` (`/page`), `@static` (root). Under `static_path`:
     * `@icons` (`/images/icons`), `@images` (`/images`). Projects no longer
     * need to call `$loader->addPath(...)` for any of these. Anything else
     * (or non-standard image roots) goes into the `namespaces` config map as
     * `<name> => <absolute path>`.
     */
    public function __construct(array $config)
    {
        foreach (['templates_path', 'static_path', 'config_yaml'] as $key) {
            if (empty($config[$key])) {
                throw new \InvalidArgumentException("Styleguide: missing required config key '{$key}'");
            }
        }

        $this->config = $config + [
            'default_locale' => 'en',
            'base_url' => '/styleguide',
            'twig_context' => [],
            'twig' => null,
            // Right-hand merged onto the package defaults inside
            // buildOwnTwig(), so a partial override (`['cache' => '...']`)
            // doesn't reset the other two flags.
            'twig_options' => [],
            // Path-only config — `typography.yml` lives in the project's
            // tree (each project has its own palette / fonts), and the
            // bundled TypographyExtension just points at it.
            'typography_config' => null,
            // Extra Twig namespaces for paths that live outside `templates_path`
            // (e.g. `images/`, `images/icons/`). Conventional subdirs of
            // `templates_path` (component/macro/page) are auto-discovered and
            // don't need to be listed here.
            'namespaces' => [],
        ];

        // Load styleguide.yaml content config (favicon, iframe.css/js/fonts, etc.)
        $this->yamlConfig = is_file($config['config_yaml'])
            ? (array) Yaml::parseFile($config['config_yaml'])
            : [];

        $this->distRoot = __DIR__ . '/../dist';

        $this->twig = $this->config['twig'] instanceof Environment
            ? $this->attachLoaders($this->config['twig'], $config['templates_path'])
            : $this->buildOwnTwig($config['templates_path']);

        $this->registerBundledExtensions($this->twig);
        $this->registerBundledHelpers($this->twig);

        $this->parser = new ComponentParser($config['templates_path']);
        $this->renderer = new Renderer($this->twig, $this->config['twig_context']);
        $this->assetServer = new AssetServer($this->distRoot);
    }

    private function buildOwnTwig(string $templatesPath): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath($templatesPath, 'project');
        $loader->addPath(__DIR__ . '/../templates');
        $this->registerConventionalNamespaces($loader, $templatesPath);

        // Right-hand merge so a consumer who only sets one key (e.g. cache)
        // keeps the other two package defaults.
        $overrides = is_array($this->config['twig_options'] ?? null) ? $this->config['twig_options'] : [];
        $options = array_merge([
            'cache' => false,
            'debug' => true,
            'autoescape' => false,
        ], $overrides);

        return new Environment($loader, $options);
    }

    /**
     * Wire the conventional namespaces onto a FilesystemLoader:
     *
     * Under `templates_path`:
     *   - `templates_path/component` → `@component`
     *   - `templates_path/macro`     → `@macro`
     *   - `templates_path/page`      → `@page`
     *   - `templates_path`           → `@static`
     *
     * Under `static_path` (project root, sibling of `templates/`):
     *   - `static_path/images/icons` → `@icons`
     *   - `static_path/images`       → `@images`
     *
     * Then any extras the project supplied via the `namespaces` config map
     * (path-by-namespace, last write wins so a project can also override one
     * of the conventional locations if its layout is exotic).
     *
     * Each entry is added only when the target directory exists AND the path
     * isn't already registered under that namespace, so a project that
     * constructs `Styleguide` twice (or pre-registered some of these on its
     * own env before passing it in) doesn't end up with duplicate paths
     * slowing down template resolution.
     */
    private function registerConventionalNamespaces(FilesystemLoader $loader, string $templatesPath): void
    {
        $staticPath = (string) ($this->config['static_path'] ?? '');

        $candidates = [
            'component' => $templatesPath . '/component',
            'macro' => $templatesPath . '/macro',
            'page' => $templatesPath . '/page',
            'doc' => $templatesPath . '/doc',
            'static' => $templatesPath,
        ];
        // `images/` and `images/icons/` live next to `templates/` on every
        // consuming project we've shipped, so detect them off `static_path`
        // and register `@icons` / `@images` without forcing each project to
        // re-declare them in the `namespaces` config. Projects with a non-
        // standard image root can still override via that map (handled below).
        if ($staticPath !== '') {
            $candidates['icons'] = $staticPath . '/images/icons';
            $candidates['images'] = $staticPath . '/images';
        }

        $extras = is_array($this->config['namespaces'] ?? null) ? $this->config['namespaces'] : [];
        foreach ($extras as $name => $path) {
            if (!is_string($name) || $name === '' || !is_string($path) || $path === '') {
                continue;
            }
            $candidates[$name] = $path;
        }

        foreach ($candidates as $namespace => $path) {
            if (!is_dir($path)) {
                continue;
            }
            if ($this->loaderHasPath($loader, $namespace, $path)) {
                continue;
            }
            $loader->addPath($path, $namespace);
        }
    }

    /**
     * True when `$path` is already registered under `$namespace` on the
     * loader. Path comparison goes through `realpath()` because
     * `FilesystemLoader::addPath()` normalises stored paths (trailing slash,
     * symlinks), so a naive string match would miss equal-but-differently-
     * spelled paths and the caller would add a duplicate.
     */
    private function loaderHasPath(FilesystemLoader $loader, string $namespace, string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }
        // FilesystemLoader::getPaths() returns [] for unknown namespaces in
        // current Twig versions, but historically threw — guard either way
        // so callers (registerConventionalNamespaces) don't need to know.
        try {
            $paths = $loader->getPaths($namespace);
        } catch (\Twig\Error\LoaderError) {
            return false;
        }
        foreach ($paths as $existing) {
            if (realpath($existing) === $real) {
                return true;
            }
        }
        return false;
    }

    /**
     * Register the Twig extensions the package's own templates depend on,
     * idempotently. `foundations.twig` uses `create_attribute()` (parisek/twig-
     * attribute) and the `|typography` filter (parisek/twig-typography); the
     * sibling intl-extra / string-extra are shipped together because
     * consumers' component templates routinely reach for them too.
     *
     * The check via `hasExtension($class)` makes this safe to call after
     * `attachLoaders()` — projects that already registered any of these
     * (e.g. tailwind-base's `static/index.php`) won't see a double-registration
     * error, and their (possibly project-tuned, e.g. TypographyExtension with
     * a settings YAML) instance wins.
     */
    private function registerBundledExtensions(Environment $twig): void
    {
        // TypographyExtension accepts an optional config-file path; when the
        // project supplied one via `typography_config`, register the extension
        // with that path instead of the default empty instance. Keep the
        // hasExtension() check so projects that pre-registered it themselves
        // win — their instance carries whatever runtime settings they tuned.
        if (
            class_exists(\Parisek\Twig\TypographyExtension::class)
            && !$twig->hasExtension(\Parisek\Twig\TypographyExtension::class)
        ) {
            $typographyConfig = $this->config['typography_config'] ?? null;
            $arg = (is_string($typographyConfig) && is_file($typographyConfig)) ? $typographyConfig : '';
            $twig->addExtension(new \Parisek\Twig\TypographyExtension($arg));
        }

        // DumpExtension needs a VarCloner instance — `new $class()` in the
        // foreach below would error. The extension enables `{{ dump(var) }}`
        // in templates; safe to register unconditionally because templates
        // with a `dump()` call leaking into production are caught by the
        // `DumpRule` twig-cs-fixer lint (see tailwind-base's lint config),
        // not by withholding the extension itself.
        if (
            class_exists(\Symfony\Bridge\Twig\Extension\DumpExtension::class)
            && class_exists(\Symfony\Component\VarDumper\Cloner\VarCloner::class)
            && !$twig->hasExtension(\Symfony\Bridge\Twig\Extension\DumpExtension::class)
        ) {
            $twig->addExtension(new \Symfony\Bridge\Twig\Extension\DumpExtension(
                new \Symfony\Component\VarDumper\Cloner\VarCloner(),
            ));
        }

        $extensions = [
            \Twig\Extra\Intl\IntlExtension::class,
            \Twig\Extra\String\StringExtension::class,
            \Parisek\Twig\AttributeExtension::class,
        ];
        foreach ($extensions as $class) {
            if (class_exists($class) && !$twig->hasExtension($class)) {
                $twig->addExtension(new $class());
            }
        }
    }

    /**
     * Register the generic Twig functions every styleguide consumer needs.
     *
     * These were previously duplicated in each consuming project's entry
     * script (one big `$twig->addFunction(...)` block per project). They
     * encode the styleguide's own conventions — `component_*` resolves to
     * `@component/<name>/<name>.twig`, `page_*` to `@page/<name>/<name>.twig`,
     * `__` / `_x` / `_n` / `_nx` are identity stubs that WordPress consumers
     * override with the real translation functions, `merge_resizer` flattens
     * multi-source `<picture>` candidates into one indexed list, `uniqueId`
     * mints an HTML-id-safe random token for components that need ARIA wiring
     * without a caller-supplied id.
     *
     * Each helper is added only when the project hasn't already registered
     * one with the same name — projects that need a customised version
     * (e.g. real translation functions from WordPress's `__()` instead of
     * the identity stub) keep their version; the package fills the rest.
     *
     * The `component_*` / `page_*` error fallbacks log via `error_log()`
     * rather than `dump()` (Symfony VarDumper). The original
     * `tailwind-base` code used `dump()` because its env had
     * `DumpExtension` registered and `'debug' => TRUE`; calling `dump()`
     * unguarded in a package that ships to arbitrary consumers would leak
     * an HTML var-dump dump into the response on every miss, including
     * in production. `error_log()` reaches the same audit trail without
     * the side effect.
     */
    private function registerBundledHelpers(Environment $twig): void
    {
        // Important: do NOT use `$twig->getFunction(...) === null` /
        // `$twig->getFilter(...)` to gate registration. Reading either
        // initializes Twig's extension set (`ExtensionSet::initExtensions()`
        // sets `initialized = true`), after which `addFunction`/`addFilter`
        // throw `LogicException: Unable to add ... as extensions have
        // already been initialized.` Result: the "idempotent override"
        // pattern would defeat itself the moment the first check ran.
        //
        // Instead we attempt registration unconditionally via
        // {@see self::tryAdd…()} which catches the *duplicate-name* case
        // only — projects that pre-register any of these names on the
        // shared env (real WP `__()` instead of identity stub, custom
        // `placeholder`, …) keep their version because our `addFunction`
        // throws "already registered" and we swallow that path. The
        // "extensions initialized" case is a misuse signal (Styleguide
        // constructed after the env was rendered with), so we re-throw.
        self::tryAddFunction($twig, new TwigFunction(
            'component_*',
            static function (Environment $env, array $context, string $template_name, array $content = []): string {
                return self::renderNamespaced($env, $context, '@component', $template_name, $content, 'Component');
            },
            ['needs_environment' => true, 'needs_context' => true, 'is_safe' => ['html']],
        ));
        self::tryAddFunction($twig, new TwigFunction(
            'page_*',
            static function (Environment $env, array $context, string $template_name, array $content = []): string {
                return self::renderNamespaced($env, $context, '@page', $template_name, $content, 'Page');
            },
            ['needs_environment' => true, 'needs_context' => true, 'is_safe' => ['html']],
        ));

        // Identity translation stubs — WordPress consumers register the
        // real `__()` / `_x()` / `_n()` / `_nx()` BEFORE constructing
        // `Styleguide` (their pre-registration wins because our
        // `tryAddFunction` then swallows the duplicate-name exception).
        // Non-WP projects get the passthrough so component templates that
        // wrap strings in `_x()` don't need to branch on WP availability.
        // Signatures match the WP originals so templates passing extra
        // context / domain / number arguments don't trip ArgumentCountError.
        self::tryAddFunction($twig, new TwigFunction(
            '__',
            static fn(string $text, string $domain = 'default'): string => $text,
        ));
        self::tryAddFunction($twig, new TwigFunction(
            '_x',
            static fn(string $text, string $context = '', string $domain = 'default'): string => $text,
        ));
        self::tryAddFunction($twig, new TwigFunction(
            '_n',
            static fn(string $single, string $plural, int $number = 1, string $domain = 'default'): string
                => $number === 1 ? $single : $plural,
        ));
        self::tryAddFunction($twig, new TwigFunction(
            '_nx',
            static function (string $single, string $plural, int $number, string $context = '', string $domain = 'default'): string {
                return sprintf($number === 1 ? $single : $plural, $number);
            },
        ));

        // Typography-aware translation aliases (`…t` suffix = "translate +
        // typography"). Each calls the matching translator and pipes the result
        // through the bundled `|typography` filter, so long-form copy gets
        // consistent typographic treatment without `|typography` on every
        // callsite — opt-in is a one-character template edit (`_x` -> `_xt`).
        // Resolved via `getFunction()/getFilter()->getCallable()` at call time
        // so the project's real translator (WP `_x()` etc.) and project-tuned
        // typography settings compose in automatically when present; the
        // identity stubs above are the fallback otherwise. `is_safe: ['html']`
        // mirrors the `|typography` filter's own contract (it emits markup),
        // so the aliases don't double-escape. See parisek/styleguide#21.
        $typography = static function (string $value) use ($twig): string {
            $callable = $twig->getFilter('typography')?->getCallable();
            return is_callable($callable) ? (string) $callable($value) : $value;
        };
        self::tryAddFunction($twig, new TwigFunction(
            '_xt',
            static function (string $text, string $context, string $domain = 'default') use ($twig, $typography): string {
                return $typography(self::invokeTwigFunction($twig, '_x', [$text, $context, $domain], $text));
            },
            ['is_safe' => ['html']],
        ));
        self::tryAddFunction($twig, new TwigFunction(
            '__t',
            static function (string $text, string $domain = 'default') use ($twig, $typography): string {
                return $typography(self::invokeTwigFunction($twig, '__', [$text, $domain], $text));
            },
            ['is_safe' => ['html']],
        ));
        self::tryAddFunction($twig, new TwigFunction(
            '_nt',
            static function (string $single, string $plural, int $number, string $domain = 'default') use ($twig, $typography): string {
                return $typography(self::invokeTwigFunction($twig, '_n', [$single, $plural, $number, $domain], $number === 1 ? $single : $plural));
            },
            ['is_safe' => ['html']],
        ));
        self::tryAddFunction($twig, new TwigFunction(
            '_nxt',
            static function (string $single, string $plural, int $number, string $context, string $domain = 'default') use ($twig, $typography): string {
                return $typography(self::invokeTwigFunction($twig, '_nx', [$single, $plural, $number, $context, $domain], sprintf($number === 1 ? $single : $plural, $number)));
            },
            ['is_safe' => ['html']],
        ));

        // HTML id mint — letter prefix because HTML4 forbade ids starting
        // with a digit and CSS selectors like `#1foo` still need escaping,
        // so a letter front keeps the result drop-in for both. The closure
        // keeps a private collision set across calls within a single Twig
        // env lifetime — same-render duplicates are vanishingly unlikely
        // with bin2hex(random_bytes(3)) = 24 bits of entropy per call, but
        // the bag is free insurance for templates that mint dozens of ids
        // (galleries, accordions) on one page.
        $uniqueIds = [];
        self::tryAddFunction($twig, new TwigFunction(
            'uniqueId',
            static function () use (&$uniqueIds): string {
                do {
                    $id = chr(random_int(97, 122)) . bin2hex(random_bytes(3));
                } while (isset($uniqueIds[$id]));
                $uniqueIds[$id] = true;
                return $id;
            },
        ));

        self::tryAddFunction($twig, new TwigFunction(
            'merge_resizer',
            static function (mixed ...$items): array {
                // Drop nulls / non-arrays before the loop. Twig templates
                // routinely call merge_resizer(image_xl, image_md, image)
                // where some sources are unset for a given record — those
                // resolve to `null` and used to TypeError on the typed-
                // variadic signature. array_values() re-indexes so the
                // "last list contributes its fallback" semantics below
                // refer to the last *real* list, not the last positional
                // arg.
                $items = array_values(array_filter($items, 'is_array'));
                // Cache the last index once — `array_key_last()` is
                // O(1) on an array but evaluating it inside the nested
                // loop is wasted work on every image.
                $lastKey = array_key_last($items);
                $images = [];
                foreach ($items as $key => $item) {
                    foreach ($item as $image) {
                        // All but the last list contribute only their
                        // media-queried entries (variants with `media`).
                        // The last list contributes everything — its
                        // medialess fallback becomes the `<img>` baseline.
                        if ($key !== $lastKey) {
                            if (isset($image['media'])) {
                                $images[] = $image;
                            }
                        } else {
                            $images[] = $image;
                        }
                    }
                }
                return $images;
            },
        ));

        // `placeholder` + `|resizer` ride together — both delegate to the
        // bundled {@see Placeholder} class (lazy-loaded via the standard
        // PSR-4 autoloader on first use). Projects that need a tuned
        // palette / subject set register their own `placeholder` Twig
        // function on the env before constructing `Styleguide`; the
        // tryAddFunction below swallows the duplicate-name throw, so
        // the project's version stays.
        self::tryAddFunction($twig, new TwigFunction(
            'placeholder',
            static fn(array $opts = []): array => Placeholder::generate($opts),
        ));
        self::tryAddFilter($twig, new TwigFilter(
            'resizer',
            static function (mixed $value, mixed ...$sizes): mixed {
                $first = $sizes[0] ?? null;
                $isOrientationMap = count($sizes) === 1
                    && is_array($first)
                    && (
                        array_key_exists('landscape', $first)
                        || array_key_exists('portrait', $first)
                        || array_key_exists('square', $first)
                    );
                if ($isOrientationMap) {
                    if (!is_array($value)) {
                        return $value;
                    }
                    $bucket = self::classifyAspect($value);
                    // `landscape` is the documented fallback when the
                    // matched bucket is empty / absent. Null-coalescing
                    // alone isn't enough — it only kicks in on `null` /
                    // absent keys, but `square => []` would short-circuit
                    // to `[]` and skip the landscape fallback. Treat
                    // empty-or-non-array as "no tuples in this bucket"
                    // before deciding whether to fall through.
                    $matched = $first[$bucket] ?? null;
                    $tuples = (is_array($matched) && !empty($matched))
                        ? $matched
                        : ($first['landscape'] ?? null);
                    if (!is_array($tuples) || empty($tuples)) {
                        return $value;
                    }
                    return self::resizerFilter($value, ...$tuples);
                }
                // Tuples mode — historical behaviour.
                return self::resizerFilter($value, ...$sizes);
            },
        ));

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
        $staticPath = (string) ($this->config['static_path'] ?? '');
        self::tryAddFilter($twig, new TwigFilter(
            'cachebust',
            static function (mixed $url) use ($staticPath): mixed {
                if (!is_string($url) || $url === '' || !str_starts_with($url, '/')) {
                    return $url;
                }
                $relativeUrl = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
                $dir = $staticPath;
                // Walk up max 6 levels — covers `wp-content/themes/<theme>/static`
                // (3 hops to docroot) and `web/themes/custom/<theme>/static`
                // (3 hops). 6 leaves headroom for nested fork projects.
                for ($i = 0; $i < 6 && $dir !== '' && $dir !== '/' && $dir !== '.'; $i++) {
                    $candidate = $dir . '/' . $relativeUrl;
                    if (is_file($candidate)) {
                        $mtime = @filemtime($candidate);
                        if ($mtime !== false) {
                            $sep = str_contains($url, '?') ? '&' : '?';
                            return $url . $sep . 'v=' . $mtime;
                        }
                    }
                    $dir = dirname($dir);
                }
                return $url;
            },
        ));

        self::tryAddFilter($twig, new TwigFilter(
            'format_date',
            /**
             * Locale-light date formatter. Default output is the project's
             * canonical "j. n. Y" (Czech short-date) layout; pass
             * `'custom'` with a `format` to emit any PHP date() pattern.
             * Accepts integer timestamps, numeric strings, or ISO/RFC
             * strings that strtotime() can parse.
             */
            static function (int|string $timestamp, string $type = 'medium', string $format = ''): string {
                if (is_string($timestamp) && !is_numeric($timestamp)) {
                    // strtotime() returns false on parse failure; casting
                    // false to int yields 0 → "1. 1. 1970", which is a
                    // misleading "successful" output. Return the original
                    // string so the caller can see what didn't parse.
                    $parsed = strtotime($timestamp);
                    if ($parsed === false) {
                        return $timestamp;
                    }
                    $timestamp = $parsed;
                } else {
                    $timestamp = (int) $timestamp;
                }
                if ($type === 'custom' && $format !== '') {
                    return date($format, $timestamp);
                }
                return date('j. n. Y', $timestamp);
            },
        ));

        self::tryAddFilter($twig, new TwigFilter(
            'custom_price_format',
            /**
             * Formats a `{ number, currency_code }` shape into the
             * project's canonical price string. CZK: `1 234 Kč`
             * (integer, narrow-space group, suffix). EUR: `€ 1 234,56`
             * (prefix, comma decimal, narrow-space group). Any other
             * currency falls through to the raw number — the project's
             * Twig template should never see an unknown currency, but a
             * passthrough is safer than throwing inside a filter.
             */
            static function (mixed $value): mixed {
                if (!is_array($value) || !isset($value['number'], $value['currency_code'])) {
                    return $value;
                }
                return match ($value['currency_code']) {
                    'CZK' => number_format((float) $value['number'], 0, ',', ' ') . ' Kč',
                    'EUR' => '€ ' . number_format((float) $value['number'], 2, ',', ' '),
                    default => $value['number'],
                };
            },
        ));
    }

    /**
     * Register a Twig function, swallowing the "already registered"
     * LogicException so projects that pre-registered the same name keep
     * their version. See {@see registerBundledHelpers()} for the full
     * rationale (avoiding `getFunction()` which triggers extension
     * initialization and locks further `addFunction()` calls).
     */
    private static function tryAddFunction(Environment $twig, TwigFunction $function): void
    {
        try {
            $twig->addFunction($function);
        } catch (\LogicException $e) {
            if (!str_contains($e->getMessage(), 'already registered')) {
                throw $e;
            }
        }
    }

    /**
     * Sibling of {@see tryAddFunction()} for filters.
     */
    private static function tryAddFilter(Environment $twig, TwigFilter $filter): void
    {
        try {
            $twig->addFilter($filter);
        } catch (\LogicException $e) {
            if (!str_contains($e->getMessage(), 'already registered')) {
                throw $e;
            }
        }
    }

    /**
     * Invoke a registered Twig function by name, returning $fallback when the
     * function is absent or its callable can't be resolved.
     *
     * The translation stubs (`__`/`_x`/`_n`/`_nx`) are registered just before
     * the typography aliases call this, so the fallback is unreachable at
     * runtime — it exists because Twig's `getFunction()` and `getCallable()`
     * are both nullable in the type signature, and the project bans
     * ignore-annotations / `assert()` for narrowing.
     *
     * @param list<mixed> $args
     */
    private static function invokeTwigFunction(Environment $twig, string $name, array $args, string $fallback): string
    {
        $callable = $twig->getFunction($name)?->getCallable();

        return is_callable($callable) ? (string) $callable(...$args) : $fallback;
    }

    /**
     * Classifies a placeholder/CMS image's orientation by `width / height`
     * ratio with a default ±0.1 tolerance band around 1:1. Public surface
     * is the orientation-mode shape of the `|resizer` Twig filter — this
     * helper stays private because consumers needing the bucket outside
     * Twig should call the upstream `Resizer::classifyAspect()` on the
     * WordPress runtime, where the same method is intentionally exposed.
     *
     * Missing-metadata / non-numeric / zero-dimension sources fall back to
     * `landscape` so legacy assets (pre-ACF imports, SVG without intrinsic
     * dimensions) keep the kit's historical wide-crop default. Components
     * adopting the new filter don't silently shift their rendering for
     * legacy assets.
     * @param array<string, mixed> $image
     */
    private static function classifyAspect(array $image, float $tolerance = 0.1): string
    {
        $first = $image[0] ?? null;
        if (!is_array($first)) {
            return 'landscape';
        }
        $rawW = $first['width'] ?? null;
        $rawH = $first['height'] ?? null;
        $w = is_numeric($rawW) ? (float) $rawW : 0.0;
        $h = is_numeric($rawH) ? (float) $rawH : 0.0;
        if ($w <= 0 || $h <= 0) {
            return 'landscape';
        }
        // Cross-multiplication form of `abs($w/$h - 1) <= $tolerance`. Stays
        // exact at the band boundary for integer dimensions (both CMS imports
        // and our placeholder() output always are): IEEE 754 represents
        // 1100/1000 as 1.1 + 8.88e-17, which would trip the naïve `<=` to
        // false at the exact 10 % edge. Cross-multiplying keeps the comparison
        // in integer/scaled-float arithmetic and makes the boundary inclusive
        // as the spec promises.
        if (abs($w - $h) <= $tolerance * $h) {
            return 'square';
        }
        return $w > $h ? 'landscape' : 'portrait';
    }

    /**
     * `<picture>` source-set builder. Reads `_placeholderOpts` from the input
     * (set only by `placeholder()`) — without it the filter passes images
     * through untouched, so real CMS-rendered images aren't accidentally
     * regenerated as SVG fakes. Each requested size becomes one `<source>`
     * with a `min-width` media query, except the last which is the bare
     * fallback consumed as `<img>`.
     */
    private static function resizerFilter(mixed $value, mixed ...$sizes): mixed
    {
        if (!is_array($value) || empty($value) || empty($sizes)) {
            return $value;
        }
        // Guard against CMS image shapes that arrive as associative arrays
        // (`{src: …, alt: …}` directly, not wrapped in a list). Without this
        // the `_placeholderOpts` lookup would throw on undefined offset.
        // Real placeholder() output is always a list with at least `[0]` set.
        if (!isset($value[0]) || !is_array($value[0])) {
            return $value;
        }
        $base = $value[0];
        $baseOpts = $base['_placeholderOpts'] ?? null;
        if (!$baseOpts) {
            return $value;
        }
        $baseW = (float) ($base['width'] ?? 0);
        $baseH = (float) ($base['height'] ?? 0);
        if ($baseW <= 0 || $baseH <= 0) {
            return $value;
        }
        $aspect = $baseW / $baseH;

        // Pre-filter the requested sizes to valid (positive, numeric) tuples
        // so the cascade below can trust `count - 1` to mark the fallback.
        $valid = [];
        foreach ($sizes as $size) {
            // The third tuple element is the viewport min-width at which
            // this <source> becomes the chosen variant (passed through to
            // `(min-width: Npx)` in the media query below). The historical
            // tailwind-base API names this tuple slot `maxW` — preserved
            // here only at the public boundary; internally we read it as
            // `$minW` to match the `(min-width: ...)` semantics.
            [$w, $h, $minW] = array_pad((array) $size, 3, '');
            $w = is_numeric($w) ? (int) $w : null;
            $h = is_numeric($h) ? (int) $h : null;
            if (($w === null || $w <= 0) && ($h === null || $h <= 0)) {
                continue;
            }
            if ($h === null || $h <= 0) {
                $h = (int) round($w / $aspect);
            }
            if ($w === null || $w <= 0) {
                $w = (int) round($h * $aspect);
            }
            $valid[] = [$w, $h, $minW];
        }
        if (!$valid) {
            return $value;
        }

        $lastIdx = count($valid) - 1;
        $entries = [];
        foreach ($valid as $i => [$w, $h, $minW]) {
            // Re-call placeholder() at the variant's dimensions so each
            // <source> renders an SVG sized exactly to the breakpoint —
            // makes it trivial to see which source the browser chose.
            $opts = $baseOpts;
            $opts['width'] = $w;
            $opts['height'] = $h;
            $opts['label'] = true;
            unset($opts['aspect']);
            $variant = Placeholder::generate($opts)[0];
            unset($variant['_placeholderOpts']);
            if ($minW !== '' && is_numeric($minW) && $i < $lastIdx) {
                $variant['media'] = sprintf('(min-width: %dpx)', (int) $minW);
            }
            $entries[] = $variant;
        }
        return $entries;
    }

    /**
     * Shared render path for `component_*` / `page_*`. Resolves
     * `<namespace>/<name>/<name>.twig`; on miss falls back to the project's
     * `@component/alert/alert.twig` with an error message; if that also
     * misses, returns a bare inline error. `_` in the function name is
     * normalised to `-` to match the directory convention
     * (`component_header_menu` → `@component/header-menu/header-menu.twig`).
     * @param array<string, mixed> $content
     * @param array<string, mixed> $context
     */
    private static function renderNamespaced(
        Environment $env,
        array $context,
        string $namespace,
        string $template_name,
        array $content,
        string $kindLabel,
    ): string {
        $normalised = str_replace('_', '-', $template_name);
        try {
            $template = $env->load("$namespace/$normalised/$normalised.twig");
            return $template->render(array_merge($context, ['content' => $content]));
        } catch (\Throwable $e) {
            error_log(sprintf('[parisek/styleguide] %s template "%s" missing: %s', $kindLabel, $normalised, $e->getMessage()));
            try {
                $alert = $env->load('@component/alert/alert.twig');
                return $alert->render(array_merge($context, [
                    'content' => [
                        'type' => 'error',
                        'container' => 'container',
                        'message' => sprintf('%s template <strong>%s.twig</strong> not found', $kindLabel, $normalised),
                    ],
                ]));
            } catch (\Throwable $inner) {
                error_log(sprintf('[parisek/styleguide] alert fallback also failed: %s', $inner->getMessage()));
                return sprintf(
                    '<div>%s template <strong>%s.twig</strong> not found</div>',
                    htmlspecialchars($kindLabel, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($normalised, ENT_QUOTES, 'UTF-8'),
                );
            }
        }
    }

    /**
     * Layer the package's template paths onto an existing Twig environment.
     *
     * The project's existing loader keeps every namespace it already owns
     * (`@component`, `@page`, `@styleguide`, …); the package adds `@project`
     * (for Renderer template lookup) and a non-namespaced path so
     * `render-cell.twig` / `styleguide-404.twig` resolve.
     *
     * If the existing loader is already a `FilesystemLoader`, we mutate it in
     * place. Otherwise (rare — Twig\Loader\ArrayLoader in tests, ChainLoader in
     * exotic setups) we wrap it in a `ChainLoader` with a fresh
     * `FilesystemLoader` carrying the package's paths.
     */
    private function attachLoaders(Environment $twig, string $templatesPath): Environment
    {
        $existing = $twig->getLoader();
        $packagePath = __DIR__ . '/../templates';

        if ($existing instanceof FilesystemLoader) {
            if (!in_array('project', $existing->getNamespaces(), true)) {
                $existing->addPath($templatesPath, 'project');
            }
            if (!in_array($packagePath, $existing->getPaths(), true)) {
                $existing->addPath($packagePath);
            }
            $this->registerConventionalNamespaces($existing, $templatesPath);
            return $twig;
        }

        $packageLoader = new FilesystemLoader();
        $packageLoader->addPath($templatesPath, 'project');
        $packageLoader->addPath($packagePath);
        $this->registerConventionalNamespaces($packageLoader, $templatesPath);
        $twig->setLoader(new ChainLoader([$existing, $packageLoader]));
        return $twig;
    }

    /**
     * Dispatch the current request to the appropriate handler.
     *
     * Returns silently when the URI doesn't belong to /styleguide/* — the project's
     * own router continues to handle the request.
     */
    public function run(): void
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $route = Router::parse($uri);

        if ($route === null) {
            return;
        }

        // Iframe-embedded request → render endpoint (no SPA shell). See
        // {@see Router::synthesizeEmbeddedRoute()} for the rationale + decision
        // table. Centralising the swap there keeps the dispatch here simple
        // and lets the synthesis logic be tested in isolation.
        $route = Router::synthesizeEmbeddedRoute($route, (string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));

        match ($route['type']) {
            'asset' => $this->assetServer->serve($route['path'] ?? ''),
            'render' => $this->dispatchRender($route),
            'api' => $this->dispatchApi($route),
            default => $this->dispatchSpa($route),
        };

        // After dispatching a styleguide route, halt the project's downstream router.
        exit;
    }

    /**
     * @param array<string, mixed> $route
     */
    private function dispatchSpa(array $route): void
    {
        $indexPath = $this->distRoot . '/index.html';
        if (!is_file($indexPath)) {
            http_response_code(500);
            echo "Styleguide build missing — run 'npm run build' in vendor/parisek/styleguide/frontend/";
            return;
        }

        $html = (string) file_get_contents($indexPath);
        $locale = (string) $this->config['default_locale'];
        $project = (array) ($this->yamlConfig['project'] ?? []);
        $projectName = (string) ($project['name'] ?? 'Styleguide');
        $favicon = (string) ($project['favicon'] ?? '');

        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        // <html lang="..." data-default-locale="...">
        $html = (string) preg_replace(
            '/<html\s+lang="[^"]*"(?:\s+data-default-locale="[^"]*")?\s*>/',
            sprintf('<html lang="%s" data-default-locale="%s">', $esc($locale), $esc($locale)),
            $html,
            1,
        );

        // Favicon — <link rel="icon"> for the browser tab + the sidebar header
        // <img id="sg-favicon"> rendered next to the project name. Both ship with
        // empty `href`/`src` in the static `dist/index.html` so PHP can fill them
        // in per-project at request time without re-running the SPA build.
        if ($favicon !== '') {
            $html = (string) preg_replace(
                '/<link\s+rel="icon"\s+id="sg-favicon-tag"\s+href="[^"]*">/',
                '<link rel="icon" id="sg-favicon-tag" href="' . $esc($favicon) . '">',
                $html,
                1,
            );
            $html = (string) preg_replace(
                '/<img\s+src="[^"]*"\s+alt="[^"]*"\s+class="([^"]*)"\s+id="sg-favicon">/',
                '<img src="' . $esc($favicon) . '" alt="" class="$1" id="sg-favicon">',
                $html,
                1,
            );
        }

        // <body data-project-name="..." data-project-favicon="...">
        $html = (string) preg_replace(
            '/data-project-name="[^"]*"/',
            'data-project-name="' . $esc($projectName) . '"',
            $html,
            1,
        );
        $html = (string) preg_replace(
            '/data-project-favicon="[^"]*"/',
            'data-project-favicon="' . $esc($favicon) . '"',
            $html,
            1,
        );

        // Sidebar header — <div id="sg-project-name">…</div> ships with "Styleguide"
        // as the static placeholder. Same per-project request-time substitution
        // pattern as the favicon nodes, so the bundled SPA doesn't have to know
        // the project name at build time. Uses a callback so an escaped string
        // containing `$1` style sequences can't accidentally interpolate as a
        // back-reference.
        $escapedName = $esc($projectName);
        $html = (string) preg_replace_callback(
            '/(<[^>]+id="sg-project-name"[^>]*>)[^<]*(<\/[^>]+>)/',
            static fn(array $m): string => $m[1] . $escapedName . $m[2],
            $html,
            1,
        );

        // <title>
        $html = (string) preg_replace(
            '/<title>[^<]*<\/title>/',
            '<title>Styleguide — ' . $esc($projectName) . '</title>',
            $html,
            1,
        );

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        echo $html;
    }

    /**
     * @param array<string, mixed> $route
     */
    private function dispatchRender(array $route): void
    {
        $config = [
            'project' => $this->yamlConfig['project'] ?? [],
            'iframe' => $this->yamlConfig['iframe'] ?? [],
            // The foundations body reads from `styleguide.colors`, `styleguide.logo`,
            // `styleguide.typography`, `styleguide.labels` — surface the whole yaml
            // map so component/page templates that look up styleguide.* also work.
            'styleguide' => $this->yamlConfig,
        ];
        $langcode = substr((string) $this->config['default_locale'], 0, 2) ?: 'en';

        if (in_array($route['kind'], ['component', 'page', 'doc'], true)) {
            // Resolve human-readable component name from parsed metadata, if available.
            $meta = $this->parser->parse($route['kind'], $route['slug']);
            if ($meta !== null && !empty($meta['name'])) {
                $config['component_name'] = $meta['name'];
            }
            // Render mode is forwarded only for components — pages and docs
            // render their own full layout and don't go through render-cell's
            // inset wrapper.
            if ($meta !== null && $route['kind'] === 'component') {
                $config['render'] = $meta['render'] ?? 'inset';
            }
        } elseif ($route['kind'] === 'foundations') {
            $config['component_name'] = (string) ($this->yamlConfig['project']['name'] ?? 'Foundations');
            // foundations.twig uses Tailwind utility classes the consumer's
            // own `iframe.css` doesn't generate (the consumer scans only its
            // own templates/, not vendor/). The package ships a dedicated
            // dist/foundations.[hash].css built from frontend/foundations.css
            // that scans the foundations template, and render-cell.twig
            // links it alongside iframe.css for foundations renders.
            $cssUrl = $this->resolveFoundationsCssUrl();
            if ($cssUrl !== null) {
                $config['foundations_css_url'] = $cssUrl;
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderer->render(
            kind: $route['kind'],
            slug: $route['slug'],
            config: $config,
            langcode: $langcode,
        );
    }

    /**
     * Locate the hashed dist/foundations.*.css file produced by the package's
     * Vite build. Returns the public URL under /styleguide/assets/, or null
     * when the bundle is missing (e.g. consumer hasn't run npm install/build
     * after pulling a package version that introduced this file).
     */
    private function resolveFoundationsCssUrl(): ?string
    {
        $matches = glob($this->distRoot . '/foundations.*.css');
        if ($matches === false || count($matches) === 0) {
            return null;
        }
        return '/styleguide/assets/' . basename($matches[0]);
    }

    /**
     * @param array<string, mixed> $route
     */
    private function dispatchApi(array $route): void
    {
        $endpoint = match ($route['endpoint']) {
            'components' => new Api\ComponentsEndpoint($this->parser),
            'docs' => new Api\DocsEndpoint($this->parser),
            'fields' => new Api\FieldsEndpoint($this->parser),
            'pages' => new Api\PagesEndpoint($this->parser),
            default => null,
        };

        if ($endpoint === null) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Unknown API endpoint: ' . $route['endpoint']]);
            return;
        }

        $endpoint->handle();
    }
}
