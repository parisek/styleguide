<?php

declare(strict_types=1);

// Fixture entrypoint for the package's own e2e smoke tests. Mirrors the shape a
// consuming project ships in `static/index.php`, but points at the fixture
// templates + config. The SPA shell, /styleguide/assets/* and locale JSON are
// served by the package from its own dist/ (resolved in Styleguide.php), so the
// fixture only needs templates + this thin bootstrap.
//
// Run via the built-in server with this file as the router:
//   php -S 127.0.0.1:8421 tests/fixtures/index.php

// When invoked as the router script of PHP's built-in dev server, let real files
// (e.g. fixture images) be served directly; everything else boots the styleguide.
if (php_sapi_name() === 'cli-server') {
    $_uri = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    if ($_uri !== '/' && $_uri !== '' && is_file(__DIR__ . $_uri)) {
        return false;
    }
}

require __DIR__ . '/../../vendor/autoload.php';

use Parisek\Styleguide\Styleguide;
use Symfony\Component\Yaml\Yaml;

// SG_PRESENTATION=1 serves the same fixture with the opt-in presentation
// keys switched on (presentation.spec.js, the third Playwright webServer).
// The keys are merged into a copy of styleguide.yaml, so the shared file
// and every other suite keep today's defaults.
$configYaml = __DIR__ . '/styleguide.yaml';
if (getenv('SG_PRESENTATION')) {
    $presentation = Yaml::parseFile($configYaml);
    $presentation['pages'] = ['group_by' => 'category'];
    $presentation['overview'] = ['default' => 'grid'];
    $configYaml = sys_get_temp_dir() . '/sg-fixture-presentation.yaml';
    file_put_contents($configYaml, Yaml::dump($presentation, 8));
}

(new Styleguide([
    'templates_path'     => __DIR__ . '/templates',
    'static_path'        => __DIR__,
    'config_yaml'        => $configYaml,
    'default_locale'     => 'cs',
    // Wired so the e2e suite exercises the real switcher shape (every
    // discovered `.mo` catalogue) instead of a fixture with nothing to
    // discover — reuses tests/fixtures/translations/*.mo, the same
    // catalogues StyleguideLocaleTest and SpaConfigTest already read.
    'translations_path' => __DIR__ . '/translations',
    // The e2e suites serve this fixture at a second mount too
    // (SG_MOUNT=/tools/ui), to prove a configured base_url end to end.
    'base_url'          => getenv('SG_MOUNT') ?: '/styleguide',
]))->run();

// Styleguide::run() exits on /styleguide/* routes. Any other URL means the
// request wasn't for the styleguide — redirect to the SPA landing (the package's
// canonical root behavior; a WordPress consumer overrides this with its own /).
header('Location: ' . rtrim(getenv('SG_MOUNT') ?: '/styleguide', '/') . '/', true, 302);
exit;
