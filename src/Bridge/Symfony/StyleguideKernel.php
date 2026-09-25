<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Bridge\Symfony;

use Composer\Autoload\ClassLoader;
use Parisek\Styleguide\Bridge\Symfony\DependencyInjection\StyleguideExtension;
use Parisek\Styleguide\Bridge\Symfony\EventListener\ToolbarOutOfPreviewsListener;
use Symfony\Bundle\DebugBundle\DebugBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A micro-kernel that serves the catalogue from a project's front controller.
 *
 * The third way to serve the catalogue, beside the library's
 * `Styleguide::run()` and the bundle in a full Symfony application. It is the
 * bundle, plus the kernel a project without an application would otherwise
 * copy into every front controller. Start it through `FrontController::run()`.
 *
 * Not final. A project that needs more of Symfony subclasses it — in the front
 * controller itself, so a deployment stays one file — and fills the three
 * `project*` hooks. The hooks are the supported surface; overriding anything
 * else is possible and is on the project.
 *
 * @api
 */
class StyleguideKernel extends Kernel
{
    use MicroKernelTrait;

    private readonly string $staticDir;

    private ?string $cacheDir = null;

    /**
     * @param string $staticDir the directory that holds `styleguide.yaml`,
     *                          usually the front controller's `__DIR__`
     */
    public function __construct(string $environment, bool $debug, string $staticDir)
    {
        // The environment becomes a path segment of the cache directory.
        if (preg_match('/^[A-Za-z0-9_-]+$/', $environment) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'StyleguideKernel: the environment must match [A-Za-z0-9_-]+, got "%s".',
                $environment,
            ));
        }

        $this->staticDir = rtrim($staticDir, '/\\');

        parent::__construct($environment, $debug);
    }

    /**
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new StyleguideBundle();

        if ($this->profilerAvailable()) {
            // TwigBundle is here for the profiler, not the catalogue. The
            // profiler renders its own pages with Twig and needs a `twig`
            // service; the catalogue builds and owns its own Environment.
            yield new TwigBundle();
            yield new WebProfilerBundle();
        }

        if ($this->isDebug() && class_exists(DebugBundle::class)) {
            yield new DebugBundle();
        }

        yield from $this->projectBundles();
    }

    /**
     * The directory above the static directory: the theme root.
     */
    public function getProjectDir(): string
    {
        return \dirname($this->staticDir);
    }

    public function getStaticDir(): string
    {
        return $this->staticDir;
    }

    /**
     * Outside the project, for two reasons.
     *
     * The static directory is usually the document root, so a cache directory
     * inside the project can end up on the public web — the compiled container
     * and, with the profiler on, stored request data. And a writable `var/`
     * is one more thing every deployment has to provision; the system temp
     * directory already exists and is writable.
     *
     * The key covers the static directory, the kernel class, the contents of
     * the kernel's own file, of styleguide.yaml (it decides the mount the
     * routes are compiled for) and of Composer's installed.php. A production kernel never checks its container
     * for freshness, so a key without the last two would keep serving the
     * container compiled before a `composer update` or an edit to a subclass
     * in the front controller — until someone cleared the temp directory.
     *
     * The temp directory is shared, so everything lives under a directory
     * private to the current user (see privateTempDir()). The container is PHP
     * the kernel includes; another local user who could write it could run
     * code as the web server.
     *
     * Debug has its own directory. A debug kernel registers the profiler and
     * its routes, and a non-debug kernel never checks cached routes for
     * freshness, so a shared directory would keep serving `/_profiler` after
     * `APP_DEBUG=0`.
     */
    public function getCacheDir(): string
    {
        return $this->cacheDir ??= self::privateTempDir($this->tempDir()) . '/' . $this->cacheKey()
            . '/' . $this->environment . ($this->debug ? '-debug' : '');
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir() . '/log';
    }

    /**
     * Extra bundles for this project. Registered after the kernel's own.
     *
     * @return iterable<BundleInterface>
     */
    protected function projectBundles(): iterable
    {
        return [];
    }

    /**
     * Extra services and bundle configuration for this project. Runs after the
     * kernel's own configuration, so it can extend or override it.
     */
    protected function configureProject(ContainerConfigurator $container): void {}

    /**
     * Extra routes for this project. The catalogue owns `/styleguide`, and the
     * profiler `/_wdt` and `/_profiler` in debug.
     */
    protected function configureProjectRoutes(RoutingConfigurator $routes): void {}

    /**
     * Part of the cache key, for what the key cannot see.
     *
     * The key already follows this kernel's own file and the Composer
     * install. A hook that imports other project files — a services file, a
     * routes file — needs those in the key too, or production keeps the
     * container compiled from their previous version. Return a fingerprint
     * of them, or a deployment id.
     */
    protected function cacheVersion(): string
    {
        return '';
    }

    /**
     * Protected, not private: MicroKernelTrait calls it through reflection,
     * which static analysis cannot see.
     */
    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            // Required by FrameworkBundle, used for CSRF tokens and signed
            // URIs. The catalogue has neither: no forms, no session, no user.
            // Derived from the project path so it is stable per install and
            // there is nothing to provision. A project that adds something
            // that signs sets a real secret in configureProject().
            'secret' => hash('xxh128', $this->getProjectDir()),
            'http_method_override' => false,
            // Stateless. The SPA's theme cookie is read by the catalogue
            // directly, not through a Symfony session.
            'session' => ['enabled' => false],
            'php_errors' => ['log' => true],
        ]);

        $container->extension('styleguide', [
            'config' => $this->staticDir . '/styleguide.yaml',
        ]);

        if ($this->profilerAvailable()) {
            $container->extension('framework', ['profiler' => ['only_exceptions' => false]]);
            $container->extension('web_profiler', [
                'toolbar' => true,
                'intercept_redirects' => false,
            ]);
            $container->services()
                ->set(ToolbarOutOfPreviewsListener::class)
                ->args(['%' . StyleguideExtension::BASE_URL_PARAMETER . '%'])
                ->tag('kernel.event_listener', [
                    'event' => 'kernel.response',
                    'priority' => ToolbarOutOfPreviewsListener::PRIORITY,
                ]);
        }

        $this->configureProject($container);
    }

    /**
     * Protected for the same reason as configureContainer().
     */
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // Resolved through the bundle, not through the project directory:
        // vendor/ is at the project root in one layout, beside the theme in
        // another, and under the static directory in a third.
        $routes->import('@StyleguideBundle/Resources/config/routes.php', 'php');

        if ($this->profilerAvailable()) {
            // Registering the bundle is not enough. Without these routes the
            // toolbar's template cannot generate `_wdt_stylesheet`, and every
            // HTML page answers 500 — while booting the kernel and dumping the
            // router both report a healthy application.
            $routes->import($this->profilerRoutes('wdt'))->prefix('/_wdt');
            $routes->import($this->profilerRoutes('profiler'))->prefix('/_profiler');
        }

        $this->configureProjectRoutes($routes);
    }

    /**
     * The profiler is a debug tool and stays one: it stores every request it
     * sees and serves them back from `/_profiler`. It runs only in debug, and
     * only where the project installed it, which is `require-dev`.
     */
    private function profilerAvailable(): bool
    {
        return $this->isDebug()
            && class_exists(WebProfilerBundle::class)
            && class_exists(TwigBundle::class);
    }

    /**
     * The PHP route file where the installed profiler ships one, the XML one
     * otherwise. Symfony is phasing XML configuration out; older releases ship
     * only XML.
     */
    private function profilerRoutes(string $name): string
    {
        $dir = \dirname((string) (new \ReflectionClass(WebProfilerBundle::class))->getFileName())
            . '/Resources/config/routing/';

        foreach (['php', 'xml'] as $extension) {
            if (is_file($dir . $name . '.' . $extension)) {
                return '@WebProfilerBundle/Resources/config/routing/' . $name . '.' . $extension;
            }
        }

        throw new \LogicException(sprintf(
            'The installed WebProfilerBundle ships no "%s" route file in %s. Remove '
            . 'symfony/web-profiler-bundle, or report this with its version.',
            $name,
            $dir,
        ));
    }

    /**
     * `<temp>/styleguide-kernel-<uid>`, mode 0700, owned by the current user.
     *
     * Created on first use. Refused, not repaired, when it already exists and
     * is a symlink, belongs to someone else, or is open to group or others:
     * another user got there first, and nothing inside can be trusted.
     */
    private static function privateTempDir(string $tempDir): string
    {
        $uid = self::effectiveUid($tempDir);
        $dir = rtrim($tempDir, '/\\') . '/styleguide-kernel-' . $uid;

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0o700) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('StyleguideKernel: cannot create the cache directory %s.', $dir));
            }
            // mkdir() applies the umask, which can only remove bits; set the
            // mode explicitly so a restrictive umask cannot leave it unusable.
            @chmod($dir, 0o700);
        }

        // Windows has no POSIX owner or mode bits to check. Its default temp
        // directory is per-user (%TEMP% under the profile), which is the
        // guarantee relied on there; a link is still refused.
        if (\PHP_OS_FAMILY === 'Windows') {
            if (is_link($dir)) {
                throw new \RuntimeException(sprintf('StyleguideKernel: refusing the cache directory %s: it is a link.', $dir));
            }

            return $dir;
        }

        clearstatcache(true, $dir);
        $owner = @fileowner($dir);
        $mode = @fileperms($dir);

        if (is_link($dir) || $owner !== $uid || $mode === false || ($mode & 0o077) !== 0) {
            throw new \RuntimeException(sprintf(
                'StyleguideKernel: refusing the cache directory %s. It must be a real directory owned by '
                . 'uid %d with mode 0700; another local user may have created it. Remove it and retry.',
                $dir,
                $uid,
            ));
        }

        return $dir;
    }

    /**
     * The user PHP runs as. Not getmyuid(): that is the owner of the running
     * script, which is the deploy user when PHP runs as the web server's.
     * Without ext-posix, the owner of a file this process just created is the
     * same answer.
     */
    private static function effectiveUid(string $tempDir): int
    {
        if (\function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        $probe = @tempnam($tempDir, 'sg-uid-');
        if ($probe === false) {
            throw new \RuntimeException(sprintf('StyleguideKernel: cannot write to %s.', $tempDir));
        }

        $uid = @fileowner($probe);
        @unlink($probe);

        if ($uid === false) {
            throw new \RuntimeException(sprintf('StyleguideKernel: cannot read the owner of a file in %s.', $tempDir));
        }

        return $uid;
    }

    /**
     * The shared temp directory the private one is created in.
     *
     * @internal a seam for tests
     */
    protected function tempDir(): string
    {
        return sys_get_temp_dir();
    }

    private function cacheKey(): string
    {
        // The static directory, not only the project: two catalogues under one
        // project must not share a container, which fixes the config path.
        $parts = [$this->staticDir, static::class, $this->cacheVersion()];

        $kernelFile = (new \ReflectionClass(static::class))->getFileName();
        if ($kernelFile !== false) {
            // Contents, not mtime: a deployment that preserves timestamps would
            // otherwise keep the container compiled from the previous hooks.
            $parts[] = (string) @hash_file('xxh128', $kernelFile);
        }

        // styleguide.yaml decides the mount, which is compiled into the routes.
        // A production container never checks it for freshness, so a changed
        // base_url would leave the routes at the old mount.
        $parts[] = (string) @hash_file('xxh128', $this->staticDir . '/styleguide.yaml');

        // installed.php records every package version and reference, so it
        // changes with every install and update that changes code.
        $loaderFile = (new \ReflectionClass(ClassLoader::class))->getFileName();
        if ($loaderFile !== false) {
            $parts[] = (string) @hash_file('xxh128', \dirname($loaderFile) . '/installed.php');
        }

        return hash('xxh128', implode("\0", $parts));
    }
}
