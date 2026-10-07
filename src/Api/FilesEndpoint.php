<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Api;

use Parisek\Styleguide\Http\Result;
use Parisek\Styleguide\PathGuard;
use Parisek\Styleguide\TemplateRoots;

/**
 * @internal Implementation detail of `Styleguide::run()`. Consumer-facing
 *           contract is the HTTP URL (`/styleguide/api/files/<kind>/<slug>`)
 *           and its JSON response shape — see `docs/API.md` § JSON API endpoints.
 *
 * GET /styleguide/api/files/<kind>/<slug> — a component's own files, as
 * far as `source_views` lets: its Twig template `<slug>.twig` (`twig`), and
 * its stylesheets and scripts (`css/**\/*.css`, `js/**\/*.js` in its folder,
 * the layout tailwind-base components use; `css`, `js`). The Code panel shows them next to the
 * fixture and the HTML it renders, so the call, the template, the output
 * and the code behind it sit in one place. Tests (`*.test.js`, `*.spec.js`)
 * are left out.
 *
 * Built only when `show_source` is on, like SourceEndpoint. The CSS and JS
 * are what the build ships to every browser anyway, one step earlier. The
 * template is not: it is the implementation, which is why `source_views`
 * leaves it out unless a project lists it.
 */
final class FilesEndpoint
{
    private const KINDS = ['component', 'page', 'doc'];

    /** Per-folder limits: a stylesheet or script past these is not a component's own. */
    private const MAX_FILES = 20;
    private const MAX_BYTES = 200_000;

    /**
     * @param list<string> $views The `source_views` in effect: `twig`, `css`
     *                            and `js` decide which files are read.
     */
    private TemplateRoots $roots;

    /**
     * @param string|list<string>|TemplateRoots $templatesPath One root, or the ordered roots; an
     *        entry's files come from the root that owns the entry.
     * @param list<string> $views The `source_views` in effect: `twig`, `css`
     *                            and `js` decide which files are read.
     */
    public function __construct(string|array|TemplateRoots $templatesPath, private array $views = ['css', 'js'])
    {
        $this->roots = TemplateRoots::from($templatesPath);
    }

    public function handle(string $kind, string $slug): Result
    {
        if (!in_array($kind, self::KINDS, true) || preg_match('/^[A-Za-z0-9_-]+$/', $slug) !== 1) {
            return self::notFound();
        }

        $base = $kind . '/' . $slug;
        $root = $this->roots->isSingle() ? $this->roots->first() : $this->roots->ownerRoot($kind, $slug);
        if ($root === null || !is_dir(rtrim($root, '/') . '/' . $base)) {
            return self::notFound();
        }

        $files = [];
        if (in_array('twig', $this->views, true)) {
            $template = $this->read($root, $base . '/' . $slug . '.twig');
            if ($template !== null) {
                $files[] = ['path' => $base . '/' . $slug . '.twig', 'language' => 'twig', 'source' => $template];
            }
        }
        foreach (['css' => 'css', 'js' => 'js'] as $folder => $language) {
            if (!in_array($language, $this->views, true)) {
                continue;
            }
            foreach (self::walk($root, $base . '/' . $folder, $language) as $path) {
                if (count($files) >= self::MAX_FILES) {
                    break 2;
                }
                $source = $this->read($root, $path);
                if ($source !== null) {
                    $files[] = ['path' => $path, 'language' => $language, 'source' => $source];
                }
            }
        }

        return Result::json((string) json_encode(
            ['kind' => $kind, 'slug' => $slug, 'files' => $files],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * One file's text, or null. The same containment rule the renderer
     * uses: a symlink out of the templates folder is not a component file.
     */
    private function read(string $root, string $path): ?string
    {
        // One root only: a symlink from the project into the kit is refused.
        $real = $this->roots->isSingle()
            ? PathGuard::resolvePath($root, $path)
            : PathGuard::resolveInRoot([$root], 0, $path);
        if ($real === null || filesize($real) > self::MAX_BYTES) {
            return null;
        }
        $source = file_get_contents($real);

        return $source === false || !mb_check_encoding($source, 'UTF-8') ? null : $source;
    }

    /**
     * Paths relative to the templates folder, sorted, of every `.<ext>` file
     * under `$dir`, tests left out.
     *
     * @return list<string>
     */
    private static function walk(string $root, string $dir, string $ext): array
    {
        $absolute = rtrim($root, '/') . '/' . $dir;
        if (!is_dir($absolute)) {
            return [];
        }

        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $name = $file->getFilename();
            if (!$file->isFile() || $file->getExtension() !== $ext
                || preg_match('/\.(test|spec)\.js$/', $name) === 1) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(rtrim($root, '/')) + 1);
            $paths[] = str_replace('\\', '/', $relative);
        }
        sort($paths);

        return $paths;
    }

    private static function notFound(): Result
    {
        return Result::text(
            (string) json_encode(['error' => 'No files for this entry']),
            404,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }
}
