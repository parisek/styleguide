<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Overlay;

use Parisek\Styleguide\ComponentParser;
use Parisek\Styleguide\Http\Request;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Loader\FilesystemLoader;

/**
 * Legacy single-root suite. It pins what a plain string `templates_path`
 * does today: the order of parseAll(), the source_file values, the warnings,
 * the loader paths and three API bodies. It was written and recorded BEFORE
 * the multi-root change and must stay green after it.
 *
 * Set UPDATE_LEGACY_SNAPSHOT=1 to record the snapshot again (only on code
 * that is known to be the legacy behaviour).
 */
final class LegacySingleRootTest extends TestCase
{
    private const SNAPSHOT = __DIR__ . '/../fixtures/legacy-single-root.snapshot.json';

    /**
     * @return array<string, mixed>
     */
    private static function capture(): array
    {
        $fixtures = realpath(__DIR__ . '/../fixtures');
        $templates = $fixtures . '/templates';

        $parser = new ComponentParser($templates);
        $parsed = [];
        foreach (['component', 'page', 'doc'] as $type) {
            $parsed[$type] = array_map(
                static fn(array $item): array => [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'fields_md5' => md5((string) json_encode($item['fields'])),
                    'fields_files' => array_values(array_unique(array_filter(array_column($item['fields'], 'file')))),
                    'has_styleguide' => $item['has_styleguide'] ?? null,
                    'variants' => array_column($item['variants'] ?? [], 'id'),
                ],
                $parser->parseAll($type),
            );
        }
        $single = $parser->parse('component', 'multi');
        $warned = $parser->parse('component', 'with-fields');

        $styleguide = new Styleguide([
            'templates_path' => $templates,
            'static_path' => $fixtures,
            'config_yaml' => $fixtures . '/styleguide.yaml',
            'show_source' => true,
        ]);
        $reflection = new \ReflectionProperty($styleguide, 'twig');
        /** @var \Twig\Environment $twig */
        $twig = $reflection->getValue($styleguide);
        /** @var FilesystemLoader $loader */
        $loader = $twig->getLoader();
        $paths = [];
        foreach (['project', 'component', 'macro', 'page', 'doc', 'static'] as $ns) {
            $paths[$ns] = array_map(
                static fn(string $p): string => substr($p, strlen($fixtures)),
                $loader->getPaths($ns),
            );
        }

        $api = [];
        foreach (['/styleguide/api/components', '/styleguide/api/pages', '/styleguide/api/docs'] as $uri) {
            $result = $styleguide->handle(new Request($uri));
            $api[$uri] = [$result?->status, md5((string) $result?->body)];
        }
        foreach ([
            '/styleguide/api/source/component/multi',
            '/styleguide/api/files/component/multi',
            '/styleguide/render/component/multi',
        ] as $uri) {
            $result = $styleguide->handle(new Request($uri));
            $api[$uri] = [$result?->status, md5((string) $result?->body)];
        }

        // Warnings and field-warning file names, which carry the root-relative path.
        $trouble = [];
        foreach (['broken-metadata-templates', 'broken-fields-templates', 'yaml-priority-templates', 'nested'] as $dir) {
            $p = new ComponentParser($fixtures . '/' . $dir);
            $items = [];
            foreach (['component', 'page', 'doc'] as $type) {
                foreach ($p->parseAll($type) as $item) {
                    $items[] = [
                        $type . '/' . $item['id'],
                        array_values(array_unique(array_filter(array_column($item['fields'], 'file')))),
                    ];
                }
            }
            $trouble[$dir] = ['items' => $items, 'warnings' => $p->getWarnings()];
        }

        return [
            'trouble' => $trouble,
            'parsed' => $parsed,
            'single_parse' => md5((string) json_encode($single)),
            'with_fields_files' => array_values(array_unique(array_filter(array_column($warned['fields'] ?? [], 'file')))),
            'warnings' => $parser->getWarnings(),
            'directories' => $parser->listDirectories('component'),
            'loader_paths' => $paths,
            'diagnostics_templates_path' => substr($styleguide->diagnostics()['paths']['templates_path'], strlen($fixtures)),
            'api' => $api,
        ];
    }

    #[Test]
    public function the_string_templates_path_behaves_as_recorded(): void
    {
        $current = self::capture();

        if (getenv('UPDATE_LEGACY_SNAPSHOT') === '1') {
            file_put_contents(self::SNAPSHOT, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }

        $expected = json_decode((string) file_get_contents(self::SNAPSHOT), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expected, json_decode((string) json_encode($current), true));
    }

    #[Test]
    public function the_snapshot_is_not_empty(): void
    {
        $expected = json_decode((string) file_get_contents(self::SNAPSHOT), true, flags: JSON_THROW_ON_ERROR);
        self::assertGreaterThan(10, count($expected['parsed']['component']));
        self::assertNotSame([], $expected['trouble']['broken-metadata-templates']['warnings']);
        self::assertSame(200, $expected['api']['/styleguide/render/component/multi'][0]);
    }
}
