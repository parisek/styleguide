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
    /**
     * definition-kit roles that do not project into `acf.json`, and therefore
     * carry no editor label to display. The list is closed on purpose: a typo
     * (`filed`) or a role that was removed upstream (`computed`) must fall
     * through to the missing-label warning rather than silently pass an
     * unlabelled field. Anything outside this set — including `field`, an
     * empty string and a non-string — keeps the original behaviour.
     */
    private const NON_PROJECTING_ROLES = ['query', 'global', 'parent', 'inherited', 'derived'];

    /** Keys consumed by normalisation — everything else is verbatim. */
    private const CORE_KEYS = ['label', 'title', 'type', 'description', 'required', 'fields'];

    /**
     * Output-only structural keys. An authored field named e.g. `key` (ACF
     * exports a `key` attribute) or `children` would otherwise clobber the
     * canonical `key`/`children` computed below during verbatim pass-through.
     */
    private const RESERVED_OUTPUT_KEYS = ['key', 'children'];

    /**
     * @return array{fields: list<array<string,mixed>>, warnings: list<string>}
     */
    public static function normalize(mixed $fields, string $pathPrefix = ''): array
    {
        if ($fields === null || $fields === []) {
            return ['fields' => [], 'warnings' => []];
        }

        if (!is_array($fields)) {
            // ADR-0002 "never silently dropped": garbage on a `fields:` key
            // (top-level or nested) must surface a warning, unlike an
            // absent/empty key above which is legitimately silent.
            $message = $pathPrefix === ''
                ? 'fields: value is not a map — skipped'
                : sprintf('field "%s": nested fields value is not a map — skipped', $pathPrefix);

            return ['fields' => [], 'warnings' => [$message]];
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
                // A prop that does not project into the CMS has no editor
                // label to carry: definition-kit 0.6 made `label` required
                // only of a field that reaches acf.json, precisely so nobody
                // has to invent editor copy for a value no editor ever sees
                // (`role: parent`, `query`, `global`, `inherited`, `derived`).
                //
                // Skipping those dropped 115 real props out of one theme's
                // documentation the moment it declared them. The props table
                // is developer-facing, so the key is a perfectly good name for
                // one — falling back is better documentation than an omission,
                // and better than pushing invented labels back into the YAML.
                $role = $def['role'] ?? null;
                if (is_string($role) && in_array($role, self::NON_PROJECTING_ROLES, true)) {
                    $label = $key;
                } else {
                    $warnings[] = sprintf('field "%s": missing label/title — skipped', $path);
                    continue;
                }
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
                $k = (string) $k;
                if (!in_array($k, self::CORE_KEYS, true) && !in_array($k, self::RESERVED_OUTPUT_KEYS, true)) {
                    $field[$k] = $v;
                }
            }
            $out[] = $field;
        }

        return ['fields' => $out, 'warnings' => $warnings];
    }
}
