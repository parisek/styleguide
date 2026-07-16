<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * @internal Normalises a `fields:` map (either doctrine: legacy twig
 *           annotation with `title`, or definition-kit `<id>.yaml` with
 *           `label`) into the canonical ordered list documented in
 *           docs/API.md § Fields. Core keys are normalised; every other
 *           authored key passes through verbatim (open contract — ADR-0002).
 */
final class FieldsNormalizer
{
    /** Keys consumed by normalisation — everything else is verbatim. */
    private const CORE_KEYS = ['label', 'title', 'type', 'description', 'required', 'fields'];

    /**
     * @return array{fields: list<array<string,mixed>>, warnings: list<string>}
     */
    public static function normalize(mixed $fields, string $pathPrefix = ''): array
    {
        if (!is_array($fields) || $fields === []) {
            return ['fields' => [], 'warnings' => []];
        }

        $out = [];
        $warnings = [];
        foreach ($fields as $key => $def) {
            $key = (string) $key;
            $path = $pathPrefix === '' ? $key : $pathPrefix . '.' . $key;
            if (!is_array($def) || array_is_list($def)) {
                $warnings[] = sprintf('field "%s": definition is not a map — skipped', $path);
                continue;
            }
            $label = $def['label'] ?? $def['title'] ?? null;
            if (!is_string($label) || $label === '') {
                $warnings[] = sprintf('field "%s": missing label/title — skipped', $path);
                continue;
            }

            $children = null;
            if (array_key_exists('fields', $def)) {
                $nested = self::normalize($def['fields'], $path);
                $children = $nested['fields'];
                $warnings = [...$warnings, ...$nested['warnings']];
            }

            $field = [
                'key' => $key,
                'label' => $label,
                'type' => is_string($def['type'] ?? null) ? $def['type'] : '',
                'description' => is_string($def['description'] ?? null) ? $def['description'] : '',
                'required' => (bool) ($def['required'] ?? false),
                'children' => $children,
            ];
            foreach ($def as $k => $v) {
                if (!in_array((string) $k, self::CORE_KEYS, true)) {
                    $field[(string) $k] = $v;
                }
            }
            $out[] = $field;
        }

        return ['fields' => $out, 'warnings' => $warnings];
    }
}
