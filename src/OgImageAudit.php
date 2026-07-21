<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * @internal Server-side Open Graph image audit backing the `#og-image`
 * foundations section (#74).
 *
 * Reads the yaml `og_image:` key plus the consuming project's
 * `static_path` and reports: whether it's configured, whether the file
 * exists, real pixel dimensions (`getimagesize()`), file size in bytes,
 * and a status/notes pair the template renders verbatim (escaped).
 *
 * Checked, independently of one another:
 *  - dimensions: ≥ 1200×630 is the recommended minimum (`ok`); ≥ 600×315
 *    still renders acceptably on most platforms (`warning`); smaller is
 *    `error`.
 *  - aspect ratio: 1.91:1 ± 0.05 is the Open Graph convention every
 *    platform crops to — a deviation adds a note without changing status
 *    (a correctly-sized-but-square image still renders, just cropped).
 *  - file size: > 1 MB is a `warning` (crawlers may skip large assets on
 *    a slow fetch); > 8 MB is an `error` (Facebook's hard limit — the
 *    image is outright rejected, not merely cropped/resized).
 *
 * Overall `status` is the most severe of the dimension and file-size
 * checks; the aspect-ratio check only ever contributes a note.
 *
 * Every filesystem read (`getimagesize`/`filesize`) is routed through
 * {@see PathGuard::resolvePath()} — same containment/scheme-rejection
 * hardening as {@see FaviconAudit} (#73), promoted to a shared class when
 * this audit needed the identical behaviour rather than duplicating it.
 *
 * Pure, static, no I/O beyond reading the one file it's told about — no
 * network, no image decoding beyond what `getimagesize()` already does.
 *
 * @phpstan-type OgImageResult array{configured: bool, path: string, exists: bool, width: int|null, height: int|null, filesize: int|null, status: string, notes: list<string>}
 */
final class OgImageAudit
{
    private const RECOMMENDED_WIDTH = 1200;

    private const RECOMMENDED_HEIGHT = 630;

    private const MINIMUM_WIDTH = 600;

    private const MINIMUM_HEIGHT = 315;

    private const ASPECT_TARGET = 1.91;

    private const ASPECT_TOLERANCE = 0.05;

    private const FILESIZE_WARN_BYTES = 1_000_000;

    private const FILESIZE_ERROR_BYTES = 8_000_000;

    private const PATH_ESCAPES_NOTE = 'path escapes the static root';

    private const EXTERNAL_URL_NOTE = 'external URLs are not allowed';

    private const BELOW_RECOMMENDED_NOTE = 'below recommended 1200×630';

    private const BELOW_MINIMUM_NOTE = 'below minimum 600×315';

    private const UNREADABLE_IMAGE_NOTE = 'file is not a readable image';

    private const FILESIZE_WARN_NOTE = 'large file — social crawlers may skip it';

    private const FILESIZE_ERROR_NOTE = 'file exceeds 8 MB — Facebook will reject it';

    /**
     * @param string $assetBase
     *   The consumer's asset base (`twig_context.templateUrl`). A yaml value
     *   written as a full browser URL (`/wp-content/themes/acme/static/
     *   images/og-image.png`) is stripped back to static-root-relative
     *   before any disk read; see {@see PathGuard::stripAssetBase()}. Empty
     *   (the default, and the standalone consumer) makes the strip a no-op.
     *
     * @return OgImageResult
     */
    public static function run(string $staticPath, mixed $ogImage, string $assetBase = ''): array
    {
        $staticPath = rtrim($staticPath, '/');

        $path = self::stringConfig($ogImage);
        if ($path === null) {
            return self::result(false, '', false, null, null, null, 'unconfigured', []);
        }

        if (PathGuard::isExternalUrl($path)) {
            return self::result(true, $path, false, null, null, null, 'error', [self::EXTERNAL_URL_NOTE]);
        }

        // Authored as a full browser URL? Strip the base back off for the
        // filesystem side — otherwise the file audits as missing while
        // sitting right there under `static_path`. Kept in a SEPARATE
        // variable: the returned `path` is the value the author wrote (the
        // audit table echoes it, and `Renderer` derives the browser-facing
        // `url` twin from it), so mutating it here would silently rewrite
        // the yaml back at whoever wrote it — and hand `Renderer` a value
        // one rebase removed from what it expects.
        $diskPath = PathGuard::stripAssetBase($path, $assetBase);

        if (PathGuard::pathEscapesRoot($staticPath, $diskPath)) {
            return self::result(true, $path, false, null, null, null, 'error', [self::PATH_ESCAPES_NOTE]);
        }

        // Proven internal and contained past this point — normalize to a
        // root-relative web path (leading `/`) so the template can render
        // `path` verbatim into an `<img src>` regardless of how the yaml
        // value was written (`og_image: images/x.png` vs `/images/x.png`).
        $path = PathGuard::toWebPath($path);

        $absolute = PathGuard::resolvePath($staticPath, $diskPath);
        if ($absolute === null) {
            return self::result(true, $path, false, null, null, null, 'missing', []);
        }

        $filesize = filesize($absolute);
        $filesize = $filesize === false ? null : $filesize;

        $size = @getimagesize($absolute);
        if ($size === false) {
            return self::result(true, $path, true, null, null, $filesize, 'error', [self::UNREADABLE_IMAGE_NOTE]);
        }

        $width = (int) $size[0];
        $height = (int) $size[1];

        [$dimensionStatus, $dimensionNote] = self::checkDimensions($width, $height);
        [$filesizeStatus, $filesizeNote] = self::checkFilesize($filesize);
        $aspectNote = self::checkAspect($width, $height);

        $notes = array_values(array_filter([$dimensionNote, $filesizeNote, $aspectNote]));
        $status = self::worstStatus($dimensionStatus, $filesizeStatus);

        return self::result(true, $path, true, $width, $height, $filesize, $status, $notes);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private static function checkDimensions(int $width, int $height): array
    {
        if ($width >= self::RECOMMENDED_WIDTH && $height >= self::RECOMMENDED_HEIGHT) {
            return ['ok', null];
        }

        if ($width >= self::MINIMUM_WIDTH && $height >= self::MINIMUM_HEIGHT) {
            return ['warning', self::BELOW_RECOMMENDED_NOTE];
        }

        return ['error', self::BELOW_MINIMUM_NOTE];
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private static function checkFilesize(?int $filesize): array
    {
        if ($filesize === null) {
            return ['ok', null];
        }

        if ($filesize > self::FILESIZE_ERROR_BYTES) {
            return ['error', self::FILESIZE_ERROR_NOTE];
        }

        if ($filesize > self::FILESIZE_WARN_BYTES) {
            return ['warning', self::FILESIZE_WARN_NOTE];
        }

        return ['ok', null];
    }

    /**
     * Reduces `$width:$height` to its lowest terms via `gcd()` and compares
     * against the 1.91:1 Open Graph convention within {@see
     * self::ASPECT_TOLERANCE}. Returns null (no note) when within
     * tolerance — this check never changes `status`, it only ever adds an
     * informational note, since a correctly-sized-but-off-ratio image
     * still renders, just cropped by the consuming platform.
     */
    private static function checkAspect(int $width, int $height): ?string
    {
        if ($height === 0) {
            return null;
        }

        $ratio = $width / $height;
        if (abs($ratio - self::ASPECT_TARGET) <= self::ASPECT_TOLERANCE) {
            return null;
        }

        $divisor = self::gcd($width, $height);
        $reducedWidth = intdiv($width, $divisor);
        $reducedHeight = intdiv($height, $divisor);

        return "aspect ratio {$reducedWidth}:{$reducedHeight} differs from 1.91:1 — platforms will crop";
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a === 0 ? 1 : abs($a);
    }

    /**
     * Severity order ok < warning < error — returns whichever of the two
     * per-check statuses is more severe, since dimension and file-size
     * problems are independent failure modes that both gate the overall
     * `status` the template badges.
     */
    private static function worstStatus(string $a, string $b): string
    {
        $rank = ['ok' => 0, 'warning' => 1, 'error' => 2];

        return $rank[$a] >= $rank[$b] ? $a : $b;
    }

    /**
     * @param list<string> $notes
     *
     * @return OgImageResult
     */
    private static function result(
        bool $configured,
        string $path,
        bool $exists,
        ?int $width,
        ?int $height,
        ?int $filesize,
        string $status,
        array $notes,
    ): array {
        return [
            'configured' => $configured,
            'path' => $path,
            'exists' => $exists,
            'width' => $width,
            'height' => $height,
            'filesize' => $filesize,
            'status' => $status,
            'notes' => $notes,
        ];
    }

    private static function stringConfig(mixed $ogImage): ?string
    {
        if (!is_string($ogImage) || $ogImage === '') {
            return null;
        }

        return $ogImage;
    }
}
