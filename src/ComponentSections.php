<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * `components.sections` in styleguide.yaml: the sidebar sections, in sidebar
 * order, and which entry takes which component.
 *
 * It replaces `components.group_by`; the two together throw. It sorts the
 * catalogue and nothing else: a component outside `components.include` is not
 * listed at all, and a `hidden` section only leaves the sidebar, the grid and
 * the search. The component still renders by its URL.
 *
 * An entry may declare `kind`, `category`, `component` and `fallback`. Every
 * declared field must match. A matching entry ranks by the most specific
 * field it declares:
 *
 *   4 `component`, 3 `kind` with `category`, 2 `category`, 1 `kind`.
 *
 * The earlier entry wins inside a tier. A component that no entry takes goes
 * to the `fallback` entry, or to the implicit last section `other`.
 */
final class ComponentSections
{
    /** Section id of a built-in `kind` entry; the SPA's locale holds its heading. */
    public const BUILTIN_IDS = [
        'element' => 'basic',
        'part' => 'parts',
        'block' => 'blocks',
        'section' => 'sections',
        'utility' => 'utilities',
    ];

    /** The id of the `fallback` entry that has none, and of the implicit last section. */
    public const OTHER = 'other';

    private const FIELDS = ['id', 'label', 'kind', 'category', 'component', 'hidden', 'fallback'];

    public const TIER_FALLBACK = 0;
    public const TIER_KIND = 1;
    public const TIER_CATEGORY = 2;
    public const TIER_KIND_CATEGORY = 3;
    public const TIER_COMPONENT = 4;

    /**
     * @param list<array{
     *     id: string,
     *     label: string|array<string,string>|null,
     *     kind: string|null,
     *     category: string|null,
     *     component: list<string>|null,
     *     hidden: bool,
     *     fallback: bool,
     * }> $entries
     */
    private function __construct(private readonly array $entries) {}

    /**
     * Reads the `components` map of styleguide.yaml.
     *
     * @return self|null null when the key is absent
     * @throws \InvalidArgumentException on any invalid entry, naming its index
     */
    public static function fromConfig(mixed $components): ?self
    {
        if (!is_array($components) || array_is_list($components) || !array_key_exists('sections', $components)) {
            return null;
        }
        if (($components['group_by'] ?? null) !== null) {
            throw new \InvalidArgumentException(
                'styleguide.yaml: `components.sections` replaces `components.group_by`; set only one of them',
            );
        }
        $sections = $components['sections'];
        if (!is_array($sections) || $sections === [] || !array_is_list($sections)) {
            throw new \InvalidArgumentException('styleguide.yaml: `components.sections` must be a non-empty list of entries');
        }

        $entries = [];
        $ids = [];
        $fallbacks = 0;
        foreach ($sections as $index => $raw) {
            $entry = self::entry($raw, $index);
            if (isset($ids[$entry['id']])) {
                throw new \InvalidArgumentException(sprintf(
                    'styleguide.yaml: `components.sections[%d]` repeats the id "%s" of `components.sections[%d]`; give it an `id`',
                    $index,
                    $entry['id'],
                    $ids[$entry['id']],
                ));
            }
            $ids[$entry['id']] = $index;
            if ($entry['fallback'] && ++$fallbacks > 1) {
                throw new \InvalidArgumentException(sprintf(
                    'styleguide.yaml: `components.sections[%d]` is a second `fallback` entry; only one is allowed',
                    $index,
                ));
            }
            $entries[] = $entry;
        }

        return new self($entries);
    }

    /**
     * @return array{id: string, label: string|array<string,string>|null, kind: string|null, category: string|null, component: list<string>|null, hidden: bool, fallback: bool}
     */
    private static function entry(mixed $raw, int $index): array
    {
        $where = sprintf('styleguide.yaml: `components.sections[%d]`', $index);
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new \InvalidArgumentException($where . ' must be a map');
        }
        foreach (array_keys($raw) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s has the unknown field "%s" (allowed: %s)',
                    $where,
                    (string) $field,
                    implode(', ', self::FIELDS),
                ));
            }
        }

        $kind = $raw['kind'] ?? null;
        if ($kind !== null && (!is_string($kind) || !in_array($kind, ComponentParser::KIND_VALUES, true))) {
            throw new \InvalidArgumentException(sprintf(
                '%s: `kind` must be one of %s',
                $where,
                implode(', ', ComponentParser::KIND_VALUES),
            ));
        }
        $category = $raw['category'] ?? null;
        if ($category !== null && (!is_string($category) || trim($category) === '')) {
            throw new \InvalidArgumentException($where . ': `category` must be a non-empty string');
        }
        $component = $raw['component'] ?? null;
        if ($component !== null) {
            if (!is_array($component) || !array_is_list($component) || $component === []) {
                throw new \InvalidArgumentException($where . ': `component` must be a non-empty list of component ids');
            }
            foreach ($component as $id) {
                if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
                    throw new \InvalidArgumentException($where . ': `component` must hold component ids (letters, digits, "_", "-")');
                }
            }
            $component = array_values(array_unique($component));
        }
        $hidden = $raw['hidden'] ?? false;
        $fallback = $raw['fallback'] ?? false;
        if (!is_bool($hidden)) {
            throw new \InvalidArgumentException($where . ': `hidden` must be true or false');
        }
        if (!is_bool($fallback)) {
            throw new \InvalidArgumentException($where . ': `fallback` must be true or false');
        }
        if ($fallback && ($kind !== null || $category !== null || $component !== null)) {
            throw new \InvalidArgumentException($where . ': `fallback` does not go with `kind`, `category` or `component`');
        }
        if (!$fallback && $kind === null && $category === null && $component === null) {
            throw new \InvalidArgumentException($where . ' needs one of `kind`, `category`, `component` or `fallback`');
        }

        $label = $raw['label'] ?? null;
        if ($label !== null) {
            if (is_string($label)) {
                if (trim($label) === '') {
                    throw new \InvalidArgumentException($where . ': `label` must not be empty');
                }
            } elseif (is_array($label) && $label !== [] && !array_is_list($label)) {
                foreach ($label as $language => $text) {
                    if (!is_string($language) || $language === '' || !is_string($text) || trim($text) === '') {
                        throw new \InvalidArgumentException($where . ': `label` must be a string or a map of language to non-empty string');
                    }
                }
            } else {
                throw new \InvalidArgumentException($where . ': `label` must be a string or a map of language to non-empty string');
            }
        }

        // Id: written, or derived. A bare `kind` entry derives the built-in id.
        $id = $raw['id'] ?? null;
        if ($id !== null) {
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9-]*$/', $id) !== 1) {
                throw new \InvalidArgumentException($where . ': `id` must match [a-z][a-z0-9-]*');
            }
        } elseif ($fallback) {
            $id = self::OTHER;
        } elseif ($kind !== null && $category === null && $component === null) {
            $id = self::BUILTIN_IDS[$kind];
        } else {
            throw new \InvalidArgumentException($where . ' needs an `id`: only a bare `kind` entry or a `fallback` entry derives one');
        }
        // A heading comes from the entry, or from the SPA locale for a built-in id.
        // A hidden section shows no heading, so it needs none.
        $builtin = in_array($id, self::BUILTIN_IDS, true) || $id === self::OTHER;
        if ($label === null && !$builtin && !$hidden) {
            throw new \InvalidArgumentException(sprintf('%s: the section "%s" needs a `label`', $where, $id));
        }

        return [
            'id' => $id,
            'label' => $label,
            'kind' => $kind,
            'category' => $category,
            'component' => $component,
            'hidden' => $hidden,
            'fallback' => $fallback,
        ];
    }

    /**
     * The section of one component: the winning entry and why.
     *
     * @param array{id?: mixed, kind?: mixed, category?: mixed} $component a parsed component
     * @return array{id: string, index: int|null, tier: int, hidden: bool} `index` is null for the implicit section
     */
    public function resolve(array $component): array
    {
        $id = (string) ($component['id'] ?? '');
        $kind = is_string($component['kind'] ?? null) ? $component['kind'] : '';
        $category = strtolower(trim(is_string($component['category'] ?? null) ? $component['category'] : ''));

        $best = null;
        $bestTier = self::TIER_FALLBACK;
        $fallback = null;
        foreach ($this->entries as $index => $entry) {
            if ($entry['fallback']) {
                $fallback = $index;
                continue;
            }
            if ($entry['kind'] !== null && $entry['kind'] !== $kind) {
                continue;
            }
            if ($entry['category'] !== null && strtolower(trim($entry['category'])) !== $category) {
                continue;
            }
            if ($entry['component'] !== null && !in_array($id, $entry['component'], true)) {
                continue;
            }
            $tier = match (true) {
                $entry['component'] !== null => self::TIER_COMPONENT,
                $entry['kind'] !== null && $entry['category'] !== null => self::TIER_KIND_CATEGORY,
                $entry['category'] !== null => self::TIER_CATEGORY,
                default => self::TIER_KIND,
            };
            // Strictly greater: the earlier entry wins inside a tier.
            if ($tier > $bestTier) {
                $best = $index;
                $bestTier = $tier;
            }
        }

        $index = $best ?? $fallback;
        if ($index === null) {
            return ['id' => self::OTHER, 'index' => null, 'tier' => self::TIER_FALLBACK, 'hidden' => false];
        }

        return [
            'id' => $this->entries[$index]['id'],
            'index' => $index,
            'tier' => $best === null ? self::TIER_FALLBACK : $bestTier,
            'hidden' => $this->entries[$index]['hidden'],
        ];
    }

    /**
     * The resolved list the SPA reads, in sidebar order. The implicit last
     * section `other` closes it unless a `fallback` entry exists. `label`
     * travels only when the entry wrote one; a built-in id keeps the SPA's
     * locale heading.
     *
     * @return list<array{id: string, label?: string|array<string,string>, hidden?: true, fallback?: true}>
     */
    public function payload(): array
    {
        $list = [];
        $hasFallback = false;
        foreach ($this->entries as $entry) {
            $item = ['id' => $entry['id']];
            if ($entry['label'] !== null) {
                $item['label'] = $entry['label'];
            }
            if ($entry['hidden']) {
                $item['hidden'] = true;
            }
            if ($entry['fallback']) {
                $item['fallback'] = true;
                $hasFallback = true;
            }
            $list[] = $item;
        }
        if (!$hasFallback) {
            $list[] = ['id' => self::OTHER, 'fallback' => true];
        }

        return $list;
    }

    /**
     * Fails when a `component` id is not a component with a template. A typo
     * must never leave a component in the wrong section without a word. The
     * same rule as `components.include`.
     *
     * @param list<array{id: string, hasTemplate: bool}> $directories {@see ComponentParser::listDirectories()}, unfiltered
     * @throws \InvalidArgumentException naming every unknown id
     */
    public function assertAllExist(array $directories): void
    {
        $known = [];
        foreach ($directories as $directory) {
            if ($directory['hasTemplate']) {
                $known[$directory['id']] = true;
            }
        }
        $unknown = [];
        foreach ($this->entries as $index => $entry) {
            foreach ($entry['component'] ?? [] as $id) {
                if (!isset($known[$id])) {
                    $unknown[] = sprintf('"%s" (`components.sections[%d]`)', $id, $index);
                }
            }
        }
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'styleguide.yaml: `components.sections` names %s that %s no component in templates_path: %s',
                count($unknown) === 1 ? 'an id' : 'ids',
                count($unknown) === 1 ? 'is' : 'are',
                implode(', ', $unknown),
            ));
        }
    }
}
