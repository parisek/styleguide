<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * @internal The ordered template roots behind `bootstrap.templates_path`.
 *
 * The key takes one string (today's shape) or a list of strings. A list is
 * an overlay: the first root is the strongest (a project), the last is the
 * weakest (a shared kit).
 *
 * Two rules decide which root answers:
 *
 *  - Catalogue files follow ENTRY-LEVEL OWNERSHIP. The first root that holds
 *    an entry marker of `<kind>/<id>/` (`<id>.twig`, `<id>.yaml` or
 *    `styleguide.twig`, see {@see self::entryMarkers()}) owns the whole
 *    folder: its metadata yaml, its fixtures, its data sidecars, its css and
 *    js. No file is ever taken from a second root for the same entry.
 *  - Twig namespaces (`@component`, `@page`, `@macro`, `@doc`, `@static`,
 *    `@project`) search the roots in order, so a template that is not in the
 *    project is found in the kit.
 *
 * A plain string keeps today's behaviour byte for byte: it is not resolved
 * with realpath() and it is not checked for existence. Only a list is
 * normalised (realpath, existing, deduplicated), and its error messages name
 * the index of the bad entry. The form decides, not the root count: a list of
 * one root, or of duplicates that collapse to one, is still a list and still
 * enforces owner-root containment.
 */
final class TemplateRoots
{
    /** @param list<string> $roots */
    private function __construct(private readonly array $roots, private readonly bool $legacy) {}

    /**
     * @param string|list<string>|self $value
     * @throws \InvalidArgumentException
     */
    public static function from(string|array|self $value, string $key = 'templates_path'): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (is_string($value)) {
            if ($value === '') {
                throw new \InvalidArgumentException("Styleguide: config key '{$key}' must not be an empty string");
            }

            return new self([$value], true);
        }
        if ($value === [] || !array_is_list($value)) {
            throw new \InvalidArgumentException(
                "Styleguide: config key '{$key}' must be a string or a non-empty list of strings",
            );
        }

        $roots = [];
        foreach ($value as $index => $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \InvalidArgumentException(sprintf(
                    "Styleguide: config key '%s[%d]' must be a non-empty string, got %s",
                    $key,
                    $index,
                    get_debug_type($entry),
                ));
            }
            if (str_contains($entry, "\0")) {
                throw new \InvalidArgumentException("Styleguide: config key '{$key}[{$index}]' contains a NUL byte");
            }
            $real = realpath($entry);
            if ($real === false || !is_dir($real)) {
                throw new \InvalidArgumentException(sprintf(
                    "Styleguide: config key '%s[%d]' is not an existing directory: %s",
                    $key,
                    $index,
                    $entry,
                ));
            }
            if (!in_array($real, $roots, true)) {
                $roots[] = $real;
            }
        }

        return new self($roots, false);
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->roots;
    }

    /** The strongest root. */
    public function first(): string
    {
        return $this->roots[0];
    }

    /**
     * True only for the legacy scalar form. A list is never single, even
     * with one element or with duplicates that collapse to one root: it
     * enforces owner-root containment.
     */
    public function isSingle(): bool
    {
        return $this->legacy;
    }

    public function isLegacyString(): bool
    {
        return $this->legacy;
    }

    /**
     * The file names that make `<kind>/<id>/` an entry: the template, the
     * metadata yaml and the default fixture. One of them is enough (ADR-0009).
     *
     * A folder whose name starts with `_` is a partial. Only its template
     * counts, as before ADR-0009: the two new markers never make a partial an
     * entry.
     *
     * @return list<string>
     */
    public static function entryMarkers(string $id): array
    {
        if (str_starts_with($id, '_')) {
            return [$id . '.twig'];
        }

        return [$id . '.twig', $id . '.yaml', 'styleguide.twig'];
    }

    /**
     * Index of the root that owns `<kind>/<id>/`: the first one holding an
     * entry marker (see {@see self::entryMarkers()}) as a file that stays
     * inside that root. Null when no root does.
     */
    public function ownerIndex(string $kind, string $id): ?int
    {
        if (PathGuard::isLexicallyUnsafe($kind . '/' . $id)) {
            return null;
        }
        foreach ($this->roots as $index => $root) {
            // Containment per root: a marker that is a symlink into another
            // root does not make this root the owner. PathGuard refuses it.
            foreach (self::entryMarkers($id) as $marker) {
                if (PathGuard::resolveInRoot($this->roots, $index, $kind . '/' . $id . '/' . $marker) !== null) {
                    return $index;
                }
            }
        }

        return null;
    }

    /** The owning root's path, or null. */
    public function ownerRoot(string $kind, string $id): ?string
    {
        $index = $this->ownerIndex($kind, $id);

        return $index === null ? null : $this->roots[$index];
    }

    /** Index of the root that contains `$absolute`, longest root first. */
    public function indexOfPath(string $absolute): ?int
    {
        $best = null;
        $bestLength = -1;
        foreach ($this->roots as $index => $root) {
            $prefix = rtrim($root, '/') . '/';
            if (str_starts_with($absolute, $prefix) && strlen($prefix) > $bestLength) {
                $best = $index;
                $bestLength = strlen($prefix);
            }
        }

        return $best;
    }

    /** `$absolute` relative to the root that contains it, or unchanged. */
    public function relative(string $absolute): string
    {
        $index = $this->indexOfPath($absolute);

        return $index === null ? $absolute : substr($absolute, strlen(rtrim($this->roots[$index], '/')) + 1);
    }

    /** How a message names root `$index`: the config key with its index. */
    public static function label(int $index): string
    {
        return 'templates_path[' . $index . ']';
    }

    /** Name of the Twig namespace that holds root `$index` alone. */
    public static function rootNamespace(int $index): string
    {
        return 'styleguide_root_' . $index;
    }
}
