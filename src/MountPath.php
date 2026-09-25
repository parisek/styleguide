<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * The URL path the catalogue is mounted at, and the one place that decides
 * what a valid one looks like.
 *
 * The runtime serves at {@see self::DEFAULT} only, for now: the built SPA,
 * the Symfony routes and parts of the PHP still assume it. Everything that
 * produces or recognises a catalogue URL reads the mount from one value, so
 * unlocking `bootstrap.base_url` later changes where that value comes from,
 * not every call site. Validation already lives here so that `doctor` and,
 * later, the runtime refuse exactly the same values.
 *
 * @internal
 */
final class MountPath
{
    public const DEFAULT = '/styleguide';

    /**
     * One path segment. Conservative on purpose: no percent-encoding (servers
     * decode `%2F` differently, which moves the routing boundary), no
     * non-ASCII, nothing that needs escaping in HTML, JSON or a cookie path.
     */
    private const SEGMENT = '/^[A-Za-z0-9_~-][A-Za-z0-9._~-]*$/';

    /**
     * The canonical form of a configured mount: one leading slash, no
     * trailing slash, e.g. `/tools/ui/` → `/tools/ui`.
     *
     * @throws \InvalidArgumentException with the reason, for anything that is
     *         not an absolute, plain, multi-segment-capable path; for `/`,
     *         which would claim every URL of the host application; and for
     *         dot-segments, empty segments, query, fragment or encoding
     */
    public static function normalise(mixed $value): string
    {
        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException(sprintf(
                'The mount path must be a non-empty string, got %s.',
                get_debug_type($value),
            ));
        }

        if ($value[0] !== '/') {
            throw new \InvalidArgumentException(sprintf(
                'The mount path must start with "/", got "%s".',
                $value,
            ));
        }

        $trimmed = rtrim($value, '/');
        if ($trimmed === '') {
            throw new \InvalidArgumentException(
                'The mount path cannot be "/": the catalogue would claim every URL of the host application.',
            );
        }

        foreach (explode('/', substr($trimmed, 1)) as $segment) {
            if (preg_match(self::SEGMENT, $segment) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'The mount path "%s" has an invalid segment "%s". Use letters, digits, "-", "_", "~" '
                        . 'and ".", with no empty or dot-only segments, no percent-encoding, query or fragment.',
                    $value,
                    $segment,
                ));
            }
        }

        return $trimmed;
    }

    /**
     * The path below the mount, without a leading slash, when `$path` is the
     * mount or lies under it; null otherwise. `/styleguides` is not under
     * `/styleguide`.
     */
    public static function relative(string $path, string $mount): ?string
    {
        if ($path === $mount) {
            return '';
        }

        return str_starts_with($path, $mount . '/') ? substr($path, \strlen($mount) + 1) : null;
    }
}
