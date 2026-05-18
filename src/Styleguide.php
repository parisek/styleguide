<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;

/**
 * Public bootstrap entry for the styleguide.
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
    private array $config;
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

        $this->parser = new ComponentParser($config['templates_path']);
        $this->renderer = new Renderer($this->twig, $this->config['twig_context']);
        $this->assetServer = new AssetServer($this->distRoot);
    }

    private function buildOwnTwig(string $templatesPath): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath($templatesPath, 'project');
        $loader->addPath(__DIR__ . '/../templates');
        return new Environment($loader, ['cache' => false, 'debug' => true]);
    }

    /**
     * Register the Twig extensions the package's own templates depend on,
     * idempotently. `overview.twig` uses `create_attribute()` (parisek/twig-
     * attribute) and the `|typography` filter (parisek/twig-typography); the
     * sibling intl-extra / string-extra / twig-common are shipped together
     * because consumers' component templates routinely reach for them too.
     *
     * The check via `hasExtension($class)` makes this safe to call after
     * `attachLoaders()` — projects that already registered any of these
     * (e.g. tailwind-base's `static/index.php`) won't see a double-registration
     * error, and their (possibly project-tuned, e.g. TypographyExtension with
     * a settings YAML) instance wins.
     */
    private function registerBundledExtensions(Environment $twig): void
    {
        $extensions = [
            \Twig\Extra\Intl\IntlExtension::class,
            \Twig\Extra\String\StringExtension::class,
            \Parisek\Twig\CommonExtension::class,
            \Parisek\Twig\AttributeExtension::class,
            \Parisek\Twig\TypographyExtension::class,
        ];
        foreach ($extensions as $class) {
            if (class_exists($class) && !$twig->hasExtension($class)) {
                $twig->addExtension(new $class());
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
            return $twig;
        }

        $packageLoader = new FilesystemLoader();
        $packageLoader->addPath($templatesPath, 'project');
        $packageLoader->addPath($packagePath);
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

        match ($route['type']) {
            'asset' => $this->assetServer->serve($route['path']),
            'render' => $this->dispatchRender($route),
            'api' => $this->dispatchApi($route),
            default => $this->dispatchSpa($route),
        };

        // After dispatching a styleguide route, halt the project's downstream router.
        exit;
    }

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

        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        // <html lang="..." data-default-locale="...">
        $html = (string) preg_replace(
            '/<html\s+lang="[^"]*"(?:\s+data-default-locale="[^"]*")?\s*>/',
            sprintf('<html lang="%s" data-default-locale="%s">', $esc($locale), $esc($locale)),
            $html,
            1
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
                1
            );
            $html = (string) preg_replace(
                '/<img\s+src="[^"]*"\s+alt="[^"]*"\s+class="([^"]*)"\s+id="sg-favicon">/',
                '<img src="' . $esc($favicon) . '" alt="" class="$1" id="sg-favicon">',
                $html,
                1
            );
        }

        // <body data-project-name="..." data-project-favicon="...">
        $html = (string) preg_replace(
            '/data-project-name="[^"]*"/',
            'data-project-name="' . $esc($projectName) . '"',
            $html,
            1
        );
        $html = (string) preg_replace(
            '/data-project-favicon="[^"]*"/',
            'data-project-favicon="' . $esc($favicon) . '"',
            $html,
            1
        );

        // <title>
        $html = (string) preg_replace(
            '/<title>[^<]*<\/title>/',
            '<title>Styleguide — ' . $esc($projectName) . '</title>',
            $html,
            1
        );

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        echo $html;
    }

    private function dispatchRender(array $route): void
    {
        $config = [
            'project' => $this->yamlConfig['project'] ?? [],
            'iframe' => $this->yamlConfig['iframe'] ?? [],
            // The overview body reads from `styleguide.colors`, `styleguide.logo`,
            // `styleguide.typography`, `styleguide.labels` — surface the whole yaml
            // map so component/page templates that look up styleguide.* also work.
            'styleguide' => $this->yamlConfig,
        ];
        $langcode = substr((string) $this->config['default_locale'], 0, 2) ?: 'en';

        if (in_array($route['kind'], ['component', 'page'], true)) {
            // Resolve human-readable component name from parsed metadata, if available.
            $meta = $this->parser->parse($route['kind'], $route['slug']);
            if ($meta !== null && !empty($meta['name'])) {
                $config['component_name'] = $meta['name'];
            }
        } elseif ($route['kind'] === 'overview') {
            $config['component_name'] = (string) ($this->yamlConfig['project']['name'] ?? 'Overview');
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderer->render(
            kind: $route['kind'],
            slug: $route['slug'],
            config: $config,
            langcode: $langcode,
        );
    }

    private function dispatchApi(array $route): void
    {
        $endpoint = match ($route['endpoint']) {
            'components' => new Api\ComponentsEndpoint($this->parser),
            'pages' => new Api\PagesEndpoint($this->parser),
            'fields' => new Api\FieldsEndpoint($this->parser),
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
