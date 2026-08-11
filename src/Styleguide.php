<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

use Symfony\Component\Yaml\Exception\ParseException;
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
 * @api This class and its public methods (`__construct`, `run`, `renderObserved`,
 *      `inventory`, `componentDirectories`, `fromYaml`) are the only part of the
 *      PHP surface covered by SemVer. The config array shape passed to the
 *      constructor is part of the contract — see `docs/API.md` § PHP API. All
 *      other classes in `Parisek\Styleguide\*` (Router, Renderer, ComponentParser
 *      methods other than `RENDER_MODES`/`normaliseRender`, AssetServer,
 *      Placeholder, RenderObserver, Api\*) are `@internal` — refactor without
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
 * Or, once `styleguide.yaml` carries a `bootstrap:` section (see
 * {@see self::fromYaml()}), both the HTTP entry point and any CLI consumer can
 * share the exact same project config instead of restating it:
 *
 *     \Parisek\Styleguide\Styleguide::fromYaml(__DIR__ . '/styleguide.yaml', [
 *         'twig_context' => ['templateUrl' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')],
 *     ])->run();
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
    private RenderObserver $observer;
    /**
     * Non-null only when `translations_path` was supplied — discovers and
     * parses `.mo` catalogues on demand. See {@see \Parisek\Styleguide\Translation\TranslationCatalog}.
     */
    private ?\Parisek\Styleguide\Translation\TranslationCatalog $translationCatalog = null;
    /**
     * The locale a `render` request asked for via `?locale=`, resolved
     * against the discovered catalogues — read fresh, at call time, by the
     * `__()`/`_x()`/`_n()`/`_nx()` closures registered in
     * {@see registerBundledHelpers()}, exactly the way `default_locale` is
     * read fresh by the TypographyExtension resolver (see that method's
     * docblock for why that's safe: one `Styleguide` instance per HTTP
     * request). Defaults to `default_locale` — an absent `?locale=` renders
     * exactly as it did before this feature existed.
     */
    private string $requestLocale;
    /**
     * Names of the observation-carrying functions (`component_*`/`page_*`)
     * that lost their registration to something already present on the
     * supplied Twig environment — populated in {@see registerBundledHelpers()},
     * consumed by {@see renderObserved()} to refuse rather than silently
     * return an incomplete trace. Always empty for a package-built
     * environment (the `twig` config key omitted), since there is nothing
     * pre-registered to collide with.
     *
     * @var list<string>
     */
    private array $unobservableFunctions = [];

    /**
     * The single authoritative list of run-truth config keys — values true
     * only about THIS RUN (which process is rendering, over HTTP or CLI),
     * never about the project itself, and therefore forbidden inside a
     * `bootstrap:` YAML section ({@see self::fromYaml()}) even though every
     * one of them is a legal key on the array constructor above. This is the
     * ONE place this ENFORCEMENT is declared — {@see self::fromYaml()}
     * validates purely by walking this list, so adding a new run-truth key
     * (top-level or nested) means changing exactly two things: one entry
     * here, AND the matching entry in the prose enumeration at
     * `docs/API.md` § YAML schemas → `bootstrap:` (the **Forbidden keys**
     * paragraph — that paragraph is itself the single authoritative copy
     * within the docs; the other two mentions of this set elsewhere in
     * `docs/API.md`, plus the `CHANGELOG.md` entry that introduced
     * `fromYaml()`, point back at it instead of re-listing the keys, so
     * they need no edit). Nothing mechanically enforces the docs edit —
     * it is a manual, but deliberately small (one file, one paragraph),
     * companion step to this const. Forgetting to add a new key here means
     * it is silently treated as project-truth (accepted from YAML) rather
     * than silently forbidden — loud in the sense that a project that
     * meant to keep it run-truth would need to notice the acceptance, not
     * that omission itself throws.
     *
     * Entries are `bootstrap.*`-relative dotted paths: a bare name
     * (`'auth'`) checks a top-level `bootstrap` key; a dotted name
     * (`'twig_context.templateUrl'`) checks a key nested one level deep.
     * {@see self::assertNoRunTruthKeys()} walks each path generically, so a
     * nested entry needs no bespoke check the way `templateUrl` once did.
     *
     * Known limitation, not worth the cure: a `bootstrap:` mapping whose keys
     * are numeric (`{0: a, 1: b}`) is reported as a YAML sequence. Symfony's
     * parser produces the identical PHP array for that and for a real
     * sequence — `array_is_list()` cannot tell them apart because PHP does not
     * keep the distinction — so separating them would mean re-parsing the raw
     * YAML for a config nobody writes. The message is wrong; the rejection is
     * not.
     *
     * @var list<string>
     */
    private const RUN_TRUTH_KEYS = [
        'twig',
        'twig_options',
        'auth',
        'dist_path',
        'config_yaml',
        'twig_context.templateUrl',
    ];

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
     *   translations_path?: string|null,
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
            // Absolute path to a directory of compiled `.mo` catalogues, one
            // per locale (`cs_CZ.mo`, `en_US.mo`, …). Null (the default)
            // means no real translator — `__()`/`_x()`/`_n()`/`_nx()` stay
            // the identity stubs, i.e. today's behaviour. See
            // `docs/superpowers/specs/2026-08-11-styleguide-language-switching-design.md`
            // in tailwind-base for the design this key implements.
            'translations_path' => null,
        ];

        // Load styleguide.yaml content config (favicon, iframe.css/js/fonts, etc.)
        $this->yamlConfig = is_file($config['config_yaml'])
            ? (array) Yaml::parseFile($config['config_yaml'])
            : [];

        // `dist_path` override exists for tests only (SpaConfigTest points it at a
        // throwaway temp dir so writing a synthetic index.html fixture doesn't
        // corrupt the package's real built dist/). Consumers never set this.
        $this->distRoot = (string) ($config['dist_path'] ?? (__DIR__ . '/../dist'));

        $translationsPath = $this->config['translations_path'];
        if ($translationsPath !== null) {
            if (!is_string($translationsPath) || $translationsPath === '') {
                throw new \InvalidArgumentException(
                    "Styleguide: config key 'translations_path' must be null or a non-empty string",
                );
            }
            $this->translationCatalog = new \Parisek\Styleguide\Translation\TranslationCatalog($translationsPath);
        }
        // Every render starts on default_locale — dispatchRender() narrows
        // this to the request's own `?locale=` once the route is parsed.
        $this->requestLocale = (string) $this->config['default_locale'];

        $this->twig = $this->config['twig'] instanceof Environment
            ? $this->attachLoaders($this->config['twig'], $config['templates_path'])
            : $this->buildOwnTwig($config['templates_path']);

        $this->registerBundledExtensions($this->twig);
        $this->observer = new RenderObserver();
        $this->registerBundledHelpers($this->twig, $this->observer);

        $this->parser = new ComponentParser($config['templates_path']);
        $this->renderer = new Renderer($this->twig, $this->config['twig_context'], $config['templates_path']);
        $this->assetServer = new AssetServer($this->distRoot);
    }

    /**
     * @api Loader layered over the array constructor — never replaces it.
     *      Reads a project's `styleguide.yaml` `bootstrap:` section and
     *      constructs a `Styleguide` from it, so the HTTP entry point
     *      (`static/index.php`) and any other consumer that needs to render
     *      the same project (e.g. a CLI fixture-coverage audit) share one
     *      declaration of the project's config instead of each restating it.
     *      See `docs/superpowers/specs/2026-08-08-styleguide-render-trace-api-design.md`
     *      § 5 in `tailwind-base` for the design rationale, and `docs/API.md`
     *      § YAML schemas → `bootstrap:` for the key reference.
     *
     * **What goes in the YAML vs. `$overrides` — project-truth vs. run-truth,
     * enforced, not just documented.** Everything true about the PROJECT
     * regardless of who is rendering it (`templates_path`, `static_path`,
     * `default_locale`, `base_url`, `typography_config`, `namespaces`, and
     * the project-shaped part of `twig_context` — `homeUrl`, `frontPageUrl`,
     * `langcode`, …) belongs in `bootstrap:`. Everything true only about
     * THIS RUN — `templateUrl` (computed from `$_SERVER['SCRIPT_NAME']`,
     * correct for exactly one HTTP request and quietly wrong for a CLI
     * process on another machine), a pre-built `twig` environment,
     * `twig_options`, `auth`, `dist_path` — can ONLY arrive via `$overrides`.
     * The exhaustive, single-source list of these keys — top-level and
     * nested alike — is {@see self::RUN_TRUTH_KEYS}; this paragraph
     * describes the distinction the list encodes, not a second copy of it.
     * Writing one of them into `bootstrap:` anyway is a hard
     * `\InvalidArgumentException`, not a silent no-op: a value that merely
     * failed to be READ (the old behaviour for the top-level keys, since
     * nothing copied them out of `$bootstrap` into `$config`) looks
     * identical, from the author's chair, to a value that was silently
     * IGNORED — and `bootstrap.twig_context` used to be copied wholesale,
     * so `templateUrl` written there WAS honoured, not ignored. Both failure
     * shapes are refused now, by name. A key outside this fixed forbidden
     * set that isn't otherwise recognised is not an error — it's forward
     * compatibility with a future schema, the same tolerance
     * `sync-styleguide` already relies on for `project:`/`labels:`.
     *
     * `twig_context` is the one YAML key `$overrides` can partially
     * override: an override supplying only `templateUrl` (the run-dependent
     * value) is merged key-by-key on top of the YAML's own `twig_context`
     * (`homeUrl`/`frontPageUrl`/`langcode`), rather than replacing it
     * wholesale — otherwise every CLI caller would have to restate the
     * project's routes just to add the one key that's actually theirs to
     * supply. Every other config key is a plain override: whatever
     * `$overrides` sets for a scalar key wins outright over the YAML.
     *
     * `config_yaml` is NOT an overridable key via `$overrides` — `$path` IS
     * the file being read, so an override claiming a different `config_yaml`
     * would contradict the very call that produced this config. It is
     * always set to `$path`, after the override merge, so a stray
     * `'config_yaml' => …` entry in `$overrides` is silently correct rather
     * than silently wrong. It is also forbidden as a `bootstrap.*` YAML key
     * (see above) — unlike `$overrides`, there's no benign reading of a
     * project author writing `config_yaml:` inside the file it's already
     * being read from, so that one is refused rather than tolerated.
     *
     * Relative `bootstrap.*` paths (`templates_path`, `static_path`,
     * `typography_config`, each `namespaces.*` value) resolve relative to
     * the YAML FILE'S OWN DIRECTORY, not `__DIR__` of whatever script calls
     * this method and not the process's current working directory — the
     * portability property called out in the design doc: the same
     * `styleguide.yaml` produces the same absolute paths whether it's read
     * by `static/index.php` (HTTP) or a CLI script invoked from an
     * arbitrary cwd.
     *
     * @param array<string, mixed> $overrides Run-truth config, layered on top of the YAML's project-truth. See above.
     * @throws \InvalidArgumentException When `$path` doesn't exist, isn't valid YAML, doesn't parse to a
     *         top-level mapping, has a non-mapping `bootstrap:` key, is missing a required
     *         `bootstrap.templates_path` / `bootstrap.static_path` string, contains a forbidden
     *         run-truth key (see {@see self::RUN_TRUTH_KEYS} for the exhaustive list, top-level and
     *         nested alike), or has a present-but-optional key (`default_locale`, `base_url`, `typography_config`,
     *         `namespaces`, `namespaces.*`, `twig_context`) of the wrong type. Each message names the
     *         file and the specific problem — this method never falls back to a guessed default for
     *         a required key and never coerces or silently drops a malformed optional one, because a
     *         guessed `templates_path` that's wrong, or a forbidden key that's quietly ignored, is a
     *         silent-wrong-config bug wearing the same clothes as the `ReflectionProperty` hack this
     *         whole API replaces.
     */
    public static function fromYaml(string $path, array $overrides = []): self
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException(sprintf(
                "Styleguide::fromYaml(): config file not found at '%s'",
                $path,
            ));
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(sprintf(
                "Styleguide::fromYaml(): '%s' is not valid YAML: %s",
                $path,
                $e->getMessage(),
            ), 0, $e);
        }

        // array_is_list() rejects a YAML sequence (`- one\n- two`) too — that
        // parses to a PHP list, which is_array() alone would accept, but it
        // isn't the mapping shape `bootstrap:`/`project:`/… keys need.
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException(sprintf(
                "Styleguide::fromYaml(): '%s' must parse to a YAML mapping at the top level, got %s",
                $path,
                is_array($data) ? 'a YAML sequence' : get_debug_type($data),
            ));
        }

        // A `bootstrap:` key that's simply absent from the document is not
        // an error here (the required-key checks below report that) — but a
        // key that IS present and is the wrong shape must say so, not fall
        // through and get misreported as "missing bootstrap.templates_path".
        // Two shapes disguise themselves as "missing" if only is_array() is
        // checked: a YAML sequence (`bootstrap:\n  - a\n  - b`) parses to a
        // PHP list, which is_array() alone accepts; and `bootstrap:` with no
        // value parses to null, which `?? []` used to silently coerce to an
        // empty mapping. array_is_list() catches the sequence case the same
        // way the top-level `$data` check above does; the `!== []` guard
        // keeps a genuinely empty mapping (`bootstrap: {}`) — a legal,
        // still-missing-its-required-keys shape — out of that branch, since
        // array_is_list([]) is true for an empty array.
        if (array_key_exists('bootstrap', $data)) {
            $bootstrap = $data['bootstrap'];
            if (!is_array($bootstrap) || ($bootstrap !== [] && array_is_list($bootstrap))) {
                throw new \InvalidArgumentException(sprintf(
                    "Styleguide::fromYaml(): '%s' key 'bootstrap' must be a mapping, got %s",
                    $path,
                    is_array($bootstrap) ? 'a YAML sequence' : get_debug_type($bootstrap),
                ));
            }
        } else {
            $bootstrap = [];
        }

        foreach (['templates_path', 'static_path'] as $key) {
            if (empty($bootstrap[$key]) || !is_string($bootstrap[$key])) {
                throw new \InvalidArgumentException(sprintf(
                    "Styleguide::fromYaml(): '%s' is missing required 'bootstrap.%s' (non-empty string)",
                    $path,
                    $key,
                ));
            }
        }

        // Run-truth keys are forbidden inside bootstrap: — not merely
        // unrecognised. `$overrides` is documented as the ONLY place they can
        // arrive from (see docblock § What goes in the YAML vs. $overrides);
        // an author who deliberately writes one here (e.g. `auth:` expecting
        // it to gate the styleguide) must be told it was refused, not have
        // it silently vanish while looking like it took effect. Contrast
        // with a genuinely unknown key, which round-trips unharmed for
        // forward compatibility (see the `sync-styleguide` generator-safety
        // note in docs/API.md) — only the fixed set in self::RUN_TRUTH_KEYS
        // fails, top-level or nested alike.
        self::assertNoRunTruthKeys($bootstrap, $path);

        // Resolve relative bootstrap.* paths against the YAML's own
        // directory (see docblock) — NOT __DIR__ of the caller, NOT cwd.
        $baseDir = dirname($path);
        $realBase = realpath($baseDir);
        if ($realBase !== false) {
            $baseDir = $realBase;
        }

        $config = [
            'templates_path' => self::resolveYamlPath((string) $bootstrap['templates_path'], $baseDir),
            'static_path' => self::resolveYamlPath((string) $bootstrap['static_path'], $baseDir),
        ];

        // Every other bootstrap.* key is optional — omitted here (not
        // defaulted) when absent from the YAML, so Styleguide::__construct's
        // OWN default-merging (`$config + [...]`) is the single place those
        // defaults live, rather than restating them a second time. Unlike
        // the old silent-ignore behaviour, a key that IS present but carries
        // the wrong type throws — a typo'd `base_url: []` must fail loudly
        // at load time, naming the offending key, rather than quietly
        // falling back to the constructor's default as if nothing was
        // written at all.
        if (array_key_exists('default_locale', $bootstrap)) {
            if (!is_string($bootstrap['default_locale']) || $bootstrap['default_locale'] === '') {
                throw new \InvalidArgumentException(sprintf(
                    "Styleguide::fromYaml(): '%s' key 'bootstrap.default_locale' must be a non-empty string, got %s",
                    $path,
                    get_debug_type($bootstrap['default_locale']),
                ));
            }
            $config['default_locale'] = $bootstrap['default_locale'];
        }
        if (array_key_exists('base_url', $bootstrap)) {
            if (!is_string($bootstrap['base_url']) || $bootstrap['base_url'] === '') {
                throw new \InvalidArgumentException(sprintf(
                    "Styleguide::fromYaml(): '%s' key 'bootstrap.base_url' must be a non-empty string, got %s",
                    $path,
                    get_debug_type($bootstrap['base_url']),
                ));
            }
            $config['base_url'] = $bootstrap['base_url'];
        }
        if (array_key_exists('typography_config', $bootstrap)) {
            if (!is_string($bootstrap['typography_config'])) {
                throw new \InvalidArgumentException(sprintf(
                    "Styleguide::fromYaml(): '%s' key 'bootstrap.typography_config' must be a string, got %s",
                    $path,
                    get_debug_type($bootstrap['typography_config']),
                ));
            }
            // An explicit empty string means "no typography config" — a
            // legitimate no-op, not an error, and not distinguishable in
            // the YAML from "key absent" any other way.
            if ($bootstrap['typography_config'] !== '') {
                $config['typography_config']
                    = self::resolveYamlPath($bootstrap['typography_config'], $baseDir);
            }
        }
        if (array_key_exists('translations_path', $bootstrap)) {
            if (!is_string($bootstrap['translations_path'])) {
                throw new \InvalidArgumentException(sprintf(
                    "Styleguide::fromYaml(): '%s' key 'bootstrap.translations_path' must be a string, got %s",
                    $path,
                    get_debug_type($bootstrap['translations_path']),
                ));
            }
            // Same "explicit empty string opts out, same as absent" shape as
            // typography_config above.
            if ($bootstrap['translations_path'] !== '') {
                $config['translations_path']
                    = self::resolveYamlPath($bootstrap['translations_path'], $baseDir);
            }
        }
        if (array_key_exists('namespaces', $bootstrap)) {
            if (!is_array($bootstrap['namespaces']) || array_is_list($bootstrap['namespaces'])) {
                throw new \InvalidArgumentException(sprintf(
                    "Styleguide::fromYaml(): '%s' key 'bootstrap.namespaces' must be a mapping of "
                        . 'name => path, got %s',
                    $path,
                    is_array($bootstrap['namespaces']) ? 'a YAML sequence' : get_debug_type($bootstrap['namespaces']),
                ));
            }
            $namespaces = [];
            foreach ($bootstrap['namespaces'] as $name => $namespacePath) {
                if (!is_string($name) || $name === '') {
                    throw new \InvalidArgumentException(sprintf(
                        "Styleguide::fromYaml(): '%s' key 'bootstrap.namespaces' has a non-string or empty "
                            . 'namespace name, got %s',
                        $path,
                        get_debug_type($name),
                    ));
                }
                if (!is_string($namespacePath) || $namespacePath === '') {
                    throw new \InvalidArgumentException(sprintf(
                        "Styleguide::fromYaml(): '%s' key 'bootstrap.namespaces.%s' must be a non-empty "
                            . 'string, got %s',
                        $path,
                        $name,
                        get_debug_type($namespacePath),
                    ));
                }
                $namespaces[$name] = self::resolveYamlPath($namespacePath, $baseDir);
            }
            $config['namespaces'] = $namespaces;
        }
        if (array_key_exists('twig_context', $bootstrap)) {
            if (!is_array($bootstrap['twig_context']) || array_is_list($bootstrap['twig_context'])) {
                throw new \InvalidArgumentException(sprintf(
                    "Styleguide::fromYaml(): '%s' key 'bootstrap.twig_context' must be a mapping, got %s",
                    $path,
                    is_array($bootstrap['twig_context']) ? 'a YAML sequence' : get_debug_type($bootstrap['twig_context']),
                ));
            }
            // `templateUrl` (the canonical run-truth value this whole
            // boundary exists for — computed from $_SERVER['SCRIPT_NAME'],
            // correct for exactly one HTTP request) is already refused by
            // the self::assertNoRunTruthKeys() call above, which walks
            // self::RUN_TRUTH_KEYS including the nested 'twig_context.*'
            // entries — nothing bespoke needed here.
            $config['twig_context'] = $bootstrap['twig_context'];
        }

        // $overrides is run-truth (see docblock). twig_context is merged
        // key-by-key rather than replaced wholesale, so an override
        // supplying only the run-dependent `templateUrl` doesn't blow away
        // the project's own homeUrl/frontPageUrl/langcode from the YAML.
        if (isset($overrides['twig_context']) && is_array($overrides['twig_context'])) {
            $overrides['twig_context'] = $overrides['twig_context'] + ($config['twig_context'] ?? []);
        }

        $config = $overrides + $config;
        // Never overridable — $path IS the file this config was read from.
        $config['config_yaml'] = $path;

        return new self($config);
    }

    /**
     * Walks {@see self::RUN_TRUTH_KEYS} against `$bootstrap` and throws on
     * the first one present, whether top-level (`'auth'`) or nested
     * (`'twig_context.templateUrl'`). Reports the same message shape either
     * way, naming the full dotted `bootstrap.*` path so a nested hit points
     * at the leaf, not just the containing mapping.
     *
     * A dotted entry only matches when every intermediate segment is itself
     * an array — a `bootstrap.twig_context` that fails its OWN type check
     * later (e.g. a string instead of a mapping) simply doesn't match here,
     * so this method never masks that separate, more specific error.
     *
     * @param array<string, mixed> $bootstrap
     */
    private static function assertNoRunTruthKeys(array $bootstrap, string $path): void
    {
        foreach (self::RUN_TRUTH_KEYS as $keyPath) {
            $cursor = $bootstrap;
            $present = true;
            foreach (explode('.', $keyPath) as $segment) {
                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                    $present = false;
                    break;
                }
                $cursor = $cursor[$segment];
            }
            if ($present) {
                throw new \InvalidArgumentException(sprintf(
                    "Styleguide::fromYaml(): '%s' key 'bootstrap.%s' is a run-truth value — it can only "
                        . 'be supplied via $overrides, never via the YAML. See Styleguide::fromYaml() docblock.',
                    $path,
                    $keyPath,
                ));
            }
        }
    }

    /**
     * Resolves a `bootstrap.*` YAML value against `$baseDir` (the YAML
     * file's own directory) unless it's already absolute. `realpath()` is
     * used opportunistically to normalise `../` segments and symlinks when
     * the target already exists; a target that doesn't exist yet (a
     * `namespaces` entry for a directory created later, say) falls back to
     * plain string concatenation rather than failing — mirrors how the
     * array constructor never requires `templates_path`/`static_path` to
     * exist ahead of time either.
     */
    private static function resolveYamlPath(string $value, string $baseDir): string
    {
        if ($value === '') {
            return $baseDir;
        }
        if (self::isAbsoluteFilesystemPath($value)) {
            return $value;
        }
        $joined = rtrim($baseDir, '/') . '/' . $value;
        $real = realpath($joined);
        return $real !== false ? $real : $joined;
    }

    /**
     * `/…` (POSIX/UNC-style, incl. `\\server\share` normalised forms) or
     * `C:\…` / `C:/…` (Windows drive letter) — the two absolute-path shapes
     * worth distinguishing from "relative to the YAML's directory".
     */
    private static function isAbsoluteFilesystemPath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
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
     * a settings YAML) instance wins. Trade-off: a project that pre-registered
     * its own single-argument `TypographyExtension('...')` (no locale resolver)
     * before upgrading past 1.9.0 keeps working exactly as before — no fatal,
     * no behaviour change — but silently misses the per-language layer this
     * method would otherwise wire up. That is the same trade-off `hasExtension()`
     * has always made for every setting the project's own instance carries
     * (a tuned YAML path is silently kept too); it is not a new failure mode,
     * just a new instance of the existing one. Documented in README.md § Bootstrap.
     */
    private function registerBundledExtensions(Environment $twig): void
    {
        // TypographyExtension accepts an optional config-file path; when the
        // project supplied one via `typography_config`, register the extension
        // with that path instead of the default empty instance. Keep the
        // hasExtension() check so projects that pre-registered it themselves
        // win — their instance carries whatever runtime settings they tuned.
        //
        // The second constructor argument (parisek/twig-typography >= 1.3) is
        // a locale resolver — a zero-arg callable returning the locale to
        // typeset against, invoked fresh on every `|typography` call.
        // `default_locale` is already this package's single source of truth
        // for "what locale is this render in" — it drives `<html lang>`
        // (dispatchSpa()) and the `langcode` context value handed to every
        // component/page render (dispatchRender()), which a project's own
        // translator (WP `_x()` etc., wired ahead of the identity stubs
        // below) is free to read. Reusing it here keeps that one config key
        // meaning one thing consistently instead of adding a second key a
        // project would have to keep in sync with the first. A styleguide
        // request only ever
        // renders one locale — there is no per-request language switch; the
        // SPA's locale switcher changes only the chrome UI language, never
        // `default_locale` — so a resolver reading `$this->config` fresh on
        // each call (rather than capturing the value up front) is safe: every
        // HTTP request builds its own `Styleguide` instance with its own
        // config, so there is no cross-request bleed.
        if (
            class_exists(\Parisek\Twig\TypographyExtension::class)
            && !$twig->hasExtension(\Parisek\Twig\TypographyExtension::class)
        ) {
            $typographyConfig = $this->config['typography_config'] ?? null;
            $arg = (is_string($typographyConfig) && is_file($typographyConfig)) ? $typographyConfig : '';
            $twig->addExtension(new \Parisek\Twig\TypographyExtension(
                $arg,
                fn(): string => (string) $this->config['default_locale'],
            ));
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
    private function registerBundledHelpers(Environment $twig, RenderObserver $observer): void
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
        //
        // component_*/page_* go through the SAME swallow-duplicate
        // tryAddFunction() as every other helper here — `run()`'s HTTP path
        // must keep tolerating a consumer's pre-registered version exactly
        // as it does today, and that tolerance is exactly what
        // tryAddFunction() already provides. An earlier revision force-won
        // this registration by reflecting into Twig's private
        // `ExtensionSet`/`StagingExtension` internals to evict any
        // pre-existing entry first. That was rejected on review: it
        // relocated the exact hack this whole API exists to eliminate
        // — a consumer no longer reaches into `parisek/styleguide`'s private
        // state, but this package reached into `twig/twig`'s instead, a
        // third-party dependency with its own release cycle, one step
        // further from anyone who could fix a future internal-shape change.
        //
        // Instead: `tryAddFunction()`'s return value tells us whether OUR
        // closure actually got wired in. When it didn't — the consumer
        // pre-registered its own `component_*`/`page_*` on a shared
        // environment before constructing `Styleguide`, or the extension set
        // was already locked — the render observer ({@see RenderObserver})
        // is provably not wired into this render, so `renderObserved()`
        // records the collision in `$this->unobservableFunctions` and
        // refuses outright (see {@see renderObserved()}) rather than
        // silently returning an empty/partial trace. `run()`'s HTTP path
        // doesn't consult `$this->unobservableFunctions` at all, so it is
        // unaffected: whichever `component_*`/`page_*` won the registration
        // race keeps rendering exactly as before.
        if (!self::tryAddFunction($twig, new TwigFunction(
            'component_*',
            static function (Environment $env, array $context, string $template_name, array $content = []) use ($observer): string {
                return self::renderNamespaced($env, $context, '@component', $template_name, $content, 'Component', $observer);
            },
            ['needs_environment' => true, 'needs_context' => true, 'is_safe' => ['html']],
        ))) {
            $this->unobservableFunctions[] = 'component_*';
        }
        if (!self::tryAddFunction($twig, new TwigFunction(
            'page_*',
            static function (Environment $env, array $context, string $template_name, array $content = []) use ($observer): string {
                return self::renderNamespaced($env, $context, '@page', $template_name, $content, 'Page', $observer);
            },
            ['needs_environment' => true, 'needs_context' => true, 'is_safe' => ['html']],
        ))) {
            $this->unobservableFunctions[] = 'page_*';
        }

        // Identity translation stubs — WordPress consumers register the
        // real `__()` / `_x()` / `_n()` / `_nx()` BEFORE constructing
        // `Styleguide` (their pre-registration wins because our
        // `tryAddFunction` then swallows the duplicate-name exception).
        // Non-WP projects get either the `.mo`-backed reader below (when
        // `translations_path` is configured) or this passthrough, so
        // component templates that wrap strings in `_x()` don't need to
        // branch on WP availability either way. Signatures match the WP
        // originals so templates passing extra context / domain / number
        // arguments don't trip ArgumentCountError.
        //
        // `$this->requestLocale` is read fresh, inside each closure, at call
        // time — not captured up front — for the same reason the
        // TypographyExtension locale resolver above does that: one
        // `Styleguide` instance serves exactly one HTTP request, so there's
        // no cross-request bleed, and dispatchRender() only knows the
        // request's `?locale=` after the route is parsed, which happens
        // after this registration runs.
        $catalog = $this->translationCatalog;
        if ($catalog !== null) {
            self::tryAddFunction($twig, new TwigFunction(
                '__',
                fn(string $text, string $domain = 'default'): string
                    => $catalog->lookup($this->requestLocale, $text),
            ));
            self::tryAddFunction($twig, new TwigFunction(
                '_x',
                fn(string $text, string $context = '', string $domain = 'default'): string
                    => $catalog->lookup($this->requestLocale, $text, $context),
            ));
            self::tryAddFunction($twig, new TwigFunction(
                '_n',
                fn(string $single, string $plural, int $number = 1, string $domain = 'default'): string
                    => $catalog->lookupPlural($this->requestLocale, $single, $plural, $number),
            ));
            self::tryAddFunction($twig, new TwigFunction(
                '_nx',
                fn(string $single, string $plural, int $number, string $context = '', string $domain = 'default'): string
                    => sprintf(
                        $catalog->lookupPlural($this->requestLocale, $single, $plural, $number, $context),
                        $number,
                    ),
            ));
        } else {
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
        }

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
                // Keep each surviving list's ORIGINAL 1-based call position
                // alongside it. The diagnostic below names an argument, and
                // a number counted after re-indexing points at the wrong
                // one the moment any earlier argument was null:
                // `merge_resizer($a, null, $bad, $fallback)` would report
                // `$bad` as #2 when the author wrote it third.
                $positions = [];
                $lists = [];
                $position = 0;
                foreach ($items as $item) {
                    $position++;
                    if (is_array($item)) {
                        $positions[] = $position;
                        $lists[] = $item;
                    }
                }
                $items = $lists;
                // Cache the last index once — `array_key_last()` is
                // O(1) on an array but evaluating it inside the nested
                // loop is wasted work on every image.
                $lastKey = array_key_last($items);
                $images = [];
                foreach ($items as $key => $item) {
                    $kept = 0;
                    foreach ($item as $image) {
                        // All but the last list contribute only their
                        // media-queried entries (variants with `media`).
                        // The last list contributes everything — its
                        // medialess fallback becomes the `<img>` baseline.
                        if ($key !== $lastKey) {
                            if (isset($image['media'])) {
                                $images[] = $image;
                                $kept++;
                            }
                        } else {
                            $images[] = $image;
                            $kept++;
                        }
                    }
                    // A non-final argument that arrived non-empty and
                    // contributed NOTHING is always an authoring mistake,
                    // never a legitimate state — and it fails silently: the
                    // whole viewport layer disappears and the remaining one
                    // stretches across every breakpoint. The symptom reads
                    // as a CSS bug (wrong image at wrong width), so the
                    // hunt starts nowhere near the template that caused it.
                    //
                    // Deliberately NOT reported: a partially-consumed
                    // argument (some entries have `media`, some don't).
                    // Dropping a non-final argument's fallback-shaped
                    // entries is the documented contract, not a mistake —
                    // warning there would flag correct templates.
                    //
                    // The message names symptom and rule, not a single
                    // cause, because there are several ways to reach here
                    // and the most tempting one-line remedy is wrong for
                    // some of them. A single-tuple argument is the common
                    // case (the last tuple of a `|resizer` call is the
                    // unconditional fallback, so it never gets a `media`).
                    // But two tuples are not sufficient either — the
                    // non-final one also needs a non-empty numeric
                    // breakpoint. And a pass-through case such as an
                    // animated GIF (which `{@see self::resizerFilter()}`
                    // returns untouched, by design) yields one medialess
                    // entry no matter how many tuples were requested; that
                    // argument cannot serve a non-final position at all.
                    //
                    // `error_log()` rather than a throw: a styleguide that
                    // dies on a fixture typo is worse than one that renders
                    // and complains, and this mirrors how the
                    // `component_*` / `page_*` misses already report.
                    if ($kept === 0 && $item !== [] && $key !== $lastKey) {
                        error_log(sprintf(
                            'merge_resizer(): argument #%d contributed no variants and was dropped — '
                            . 'all %d of its entries lack a `media` key, and non-final arguments keep '
                            . 'only media-queried ones. Give it at least one NON-LAST tuple with a '
                            . 'non-empty numeric maxWidth (the last tuple of a `|resizer` call is always '
                            . 'the medialess fallback). Sources that pass through `|resizer` untouched, '
                            . 'such as animated GIFs, can only be used in the final position.',
                            $positions[$key],
                            count($item),
                        ));
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
     *
     * Returns whether OUR function actually won the registration — `true`
     * when `addFunction()` succeeded, `false` on either failure shape
     * (duplicate name, or the extension set already locked). This return
     * value is how {@see registerBundledHelpers()} detects, for
     * `component_*`/`page_*` specifically, that the render observer did NOT
     * get wired into a consumer-supplied environment — see
     * `$this->unobservableFunctions` and {@see renderObserved()}. No
     * internals reflection needed: the ordinary `addFunction()` call this
     * method already makes for every other helper is itself sufficient
     * detection, because success/failure IS the fact renderObserved() needs.
     */
    private static function tryAddFunction(Environment $twig, TwigFunction $function): bool
    {
        try {
            $twig->addFunction($function);
            return true;
        } catch (\LogicException $e) {
            self::logUnexpectedRegistrationFailure($function->getName(), $e);
            return false;
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
        RenderObserver $observer,
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

        // Record this call BEFORE rendering — depth-at-entry is what marks a
        // call "direct" (nothing on the stack yet) vs "nested" (something
        // is), and the parent name has to be read off the stack as it stood
        // right before this call pushed onto it. The `finally` mirrors
        // Renderer::renderInner()'s own reset pattern: the stack pops
        // whether the render below succeeds or throws, so a component that
        // fails mid-render never leaves a stale entry that would misreport
        // its siblings as nested inside it.
        $observer->enter($normalised, $content);
        try {
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
        } finally {
            $observer->exit();
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
     * @api Render observation. Renders ONE fixture (`kind`/`slug`[/`variant`])
     *      and returns both the resulting HTML and the trace of every
     *      `component_*`/`page_*` invocation the render produced —
     *      observations, not judgement (see the design rationale in
     *      `docs/superpowers/specs/2026-08-08-styleguide-render-trace-api-design.md`
     *      in the `tailwind-base` repo): no flattening, no truthiness
     *      evaluation, no coverage computed. What counts as a defect is a
     *      consumer-owned decision; this method only reports what happened.
     *
     * Named `renderObserved()` — deliberately generic ("render observation"),
     * not `renderWithTrace()`/`inventory()`-style placeholders tied to any one
     * consumer's vocabulary. Lives on `Styleguide` rather than a new facade
     * because this class is already the package's one `@api`-covered surface
     * and already owns both collaborators (`$this->renderer`, `$this->parser`)
     * the method needs — a separate facade would only add an indirection with
     * nothing of its own to hide. `Renderer` stays fully `@internal`; nothing
     * about its own surface changes here.
     *
     * The recorder ({@see RenderObserver}) is wired into the `component_*`/
     * `page_*` Twig functions themselves (registered in
     * {@see registerBundledHelpers()}, which every `Styleguide` construction
     * runs), not layered on afterward by this method. That wiring IS
     * registration-order dependent, though: when a consumer supplies their
     * own Twig environment (the `twig` config key) with `component_*`/
     * `page_*` already registered — a WordPress theme does this because its
     * component templates need those functions before `Styleguide` ever
     * gets constructed — this package's own closures lose the registration
     * race and the recorder never sees a single call. That state is
     * detected at construction time (see `$this->unobservableFunctions`)
     * and this method REFUSES to run rather than return a silently
     * incomplete trace — see the check at the top of the method body.
     * `run()`'s HTTP path is unaffected: it doesn't consult
     * `$this->unobservableFunctions`, so the consumer's pre-registered
     * `component_*`/`page_*` keeps rendering exactly as before. A
     * package-built environment (the `twig` config key omitted) never hits
     * this: there is nothing pre-registered to collide with, so the
     * recorder is always wired in and every call above this point continues
     * to hold. Nesting/position is derived from a call-stack the observer
     * maintains across the recursive `renderNamespaced()` calls a nested
     * render produces (depth 0 = direct, depth > 0 = nested, with the
     * immediate parent's name attached) — see {@see RenderObserver} for the
     * full mechanism.
     *
     * `{% include '@component/<x>/<x>.twig' with {...} %}` bypasses the
     * recorder entirely — `include` is a Twig TAG compiled into the generated
     * template class, not a function this package can wrap. Rather than
     * silently missing that call, this method DECLARES it: a regex scan of
     * the top-level fixture's own Twig source (nested includes inside an
     * included file are not walked — a declared limitation, not a silent
     * one) finds `{% include '@component/<x>/<x>.twig' … %}` /
     * `{% include '@page/<x>/<x>.twig' … %}` shapes and adds one entry per
     * match to the returned `unobservable` list. See parisek/styleguide#119.
     *
     * @param 'component'|'page'|'doc' $kind
     * @return array{
     *   html: string,
     *   calls: list<array{component: string, arguments: array<string, mixed>, fixture: array{kind: string, slug: string, variant: string|null}, position: 'direct'|'nested', parent: string|null}>,
     *   unobservable: list<array{component: string|null, kind: 'component'|'page'|null, fixture: array{kind: string, slug: string, variant: string|null}, source: string, reason?: string}>,
     * }
     * @throws \InvalidArgumentException When `$kind` isn't `component`/`page`/`doc`.
     * @throws \LogicException When the supplied Twig environment already had
     *         `component_*`/`page_*` registered before `Styleguide` was
     *         constructed (or its extension set was already locked), so the
     *         render observer could not be wired in — this environment is
     *         not observable. See the class-level check below and the
     *         `$this->unobservableFunctions` docblock for how this is
     *         detected without reflecting into Twig internals.
     */
    public function renderObserved(string $kind, string $slug, ?string $variant = null): array
    {
        if ($this->unobservableFunctions !== []) {
            throw new \LogicException(sprintf(
                'Styleguide::renderObserved(): cannot observe this render — %s could not be registered on the '
                . 'supplied Twig environment, because a function of that name (or a locked extension set) was '
                . 'already present before Styleguide was constructed. renderObserved() requires exclusive control '
                . 'of component_*/page_* to record a trace, and a partial/silent trace would be worse than this '
                . 'refusal. Fix: construct Styleguide with a pristine environment (omit the `twig` config key, '
                . 'the package builds its own), or, if a shared environment is required, do not pre-register '
                . 'component_*/page_* on it before constructing Styleguide — the package registers its own '
                . '(honouring any OTHER pre-registered helper, e.g. real WP __()) and both run() and '
                . 'renderObserved() work off the same wiring. This does not affect run()\'s HTTP path, which '
                . 'keeps rendering with whichever component_*/page_* won the registration race.',
                implode(', ', $this->unobservableFunctions),
            ));
        }

        if (!in_array($kind, ['component', 'page', 'doc'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Styleguide::renderObserved(): kind must be one of component/page/doc, got "%s"',
                $kind,
            ));
        }

        $fixture = ['kind' => $kind, 'slug' => $slug, 'variant' => $variant];

        $renderConfig = [
            'project' => $this->yamlConfig['project'] ?? [],
            'iframe' => $this->yamlConfig['iframe'] ?? [],
            'styleguide' => $this->yamlConfig,
        ];
        if ($variant !== null) {
            $renderConfig['variant'] = $variant;
        }

        $this->observer->arm($fixture);
        try {
            $html = $this->renderer->render($kind, $slug, $renderConfig, (string) $this->config['default_locale']);
        } finally {
            $calls = $this->observer->disarm();
        }

        $unobservable = $this->detectUnobservableIncludes($kind, $slug, $variant, $fixture);

        // No "non-empty HTML with an empty trace" assertion here (there used
        // to be one — see RenderObservedTest for the history). It guarded
        // against the recorder being bypassed by a pre-registered consumer
        // `component_*`/`page_*` — a state this method now refuses outright
        // at the top (see the `$this->unobservableFunctions` check above),
        // so by the time execution reaches this point the recorder is
        // guaranteed to have been wired into whatever render just happened.
        // The only way left to reach "non-empty HTML, empty trace" is a
        // fixture that is plain markup — content-only, no
        // component_*/page_*/include call at all — which a `doc/` fixture
        // (changelog, icon gallery, typography specimen) is BY DEFINITION.
        // Keeping the assertion would mean inventory() enumerating a doc and
        // renderObserved() throwing on it — a LogicException on legitimate
        // input, not a caught wiring bug.
        return ['html' => $html, 'calls' => $calls, 'unobservable' => $unobservable];
    }

    /**
     * Parses the fixture's own Twig source with Twig's own lexer/parser
     * (`$this->twig->tokenize()` / `->parse()`) — NOT a regex — and walks the
     * resulting AST for `{% include '@component/<x>/<x>.twig' %}` (and the
     * `@page` equivalent). Actually capturing what such an include renders is
     * out of scope (see {@see renderObserved()}'s docblock) — this only
     * declares that an unobservable call exists, naming whatever
     * component/page id the include path names.
     *
     * Traverses INTO included templates, recursively: an include that does
     * NOT itself name a `@component/…`/`@page/…` self-consistent path (a
     * plain partial, e.g. `_row.twig`) is resolved and scanned in turn, so an
     * include buried inside an included partial (include-of-an-include) is
     * still found — a regex over the top-level source alone could never see
     * it. Cycle-guarded via `$visited` (absolute paths already scanned) so a
     * partial that includes itself, directly or via a longer cycle, cannot
     * loop forever.
     *
     * A non-constant include expression (`{% include some_var %}`,
     * `{% include 'prefix-' ~ name %}`) cannot be resolved statically — that
     * IS a blind spot, and per this API's contract a blind spot must be
     * DECLARED, never silently dropped. Such entries carry `component: null`
     * / `kind: null` plus a `reason` explaining why, rather than being
     * omitted from the result.
     *
     * @param 'component'|'page'|'doc' $kind
     * @param array{kind: string, slug: string, variant: string|null} $fixture
     * @return list<array{component: string|null, kind: 'component'|'page'|null, fixture: array{kind: string, slug: string, variant: string|null}, source: string, reason?: string}>
     */
    private function detectUnobservableIncludes(string $kind, string $slug, ?string $variant, array $fixture): array
    {
        $file = $this->resolveFixtureFile($kind, $slug, $variant);
        if ($file === null || !is_file($file)) {
            return [];
        }

        $visited = [];

        return $this->scanFileForUnobservableIncludes($file, $fixture, $visited);
    }

    /**
     * @param array{kind: string, slug: string, variant: string|null} $fixture
     * @param array<string, true> $visited Absolute paths already scanned in this call tree — cycle guard, passed by reference.
     * @return list<array{component: string|null, kind: 'component'|'page'|null, fixture: array{kind: string, slug: string, variant: string|null}, source: string, reason?: string}>
     */
    private function scanFileForUnobservableIncludes(string $file, array $fixture, array &$visited): array
    {
        $real = realpath($file) ?: $file;
        if (isset($visited[$real])) {
            return [];
        }
        $visited[$real] = true;

        $content = (string) file_get_contents($file);

        try {
            $module = $this->twig->parse($this->twig->tokenize(
                new \Twig\Source($content, $this->relativeTemplatePath($file), $file),
            ));
        } catch (\Twig\Error\Error) {
            // Can't parse this file's includes statically — the render path
            // (Styleguide::renderNamespaced()) will surface the same syntax
            // error through its own, already-tested 500 path. Nothing safe
            // to say about includes inside an unparseable file.
            return [];
        }

        $found = [];
        $this->walkNodeForIncludes($module, $file, $fixture, $found, $visited);

        return $found;
    }

    /**
     * @param array{kind: string, slug: string, variant: string|null} $fixture
     * @param list<array{component: string|null, kind: 'component'|'page'|null, fixture: array{kind: string, slug: string, variant: string|null}, source: string, reason?: string}> $found
     * @param array<string, true> $visited
     */
    private function walkNodeForIncludes(
        \Twig\Node\Node $node,
        string $currentFile,
        array $fixture,
        array &$found,
        array &$visited,
    ): void {
        if ($node instanceof \Twig\Node\IncludeNode) {
            $exprNode = $node->getNode('expr');

            if ($exprNode instanceof \Twig\Node\Expression\ConstantExpression) {
                $target = (string) $exprNode->getAttribute('value');

                // Group 1: `component`/`page`. Group 2: the id —
                // backreferenced via `\2` so only a SELF-consistent path
                // (`@component/<x>/<x>.twig`, the convention every
                // component_*/page_* call already relies on) is matched; an
                // include naming two different segments is a different
                // shape entirely and not a component_*/page_*-equivalent
                // call.
                if (preg_match('/^@(component|page)\/([a-zA-Z0-9_-]+)\/\2\.twig$/', $target, $m) === 1) {
                    $found[] = [
                        'component' => $m[2],
                        'kind' => $m[1],
                        'fixture' => $fixture,
                        'source' => $this->relativeTemplatePath($currentFile),
                    ];
                    // Deliberately NOT recursing into the included
                    // component's own template here — that template's own
                    // fixture (if it has one) is scanned separately via
                    // inventory(); this walk only cares about includes
                    // reachable from THIS fixture's own markup.
                } else {
                    // Not a component/page-equivalent include — e.g. a
                    // plain partial (`_row.twig`). Resolve it and recurse:
                    // the partial may itself include a component
                    // (include-of-an-include), which only shows up by
                    // actually walking into it.
                    $resolved = $this->resolveIncludeTarget($target, $currentFile);
                    if ($resolved !== null) {
                        foreach ($this->scanFileForUnobservableIncludes($resolved, $fixture, $visited) as $nested) {
                            $found[] = $nested;
                        }
                    }
                    // Unresolvable partial path (named but not found on
                    // disk): nothing to traverse into, and this is not
                    // itself a component/page-equivalent include, so no
                    // blind spot to declare for THIS node — Twig's own
                    // render path will raise the "template not found" error
                    // if this branch is actually reached at render time.
                }
            } else {
                // Non-constant (dynamic) include target — cannot be resolved
                // statically. Declared, not dropped: see this method's
                // docblock for why a silent omission here would be exactly
                // the "confidently wrong report" this API exists to avoid.
                $found[] = [
                    'component' => null,
                    'kind' => null,
                    'fixture' => $fixture,
                    'source' => $this->relativeTemplatePath($currentFile),
                    'reason' => 'dynamic include target — cannot be resolved statically',
                ];
            }
        }

        // Node::getIterator() (\IteratorAggregate<int|string, Node>) always
        // yields Node instances — no instanceof guard needed, and PHPStan
        // correctly flags one as always-true.
        foreach ($node as $child) {
            $this->walkNodeForIncludes($child, $currentFile, $fixture, $found, $visited);
        }
    }

    /**
     * Resolves an include target string to an absolute filesystem path, for
     * the recursive traversal in {@see walkNodeForIncludes()}. Two shapes:
     * a namespaced path (`@component/…`, `@page/…`, `@doc/…`) resolves
     * directly under `templates_path`; anything else is treated as relative
     * to the INCLUDING file's own directory, mirroring how a plain
     * (non-namespaced) `{% include %}` resolves in this package's own
     * `FilesystemLoader` setup (see {@see registerConventionalNamespaces()}).
     */
    private function resolveIncludeTarget(string $target, string $currentFile): ?string
    {
        if (preg_match('#^@(component|page|doc)/(.+)$#', $target, $m) === 1) {
            $candidate = rtrim((string) $this->config['templates_path'], '/') . '/' . $m[1] . '/' . $m[2];

            return is_file($candidate) ? $candidate : null;
        }

        $candidate = dirname($currentFile) . '/' . $target;

        return is_file($candidate) ? $candidate : null;
    }

    /**
     * Mirrors `Renderer::renderInner()`'s own candidate resolution order
     * (variant sibling → default `styleguide.twig` → the component's own
     * `<slug>.twig`) so the file this method scans for `{% include %}` is
     * exactly the one that would actually render — duplicated rather than
     * reused because `Renderer::renderInner()` is `@internal`/private and
     * resolves against a `FilesystemLoader` path, not a plain filesystem
     * path this regex scan needs.
     */
    private function resolveFixtureFile(string $kind, string $slug, ?string $variant): ?string
    {
        $dir = rtrim((string) $this->config['templates_path'], '/') . '/' . $kind . '/' . $slug;

        $candidates = [];
        if ($variant !== null && preg_match('/^[a-z0-9-]+$/', $variant) === 1) {
            $candidates[] = $dir . '/styleguide.' . $variant . '.twig';
        }
        $candidates[] = $dir . '/styleguide.twig';
        $candidates[] = $dir . '/' . $slug . '.twig';

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function relativeTemplatePath(string $absolutePath): string
    {
        $base = rtrim((string) $this->config['templates_path'], '/') . '/';

        return str_starts_with($absolutePath, $base) ? substr($absolutePath, strlen($base)) : $absolutePath;
    }

    /**
     * @api Fixture inventory. Lists every renderable component/page/doc
     *      fixture the project's `templates_path` contains, in stable order
     *      — needed for a consumer's determinism assertion (two calls in the
     *      same process must return identical order).
     *
     * Reuses {@see ComponentParser::parseAll()} rather than re-globbing the
     * filesystem — variant naming (`styleguide.<variant>.twig`, the
     * underscore-prefixed-directory partial exclusion, `doc/`) is package
     * doctrine owned by `ComponentParser`, and restating it here would drift
     * the moment that doctrine grows a new shape.
     *
     * One row per fixture: an entry with `has_default_variant` true emits a
     * `variant: null` row (the component/page/doc's own default demo); each
     * entry in `variants` emits one row per named variant.
     *
     * **No `ownFixture` field.** An earlier revision of this method carried
     * one, hardcoded to `true` on every row — dropped rather than fixed,
     * because it cannot be made meaningful from inside this method. This
     * method only enumerates fixtures that exist as real `kind/slug[/variant]`
     * demo files on disk, so every row it can possibly produce already is
     * that kind/slug's own fixture by construction; there is no false case
     * to report. The distinction the field's name promised — "rendered, but
     * NOT by its own fixture" (e.g. a component with no fixture of its own
     * that a trace's `calls` reveal gets rendered somewhere nested, inside a
     * page) — lives on a different axis entirely: it can only be computed by
     * cross-referencing `renderObserved()`'s `calls` across one or more
     * fixtures against this method's own output (a component id appearing in
     * `calls` that never appears as a `slug` here). `inventory()` never looks
     * at what renders what, so it has no data to compute that distinction
     * from, and forcing a synthetic row for it would require inventing
     * entries for slugs that have no fixture file at all — breaking the "one
     * row per real fixture" contract this method exists to keep. A field
     * that can only ever read `true` is worse than no field: a consumer would
     * be tempted to branch on it. If a future consumer needs that
     * cross-reference, it belongs in a helper that takes an `inventory()`
     * result AND one or more `renderObserved()` traces as input — not in
     * this method's row shape.
     *
     * @return list<array{kind: 'component'|'page'|'doc', slug: string, variant: string|null}>
     */
    /**
     * @api Public contract. `list<array{id: string, hasTemplate: bool}>`
     *      shape and `component` scope are SemVer-protected.
     *
     * Enumerates every `component/<id>/` directory REGARDLESS of whether it
     * ever renders — the gap `inventory()` (above) cannot close: that method
     * only lists fixtures that already render, so a directory with no
     * `styleguide*.twig` (a `never-rendered` component, still a real one) or
     * with no `<id>.twig` template at all (not a real component candidate —
     * a stray definition-only directory, or a working directory like
     * `<id>/js/`) is equally invisible to it. A consumer that needs to know
     * about ALL of a project's component directories — not just the ones
     * with a demonstrated fixture — previously had no choice but to `glob()`
     * the templates tree itself, which is exactly the kind of filesystem
     * knowledge this package exists to own on the consumer's behalf.
     *
     * Delegates to `ComponentParser::listDirectories('component')` — the
     * SAME "does `<id>/<id>.twig` exist" check `parse()`/`parseAll()` use
     * before they'll even attempt to read a directory's metadata — so this
     * method can never disagree with the catalogue's own notion of what a
     * component directory is. Deliberately ONE enumeration answering both
     * "what directories exist" and "does this one have a template", rather
     * than two overlapping methods a caller would have to cross-reference by
     * id themselves.
     *
     * @return list<array{id: string, hasTemplate: bool}>
     */
    public function componentDirectories(): array
    {
        return $this->parser->listDirectories('component');
    }

    /**
     * @api Fixture inventory. Lists every renderable component/page/doc
     *      fixture the project's `templates_path` contains, in stable order
     *      — needed for a consumer's determinism assertion (two calls in the
     *      same process must return identical order).
     *
     * Reuses {@see ComponentParser::parseAll()} rather than re-globbing the
     * filesystem — variant naming (`styleguide.<variant>.twig`, the
     * underscore-prefixed-directory partial exclusion, `doc/`) is package
     * doctrine owned by `ComponentParser`, and restating it here would drift
     * the moment that doctrine grows a new shape.
     *
     * One row per fixture: an entry with `has_default_variant` true emits a
     * `variant: null` row (the component/page/doc's own default demo); each
     * entry in `variants` emits one row per named variant.
     *
     * **No `ownFixture` field.** An earlier revision of this method carried
     * one, hardcoded to `true` on every row — dropped rather than fixed,
     * because it cannot be made meaningful from inside this method. This
     * method only enumerates fixtures that exist as real `kind/slug[/variant]`
     * demo files on disk, so every row it can possibly produce already is
     * that kind/slug's own fixture by construction; there is no false case
     * to report. The distinction the field's name promised — "rendered, but
     * NOT by its own fixture" (e.g. a component with no fixture of its own
     * that a trace's `calls` reveal gets rendered somewhere nested, inside a
     * page) — lives on a different axis entirely: it can only be computed by
     * cross-referencing `renderObserved()`'s `calls` across one or more
     * fixtures against this method's own output (a component id appearing in
     * `calls` that never appears as a `slug` here). `inventory()` never looks
     * at what renders what, so it has no data to compute that distinction
     * from, and forcing a synthetic row for it would require inventing
     * entries for slugs that have no fixture file at all — breaking the "one
     * row per real fixture" contract this method exists to keep. A field
     * that can only ever read `true` is worse than no field: a consumer would
     * be tempted to branch on it. If a future consumer needs that
     * cross-reference, it belongs in a helper that takes an `inventory()`
     * result AND one or more `renderObserved()` traces as input — not in
     * this method's row shape.
     *
     * @return list<array{kind: 'component'|'page'|'doc', slug: string, variant: string|null}>
     */
    public function inventory(): array
    {
        $rows = [];
        foreach (['component', 'page', 'doc'] as $kind) {
            foreach ($this->parser->parseAll($kind) as $entry) {
                if ($entry['has_default_variant']) {
                    $rows[] = ['kind' => $kind, 'slug' => $entry['id'], 'variant' => null];
                }
                foreach ($entry['variants'] as $variant) {
                    $rows[] = ['kind' => $kind, 'slug' => $entry['id'], 'variant' => $variant['id']];
                }
            }
        }

        return $rows;
    }

    /**
     * Render one template on the configured environment.
     *
     * The narrow primitive behind offline renders — output written to a file
     * instead of an HTTP response, by a CLI command rather than a request.
     * `renderObserved()` cannot serve them: it renders a catalogue entry and
     * records a call trace, while an offline render needs an arbitrary
     * template (a document shell around a page) and no trace at all.
     *
     * The environment is the fully wired one: `component_*`/`page_*`,
     * `create_attribute()`, `placeholder()`, and — when `translations_path`
     * is configured — the real `.mo`-backed `__()`/`_x()`/`_n()`/`_nx()`
     * resolving against `default_locale`.
     *
     * @param array<string, mixed> $context
     */
    public function renderTemplate(string $name, array $context = []): string
    {
        return $this->twig->render($name, $context + (array) $this->config['twig_context']);
    }

    /**
     * Whether a template name resolves on the configured environment.
     *
     * Callers use it to prefer a project template over a packaged default
     * without catching a loader exception as flow control.
     */
    public function hasTemplate(string $name): bool
    {
        return $this->twig->getLoader()->exists($name);
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
            // Every discovered `.mo` catalogue code (e.g. "cs_CZ") — the
            // exact identifiers `TranslationCatalog::availableLocales()`
            // exposes and the render route's own `?locale=` accepts, so the
            // SPA switcher and the server never disagree on what a "locale"
            // string looks like. Empty when `translations_path` isn't
            // configured — the switcher then has nothing to offer, matching
            // today's behaviour of no catalogue ever being selectable.
            'locales' => $this->translationCatalog?->availableLocales() ?? [],
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
        // `?locale=` overrides the request's translation/langcode locale for
        // this render only — absent, unresolvable, or ambiguous falls back
        // to `default_locale`, i.e. exactly today's behaviour with no
        // exception (design doc § URL and SPA). Resolution/ambiguity is
        // TranslationCatalog's job even when the requested code doesn't
        // resolve to a real catalogue file — an unresolvable code still
        // degrades to default_locale rather than 404ing, matching the
        // gettext-fallback philosophy the reader itself uses.
        $requestedLocale = is_string($route['locale'] ?? null) ? $route['locale'] : null;
        if ($requestedLocale !== null) {
            if ($this->translationCatalog !== null) {
                try {
                    $resolved = $this->translationCatalog->resolveLocaleCode($requestedLocale);
                } catch (\RuntimeException $e) {
                    // Ambiguous two-letter code (e.g. "pt" against pt_BR/pt_PT) —
                    // fail loudly rather than silently rendering a wrong locale,
                    // per the design doc. A render request is the one place this
                    // can surface to a human, since entries()/lookup() alone
                    // would otherwise swallow it into "no such catalogue".
                    http_response_code(400);
                    header('Content-Type: text/plain; charset=utf-8');
                    echo $e->getMessage();
                    return;
                }
                if ($resolved !== null) {
                    $this->requestLocale = $resolved;
                }
            } else {
                // No catalogue configured — there's nothing to resolve
                // against or disagree with, so the (already syntactically
                // whitelisted, see Router::whitelistLocale()) requested code
                // drives `<html lang>`/`langcode` directly. Translation
                // itself stays inert (identity stubs), matching the
                // "no translations_path -> no behaviour change beyond this"
                // contract.
                $this->requestLocale = $requestedLocale;
            }
        }
        $langcode = substr($this->requestLocale, 0, 2) ?: 'en';

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
