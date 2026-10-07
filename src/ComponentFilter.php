<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * `components.include` in styleguide.yaml: the component ids the catalogue lists.
 *
 * It filters the catalogue and nothing else. A component outside the list is not
 * listed and a direct request for it answers 404. Twig resolution is not touched:
 * a hidden component still renders when a listed component calls it, so a gap in
 * the host's list never breaks a page. The package does not scan dependencies;
 * the host writes the full list.
 *
 * An absent key means "no filter". A present key is always a filter, so an empty
 * list shows no components.
 */
final class ComponentFilter
{
    /** @param list<string> $ids */
    private function __construct(private readonly array $ids) {}

    /**
     * Reads the `components` map of styleguide.yaml.
     *
     * @return self|null null when the key is absent
     * @throws \InvalidArgumentException on a value that is not a list of component ids
     */
    public static function fromConfig(mixed $components): ?self
    {
        if (!is_array($components) || array_is_list($components) || !array_key_exists('include', $components)) {
            return null;
        }
        $include = $components['include'];
        if (!is_array($include) || !array_is_list($include)) {
            throw new \InvalidArgumentException('styleguide.yaml: `components.include` must be a list of component ids');
        }
        foreach ($include as $index => $id) {
            if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'styleguide.yaml: `components.include[%d]` must be a component id (letters, digits, "_", "-")',
                    $index,
                ));
            }
        }

        return new self(array_values(array_unique($include)));
    }

    public function allows(string $id): bool
    {
        return in_array($id, $this->ids, true);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return $this->ids;
    }

    /**
     * Fails when a listed id is not a component. A typo must never hide a
     * component without a word.
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
        $unknown = array_values(array_filter($this->ids, static fn(string $id): bool => !isset($known[$id])));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'styleguide.yaml: `components.include` names %s that %s no component in templates_path: %s',
                count($unknown) === 1 ? 'an id' : 'ids',
                count($unknown) === 1 ? 'is' : 'are',
                implode(', ', array_map(static fn(string $id): string => '"' . $id . '"', $unknown)),
            ));
        }
    }
}
