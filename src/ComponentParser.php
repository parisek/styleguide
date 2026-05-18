<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Parses styleguide metadata from Twig components & pages in the project's templates/ directory.
 *
 * Reads the first {# ... #} comment in each `.twig` file, parses it as YAML, and produces
 * a normalised array of component/page metadata: name, category, description, fields, …
 *
 * Tabs in YAML are auto-converted to 4 spaces — Twig editors insert tabs, YAML rejects them.
 */
final class ComponentParser
{
    private string $templatesPath;

    public function __construct(string $templatesPath)
    {
        $this->templatesPath = rtrim($templatesPath, '/');
    }

    /**
     * Parse metadata from a single component/page .twig file.
     *
     * @return array<string,mixed>|null  Null when file missing or metadata invalid.
     */
    public function parse(string $type, string $id): ?array
    {
        $dir = $this->templatesPath . '/' . $type . '/' . $id;
        $file = $dir . '/' . $id . '.twig';

        if (!file_exists($file)) {
            return null;
        }

        $content = (string) file_get_contents($file);
        $metadata = $this->parseTwigComment($content);

        if (!$metadata || !isset($metadata['name'])) {
            return null;
        }

        $hasStyleguide = file_exists($dir . '/styleguide.twig')
            || isset($metadata['styleguide']);

        return $this->normaliseMetadata($id, $metadata, $hasStyleguide);
    }

    /**
     * Parse all components/pages of a given type (recursive scan of templates/<type>/).
     *
     * @return array<int,array<string,mixed>>
     */
    public function parseAll(string $type): array
    {
        $dir = $this->templatesPath . '/' . $type;
        if (!is_dir($dir)) {
            return [];
        }

        $items = [];
        $iterator = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $flattened = new \RecursiveIteratorIterator($iterator);
        $regex = new \RegexIterator($flattened, '/\.twig$/');

        foreach ($regex as $file) {
            if ($file->getFilename() === 'styleguide.twig') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            $metadata = $this->parseTwigComment($content);

            if (!$metadata || !isset($metadata['name'])) {
                continue;
            }

            $id = $file->getBasename('.twig');
            $hasStyleguide = file_exists($file->getPath() . '/styleguide.twig')
                || isset($metadata['styleguide']);

            $items[] = $this->normaliseMetadata($id, $metadata, $hasStyleguide);
        }

        usort($items, function ($a, $b) {
            if ($a['weight'] === $b['weight']) {
                if (class_exists(\Collator::class)) {
                    $collator = new \Collator('cs');
                    return $collator->compare($a['name'], $b['name']);
                }
                return strcmp($a['name'], $b['name']);
            }
            return $a['weight'] - $b['weight'];
        });

        return $items;
    }

    /**
     * Extract YAML metadata from the first {# ... #} comment in a Twig file.
     *
     * @return array<string,mixed>|false
     */
    public function parseTwigComment(string $content): array|false
    {
        $content = str_replace("\r", "\n", $content);

        if (preg_match("/{#\s*(.*?)\s*#}/s", $content, $match) && $match[1]) {
            try {
                $yaml = str_replace("\t", '    ', $match[1]);
                $parsed = Yaml::parse($yaml);
                return is_array($parsed) ? $parsed : false;
            } catch (ParseException) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function normaliseMetadata(string $id, array $metadata, bool $hasStyleguide): array
    {
        return [
            'id' => $id,
            'name' => $metadata['name'],
            'category' => $metadata['category'] ?? '',
            'description' => $metadata['description'] ?? '',
            'asana' => $metadata['asana'] ?? '',
            'figma' => $metadata['figma'] ?? '',
            'drupal' => $metadata['drupal'] ?? '',
            'web' => $metadata['web'] ?? '',
            'weight' => isset($metadata['weight']) ? (int) $metadata['weight'] : 50,
            'usage' => $metadata['usage'] ?? '',
            'fields' => $metadata['fields'] ?? [],
            'hasStyleguide' => $hasStyleguide,
        ];
    }
}
