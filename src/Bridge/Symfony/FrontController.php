<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Bridge\Symfony;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves the catalogue from a project's front controller through
 * StyleguideKernel. What stays in the front controller is what the package
 * cannot do: find the autoloader, and hand a static file back to PHP's
 * built-in server.
 *
 *     if (FrontController::isBuiltInServerFile(__DIR__)) {
 *         return false;
 *     }
 *
 *     FrontController::run(__DIR__);
 *
 * A second path beside `Styleguide::run()`, not a replacement for it. The
 * library path does not change and needs no Symfony.
 *
 * @api
 */
final class FrontController
{
    /**
     * The front controller the package ships, for projects to copy. Written by
     * `vendor/bin/styleguide front-controller:init`.
     */
    public static function stubPath(): string
    {
        return \dirname(__DIR__, 3) . '/resources/front-controller.php';
    }

    /**
     * Handles the current request and sends the response.
     *
     * @param string                          $staticDir   the directory that holds `styleguide.yaml`
     * @param class-string<StyleguideKernel> $kernelClass a subclass that fills the project hooks
     */
    public static function run(string $staticDir, string $kernelClass = StyleguideKernel::class): void
    {
        if (!class_exists(FrameworkBundle::class)) {
            // A clear answer instead of a class-not-found fatal. The library
            // path needs no Symfony, so the package cannot require it.
            self::fail('FrontController::run() needs symfony/framework-bundle. Run '
                . '`composer require symfony/framework-bundle`, or serve the catalogue '
                . 'with Styleguide::run(), which needs no Symfony.');

            return;
        }

        if (!is_file($staticDir . '/styleguide.yaml')) {
            // Checked here, not left to the kernel: there it surfaces as a
            // container or YAML exception, which production shows as a bare
            // 500. The path goes to the log only, not to the visitor.
            self::fail(
                'The styleguide is misconfigured: no styleguide.yaml where the front controller expects it. '
                    . 'The server log names the directory.',
                sprintf(
                    'FrontController::run(): no styleguide.yaml in %s. Pass the directory that holds it, '
                        . 'usually the front controller\'s own __DIR__.',
                    $staticDir,
                ),
            );

            return;
        }

        if (!is_a($kernelClass, StyleguideKernel::class, true)) {
            throw new \InvalidArgumentException(sprintf(
                'FrontController::run(): "%s" does not extend %s.',
                $kernelClass,
                StyleguideKernel::class,
            ));
        }

        [$environment, $debug] = self::resolveEnvironment($_SERVER, $_ENV, static fn(string $name): string|false => getenv($name));

        if ($debug) {
            Debug::enable();
        }

        $kernel = new $kernelClass($environment, $debug, $staticDir);
        $request = Request::createFromGlobals();
        $response = $kernel->handle($request);
        $response->send();
        $kernel->terminate($request, $response);
    }

    /**
     * Extensions the built-in server may send as files. An allowlist, because
     * everything else under the static directory is either configuration
     * (`styleguide.yaml`), a dependency manifest, or PHP the built-in server
     * would execute rather than send.
     */
    private const STATIC_EXTENSIONS = [
        'css', 'js', 'mjs', 'map', 'json', 'html', 'txt',
        'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp4', 'webm', 'mp3', 'pdf',
    ];

    /**
     * Allowed extensions whose files are still not assets.
     */
    private const DENIED_FILES = ['composer.json', 'composer.lock', 'package.json', 'package-lock.json'];

    /**
     * Directories never served, wherever they appear in the path.
     */
    private const DENIED_DIRECTORIES = ['vendor', 'node_modules'];

    /**
     * True when PHP's built-in server should send the request as a file from
     * the static directory: a dist asset, an image, a font. The front
     * controller then returns `false`, which only a router script can do.
     *
     * False under any other server, for anything outside the directory, for a
     * dot-segment, under `vendor/` or `node_modules/`, and for any file that is
     * not an asset by extension. A refused file goes to the kernel, which
     * answers 404 for anything outside `/styleguide`.
     */
    public static function isBuiltInServerFile(string $staticDir, ?string $requestUri = null, string $sapi = \PHP_SAPI): bool
    {
        if ($sapi !== 'cli-server') {
            return false;
        }

        $path = parse_url($requestUri ?? (string) ($_SERVER['REQUEST_URI'] ?? '/'), \PHP_URL_PATH);
        if (!\is_string($path) || $path === '' || $path === '/') {
            return false;
        }

        // realpath() throws on a NUL byte; such a request is simply not a file.
        $decoded = rawurldecode($path);
        if (str_contains($decoded, "\0")) {
            return false;
        }

        // The request path is checked as well as the resolved one: a symlink
        // can remove a denied segment during resolution, and the built-in
        // server sends the file by the path it was asked for.
        if (!self::segmentsAllowed(explode('/', ltrim($decoded, '/')))) {
            return false;
        }

        $root = realpath($staticDir);
        $file = realpath($root . $decoded);

        if ($root === false || $file === false || !is_file($file) || !str_starts_with($file, $root . \DIRECTORY_SEPARATOR)) {
            return false;
        }

        $segments = explode(\DIRECTORY_SEPARATOR, substr($file, \strlen($root) + 1));
        if (!self::segmentsAllowed($segments)) {
            return false;
        }

        $name = strtolower((string) end($segments));

        return !\in_array($name, self::DENIED_FILES, true)
            && \in_array(pathinfo($name, \PATHINFO_EXTENSION), self::STATIC_EXTENSIONS, true);
    }

    /**
     * A misconfiguration answered as plain text, and logged. Plain because
     * nothing that could render an error page is available yet.
     */
    private static function fail(string $message, ?string $logMessage = null): void
    {
        error_log('styleguide: ' . ($logMessage ?? $message));
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $message;
    }

    /**
     * @param list<string> $segments
     */
    private static function segmentsAllowed(array $segments): bool
    {
        foreach ($segments as $segment) {
            // Lowercased: on a case-insensitive filesystem /VENDOR/ is vendor/.
            if ($segment === '' || $segment[0] === '.' || \in_array(strtolower($segment), self::DENIED_DIRECTORIES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The kernel environment and debug flag for this request.
     *
     * Debug is OFF unless the environment asks for it. The front controller
     * ships inside a theme, so every deployed site serves it, and neither
     * WordPress nor Drupal sets `APP_ENV`. A debug default would show stack
     * traces and server paths to anyone who breaks a request.
     *
     * - `APP_ENV` wins when set.
     * - Otherwise DDEV counts as asking: it sets `IS_DDEV_PROJECT=true` in the
     *   web container, so local development needs no configuration.
     * - Otherwise `prod`.
     * - `APP_DEBUG=0` turns debug off in any environment; `prod` never has it.
     *
     * Each variable is read from `$_SERVER`, then `$_ENV`, then `getenv()`.
     * PHP's built-in server leaves process variables out of `$_SERVER`, and
     * `variables_order` usually leaves `$_ENV` empty.
     *
     * @param array<array-key, mixed>         $server
     * @param array<array-key, mixed>         $env
     * @param callable(string): (string|false) $getenv
     *
     * @return array{0: string, 1: bool}
     *
     * @internal public for tests
     */
    public static function resolveEnvironment(array $server, array $env, callable $getenv): array
    {
        $read = static function (string $name) use ($server, $env, $getenv): ?string {
            $value = $server[$name] ?? $env[$name] ?? $getenv($name);

            // Unset and empty both read as null; "0" stays "0".
            return \is_string($value) && $value !== '' ? $value : null;
        };

        $environment = $read('APP_ENV') ?? ($read('IS_DDEV_PROJECT') === 'true' ? 'dev' : 'prod');
        $debug = $environment !== 'prod' && $read('APP_DEBUG') !== '0';

        return [$environment, $debug];
    }
}
