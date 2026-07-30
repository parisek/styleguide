<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Error\LoaderError;
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
     *   dist_path?: string,
     *   auth?: callable(array<string,mixed>):bool,
     * } $config
     *
     * `dist_path` is @internal for tests only (points `dispatchSpa()` at a
     * throwaway fixture dist/ instead of the package's real built one — see
     * SpaConfigTest). Not part of the `@api`-covered config shape below;
     * consumers must never set it.
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

        // `auth` gates every request (see `isAuthorized()`) — a typo'd or
        // wrong-shape value here (a string, an array missing `__invoke`, …)
        // must fail loudly at boot rather than silently falling back to
        // "allow everything" inside `isAuthorized()`'s `is_callable()` check.
        // Fail-open at request time on a misconfigured gate would be a
        // security bug masquerading as backward compatibility; failing here
        // instead surfaces it the moment the project boots, before it ever
        // serves a request.
        if (array_key_exists('auth', $config) && $config['auth'] !== null && !is_callable($config['auth'])) {
            throw new \InvalidArgumentException(
                "Styleguide: config key 'auth' must be null or callable(array<string,mixed>):bool",
            );
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
            // Optional programmatic gate — callable(array $route): bool. Checked once
            // per request in dispatch(), before ANY handler (SPA/render/api/asset).
            // Null (the default) means "allow everything", i.e. today's behaviour —
            // fully backward compatible. See README § Bootstrap → Constructor config
            // for the recommended alternative (web-server-level HTTP Basic Auth) on
            // publicly reachable deployments.
            'auth' => null,
        ];

        // Load styleguide.yaml content config (favicon, iframe.css/js/fonts, etc.)
        $this->yamlConfig = is_file($config['config_yaml'])
            ? (array) Yaml::parseFile($config['config_yaml'])
            : [];

        // `dist_path` override exists for tests only (SpaConfigTest points it at a
        // throwaway temp dir so writing a synthetic index.html fixture doesn't
        // corrupt the package's real built dist/). Consumers never set this.
        $this->distRoot = (string) ($config['dist_path'] ?? (__DIR__ . '/../dist'));

        $this->twig = $this->config['twig'] instanceof Environment
            ? $this->attachLoaders($this->config['twig'], $config['templates_path'])
            : $this->buildOwnTwig($config['templates_path']);

        $this->registerBundledExtensions($this->twig);
        $this->registerBundledHelpers($this->twig);

        $this->parser = new ComponentParser($config['templates_path']);
        $this->renderer = new Renderer($this->twig, $this->config['twig_context'], $config['templates_path']);
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
        // {@see self::tryAdd…()}, which swallows every `LogicException` Twig
        // throws from `addFunction()`/`addFilter()` — both the expected
        // *duplicate-name* case (projects that pre-register any of these
        // names on the shared env — real WP `__()` instead of identity stub,
        // custom `placeholder`, etc. — keep their version) and the
        // "extensions already initialized" case (Styleguide constructed
        // against an env that was already locked, e.g. by a prior
        // `getFunctions()` call). See {@see tryAddFunction()} for why we no
        // longer try to tell the two apart.
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
     * Register a Twig function, swallowing any `LogicException` from
     * `addFunction()`. See {@see registerBundledHelpers()} for why we can't
     * distinguish "duplicate name" from "extensions already initialized"
     * cleanly (Twig exposes both as a bare `LogicException` with only the
     * message text differing, and that text isn't a stable API — matching on
     * it to decide whether to rethrow broke once already). Swallow-and-defer:
     * never crash a consumer's boot because of a Twig internal-message
     * change; log to error_log() only when the message doesn't look like the
     * expected "already registered" collision, so the rare genuine-misuse
     * case (constructing Styleguide against an env that's already been used
     * to render) still leaves a breadcrumb for whoever's debugging it.
     */
    private static function tryAddFunction(Environment $twig, TwigFunction $function): void
    {
        try {
            $twig->addFunction($function);
        } catch (\LogicException $e) {
            self::logUnexpectedRegistrationFailure($function->getName(), $e);
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
            self::logUnexpectedRegistrationFailure($filter->getName(), $e);
        }
    }

    /**
     * Log a breadcrumb for `LogicException`s from `addFunction()`/
     * `addFilter()` that don't look like the expected "already registered"
     * collision (e.g. Twig's "extensions already initialized" case). Never
     * rethrows — see {@see tryAddFunction()} for why matching on the message
     * to decide whether to crash the consumer's boot isn't safe.
     */
    private static function logUnexpectedRegistrationFailure(string $name, \LogicException $e): void
    {
        if (!str_contains($e->getMessage(), 'already registered')) {
            error_log(sprintf(
                '[parisek/styleguide] unexpected LogicException registering "%s": %s',
                $name,
                $e->getMessage(),
            ));
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
     * `<picture>` source-set builder. Each requested size becomes one
     * `<source>` with a `min-width` media query, except the last which is
     * the bare fallback consumed as `<img>`.
     *
     * Two input kinds, one output shape (issue #70):
     *  - `placeholder()` fixtures (marked by `_placeholderOpts`) — each
     *    variant re-generates the SVG at the tuple's exact dimensions, so
     *    it's trivial to see which source the browser chose.
     *  - Real fixture images — each variant reuses the ORIGINAL `src`
     *    (the styleguide has no image pipeline and doesn't need one) but
     *    carries the tuple's declared `width`/`height`/`media`. The DOM
     *    then mirrors the multi-source markup the CMS resizer emits in
     *    production, which is what DOM-structural checks (tailwind-base
     *    `picture.contract.js`) assert against.
     *
     * Animated-GIF parity: production (timber-kit `Resizer::$skip_animated`)
     * returns animated sources untouched — re-encoding would flatten them
     * to frame 0. The styleguide can't cheaply prove animation (src is a
     * URL, not a readable path), so ANY `.gif` fixture passes through
     * whole. A static GIF fixture loses nothing real (same file either
     * way), and an animated one renders exactly like production.
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
        $baseW = (float) ($base['width'] ?? 0);
        $baseH = (float) ($base['height'] ?? 0);
        if ($baseOpts) {
            // Placeholder regeneration needs a concrete aspect to derive a
            // missing tuple axis from — placeholder() always provides both.
            if ($baseW <= 0 || $baseH <= 0) {
                return $value;
            }
        } else {
            // Real image. Animated-GIF passthrough parity — see docblock.
            $src = (string) ($base['src'] ?? '');
            $type = (string) ($base['type'] ?? '');
            if ($type === 'image/gif' || preg_match('/\.gif(\?|#|$)/i', $src) === 1) {
                return $value;
            }
        }
        // Real images without usable width/height metadata (legacy fixtures,
        // SVG) keep a null aspect: variants are then emitted with only the
        // tuple axes that were explicitly provided, never derived.
        $aspect = ($baseW > 0 && $baseH > 0) ? $baseW / $baseH : null;

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
            // Derive the missing axis from the source aspect when known;
            // with no aspect (real image without metadata) keep it null and
            // emit the variant with the provided axis only.
            if (($h === null || $h <= 0) && $aspect !== null) {
                $h = (int) round($w / $aspect);
            }
            if (($w === null || $w <= 0) && $aspect !== null) {
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
            if ($baseOpts) {
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
            } else {
                // Real image: same src for every variant, only the declared
                // dimensions differ — the browser downloads one file, the
                // DOM mirrors production's per-tuple <source> structure.
                $variant = $base;
                unset($variant['media']);
                if ($w !== null && $w > 0) {
                    $variant['width'] = $w;
                } else {
                    unset($variant['width']);
                }
                if ($h !== null && $h > 0) {
                    $variant['height'] = $h;
                } else {
                    unset($variant['height']);
                }
            }
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

        // ONLY a genuine miss falls back. A LoaderError means the file is not
        // there — the case the alert below actually describes. Every other
        // throw (SyntaxError: the template exists and does not compile;
        // RuntimeError: it compiled and blew up while rendering) propagates to
        // Renderer::render(), which turns it into HTTP 500 plus the real
        // message.
        //
        // Catching \Throwable here defeated that: a template with a fatal Twig
        // syntax error was reported as "not found", served 200, and the actual
        // parser message only reached error_log. Renderer's 500 path — added
        // precisely so "a health check or CI smoke test polling
        // /render/component/<id>" cannot see success for a broken component —
        // could never fire, because the throw was already swallowed one layer
        // below it. Downstream, eleven templates shipped broken for days with
        // every check green (portadesign/tailwind-base, 2026-07).
        //
        // Caveat worth knowing, not worth guarding: FilesystemLoader also
        // raises LoaderError for its path-traversal check, so that would report
        // as "not found" too. Unreachable from here — both interpolated tokens
        // are fixed by the caller and `$normalised` has its underscores
        // rewritten, leaving no user-controlled path segment.
        //
        // The render() call is deliberately OUTSIDE the try: a LoaderError
        // raised by a nested {% include %} while rendering is a failure of THIS
        // template, not evidence that this template is missing, and must not be
        // relabelled as one.
        try {
            $template = $env->load("$namespace/$normalised/$normalised.twig");
        } catch (\Twig\Error\SyntaxError $e) {
            // Exists but does not compile. Logged for the same reason as the
            // render failure below, then rethrown so Renderer::render() can
            // turn it into a 500 with the real parser message.
            error_log(sprintf(
                '[parisek/styleguide] %s template "%s" does not compile: %s',
                $kindLabel,
                $normalised,
                $e->getMessage(),
            ));
            throw $e;
        } catch (LoaderError $e) {
            // Genuine miss — the only case the alert fallback describes.

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

        try {
            return $template->render(array_merge($context, ['content' => $content]));
        } catch (\Throwable $e) {
            // Log, then RETHROW. The miss path above logs, and a production
            // consumer whose CMS or proxy swallows the 500 body would
            // otherwise be left with an unexplained failure and no
            // server-side trace at all — strictly less diagnosable than the
            // behaviour this change replaced, which at least logged.
            error_log(sprintf(
                '[parisek/styleguide] %s template "%s" failed to render: %s',
                $kindLabel,
                $normalised,
                $e->getMessage(),
            ));
            throw $e;
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
        $route = Router::parse($uri, $_COOKIE);

        if ($route === null) {
            return;
        }

        // Iframe-embedded request → render endpoint (no SPA shell). See
        // {@see Router::synthesizeEmbeddedRoute()} for the rationale + decision
        // table. Centralising the swap there keeps the dispatch here simple
        // and lets the synthesis logic be tested in isolation. $_COOKIE carries
        // the `sg-iframe-theme` fallback for in-iframe navigations that lost
        // the SPA's `?theme=` query param (the clicked link's href never had one).
        $route = Router::synthesizeEmbeddedRoute($route, (string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''), $_COOKIE);

        $this->dispatch($route);

        // After dispatching a styleguide route, halt the project's downstream router.
        exit;
    }

    /**
     * Extracted from `run()`'s tail so it's reachable via reflection in tests
     * without triggering the unconditional `exit` above — `run()` itself
     * can't be called in-process by PHPUnit (see SpaConfigTest's class doc
     * comment for why that suite drives a real subprocess instead).
     *
     * @param array<string, mixed> $route
     */
    private function dispatch(array $route): void
    {
        if (!$this->isAuthorized($route)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo '403 Forbidden';
            return;
        }

        match ($route['type']) {
            'asset' => $this->assetServer->serve($route['path'] ?? ''),
            'render' => $this->dispatchRender($route),
            'api' => $this->dispatchApi($route),
            default => $this->dispatchSpa($route),
        };
    }

    /**
     * Runs the `auth` config callable (if any) against the parsed route.
     * Absent `auth` (the default, `null`) means "allow everything" — fully
     * backward compatible with pre-Task-6 behaviour. A non-null, non-callable
     * value can no longer reach here — the constructor rejects it at boot.
     *
     * @param array<string, mixed> $route
     */
    private function isAuthorized(array $route): bool
    {
        $auth = $this->config['auth'] ?? null;
        if (!is_callable($auth)) {
            return true;
        }
        /** @var callable(array<string,mixed>):bool $auth */
        try {
            return (bool) $auth($route);
        } catch (\Throwable $e) {
            // Fail closed: a throwing auth callable must never leak its stack
            // trace to an unauthenticated caller. With `display_errors=On`
            // (a common misconfiguration on shared hosting) an uncaught
            // exception here would render as an HTML error page carrying
            // file paths and, depending on the callable, secrets pulled from
            // the environment it inspected before throwing — straight to
            // whoever sent the request that triggered it. Denying the
            // request and logging server-side keeps that detail off the
            // wire while still leaving a breadcrumb for whoever wrote the
            // callable to go fix it.
            error_log(sprintf(
                '[parisek/styleguide] auth callable threw %s: %s — denying request',
                $e::class,
                $e->getMessage(),
            ));
            return false;
        }
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
        // Rebase the SPA-shell favicon onto the consumer asset base (templateUrl)
        // — same resolution the iframe assets get in Renderer, so a short
        // `/images/touch/favicon.svg` resolves under the theme on WordPress /
        // Drupal instead of 404-ing at the domain root. No-op when standalone
        // (empty base) or already absolute-under-base. See Renderer::resolveAssetUrl().
        $assetBase = (string) (($this->config['twig_context']['templateUrl'] ?? '') ?: '');
        if ($favicon !== '') {
            $favicon = Renderer::resolveAssetUrl($favicon, $assetBase);
        }

        $config = [
            'locale' => $locale,
            'projectName' => $projectName,
            'favicon' => $favicon,
            'title' => sprintf('Styleguide — %s', $projectName),
            'baseUrl' => '/styleguide',
            // Gates the sidebar "Icons" entry (#87) — a yaml-shape check
            // only (not IconsCatalog::build(), which reads every icon file
            // from disk; too heavy for every SPA shell load).
            'hasIcons' => !empty($this->yamlConfig['icons']['groups']),
        ];
        $configJson = json_encode(
            $config,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG,
        );

        // Single injection point replaces the six preg_replace calls (html lang,
        // favicon link, favicon img, body data-attrs, sidebar project name, title)
        // the SPA used to need server-side values for. json_encode's default
        // escaping handles quotes and backslashes but leaves the angle
        // brackets alone, so a consumer-controlled field (e.g. `project.name`
        // in styleguide.yaml) containing a literal script-close tag would
        // otherwise terminate this <script> element early and let the rest of
        // the value execute as markup/script — an XSS via a value the
        // package doesn't control. JSON_HEX_TAG escapes angle brackets to
        // their < / > forms, which JSON.parse() decodes back to the
        // original characters, so every legitimate value round-trips
        // unchanged while the breakout is closed.
        $html = (string) preg_replace(
            '/<script id="sg-config" type="application\/json">.*?<\/script>/s',
            '<script id="sg-config" type="application/json">' . $configJson . '</script>',
            $html,
            1,
            $count,
        );
        if ($count !== 1) {
            http_response_code(500);
            throw new \RuntimeException(
                'dist/index.html is missing the #sg-config injection point — rebuild the frontend '
                . '(cd frontend && npm run build) or check dist/ for corruption.',
            );
        }

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
            // Per-entry <body> class (component/page/doc) — forwarded to
            // render-cell where it merges after the global `iframe.body_class`.
            if ($meta !== null && !empty($meta['body_class'])) {
                $config['body_class'] = $meta['body_class'];
            }
            // Render mode is forwarded only for components — pages and docs
            // render their own full layout and don't go through render-cell's
            // inset wrapper.
            if ($meta !== null && $route['kind'] === 'component') {
                $config['render'] = $meta['render'] ?? 'inset';
            }
            // File-convention variant (v0.9.0) — Router::parse() has already
            // syntactically whitelisted this; Renderer re-validates existence
            // against the actual styleguide.<variant>.twig files and falls
            // back to the default variant for anything that doesn't resolve.
            if (isset($route['variant']) && is_string($route['variant'])) {
                $config['variant'] = $route['variant'];
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
            $jsUrl = $this->resolveFoundationsJsUrl();
            if ($jsUrl !== null) {
                $config['foundations_js_url'] = $jsUrl;
            }
            // Foundations consumes the normalized palette shape (legacy
            // `shades:` map and free-form `swatches:` list both accepted —
            // see ColorPalettes). Only the foundations render is remapped;
            // component/page/doc renders keep the raw yaml `colors` so
            // consumer templates reading styleguide.colors are untouched.
            $normalizedColors = ColorPalettes::normalize($this->yamlConfig['colors'] ?? null);
            // Both audits below resolve consumer-authored paths against
            // `static_path` on disk. Some of those paths are necessarily
            // real browser URLs (every `site.webmanifest` icon `src` — the
            // browser fetches the manifest), so they carry this base and
            // have to be stripped back before the filesystem sees them.
            $assetBase = (string) (($this->config['twig_context']['templateUrl'] ?? '') ?: '');
            $config['styleguide'] = array_merge($this->yamlConfig, [
                'colors' => $normalizedColors,
                'colors_contrast' => ColorPalettes::contrastMatrix($normalizedColors),
                // Server-side favicon audit (#73) — existence, real pixel
                // dimensions, manifest validation. Null when the yaml block
                // is absent entirely (FaviconAudit::run() still returns its
                // full shape with every entry 'unconfigured'/null, but the
                // template gates the whole section on `styleguide.favicon`
                // being present, matching every other optional section here).
                'favicon_audit' => FaviconAudit::run(
                    (string) ($this->config['static_path'] ?? ''),
                    (array) ($this->yamlConfig['favicon'] ?? []),
                    $assetBase,
                ),
                // Server-side Open Graph image audit (#74) — existence,
                // real pixel dimensions, aspect ratio, file size. Unlike
                // favicon_audit, this one is *not* gated on the yaml key
                // being present — the template's `#og-image` section
                // always renders (empty-state prompt when unconfigured),
                // since an OG image is expected on every project and
                // must not silently vanish from the audit surface.
                'og_image_audit' => OgImageAudit::run(
                    (string) ($this->config['static_path'] ?? ''),
                    $this->yamlConfig['og_image'] ?? null,
                    $assetBase,
                ),
            ]);
        } elseif ($route['kind'] === 'icons') {
            // Standalone icon-catalog page (#87) — a first-level DOKUMENTACE
            // entry, sibling of foundations. Shares the package-shipped
            // foundations CSS bundle (frontend/foundations.css @source-scans
            // templates/icons.twig too), so no separate bundle is needed.
            $config['component_name'] = (string) (
                ((array) ($this->yamlConfig['labels'] ?? []))['icons'] ?? 'Icons'
            );
            $cssUrl = $this->resolveFoundationsCssUrl();
            if ($cssUrl !== null) {
                $config['foundations_css_url'] = $cssUrl;
            }
            $config['styleguide'] = array_merge($this->yamlConfig, [
                // Server-side icon catalog — inline-ready sanitized SVG
                // markup per yaml `icons:` entry. Null when the block is
                // absent/empty; the template renders its empty state then
                // (the sidebar entry itself is gated on `hasIcons`).
                'icons_catalog' => IconsCatalog::build(
                    (string) ($this->config['static_path'] ?? ''),
                    $this->yamlConfig['icons'] ?? null,
                ),
            ]);
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderer->render(
            kind: $route['kind'],
            slug: $route['slug'],
            config: $config,
            langcode: $langcode,
            // Router::parse() / synthesizeEmbeddedRoute() always set this for
            // `render`-type routes, but re-whitelist defensively — $route is a
            // loosely-typed array<string,mixed>, not a value object.
            theme: Router::whitelistTheme($route['theme'] ?? null),
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
        if (count($matches) > 1) {
            // A stale hashed file from a previous build that `emptyOutDir`
            // should have removed (interrupted build, manual file copy, a
            // consumer vendoring dist/ oddly). Pick the newest by mtime so a
            // rebuild's fresh CSS wins over debris instead of depending on
            // glob()'s filesystem-order — and leave a breadcrumb, since
            // silently serving a stale bundle is a confusing bug to chase
            // without one.
            usort($matches, static fn(string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));
            error_log(sprintf(
                '[parisek/styleguide] multiple dist/foundations.*.css found (%s) — using newest: %s',
                implode(', ', array_map('basename', $matches)),
                basename($matches[0]),
            ));
        }
        return '/styleguide/assets/' . basename($matches[0]);
    }

    /**
     * Locate the hashed dist/foundations.*.js file produced by the package's
     * Vite build. Returns the public URL under /styleguide/assets/, or null
     * when the bundle is missing (e.g. consumer hasn't run npm install/build
     * after pulling a package version that introduced this file).
     */
    private function resolveFoundationsJsUrl(): ?string
    {
        $matches = glob($this->distRoot . '/foundations.*.js');
        if ($matches === false || count($matches) === 0) {
            return null;
        }
        if (count($matches) > 1) {
            // A stale hashed file from a previous build that `emptyOutDir`
            // should have removed (interrupted build, manual file copy, a
            // consumer vendoring dist/ oddly). Pick the newest by mtime so a
            // rebuild's fresh JS wins over debris instead of depending on
            // glob()'s filesystem-order — and leave a breadcrumb, since
            // silently serving a stale bundle is a confusing bug to chase
            // without one.
            usort($matches, static fn(string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));
            error_log(sprintf(
                '[parisek/styleguide] multiple dist/foundations.*.js found (%s) — using newest: %s',
                implode(', ', array_map('basename', $matches)),
                basename($matches[0]),
            ));
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
            'health' => new Api\HealthEndpoint($this->parser),
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
