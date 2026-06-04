<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class NamespacesTest extends TestCase
{
    private string $templatesPath;
    private string $missingYaml;

    protected function setUp(): void
    {
        $this->templatesPath = __DIR__ . '/fixtures/templates';
        // A path that doesn't exist on disk — Styleguide tolerates a missing
        // yaml (the constructor only reads it when the file is present), so
        // pointing at /dev/null here keeps the test free of fixture clutter.
        $this->missingYaml = __DIR__ . '/fixtures/nonexistent.yaml';
    }

    #[Test]
    public function auto_discovers_images_and_icons_under_static_path(): void
    {
        $twig = new Environment(new FilesystemLoader(), ['cache' => false]);

        new Styleguide([
            'templates_path' => $this->templatesPath,
            // Fixture lays out images/ and images/icons/ as siblings of
            // templates/, mirroring the convention every consuming project
            // ships with. Auto-discovery walks `static_path` for these.
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => $this->missingYaml,
            'twig' => $twig,
        ]);

        /** @var FilesystemLoader $loader */
        $loader = $twig->getLoader();
        $namespaces = $loader->getNamespaces();
        self::assertContains('icons', $namespaces);
        self::assertContains('images', $namespaces);
        self::assertSame([__DIR__ . '/fixtures/images/icons'], $loader->getPaths('icons'));
        self::assertSame([__DIR__ . '/fixtures/images'], $loader->getPaths('images'));
    }

    #[Test]
    public function auto_discovers_conventional_subdirs_under_templates_path(): void
    {
        $twig = new Environment(new FilesystemLoader(), ['cache' => false]);

        new Styleguide([
            'templates_path' => $this->templatesPath,
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => $this->missingYaml,
            'twig' => $twig,
        ]);

        /** @var FilesystemLoader $loader */
        $loader = $twig->getLoader();
        $namespaces = $loader->getNamespaces();

        // `component/` and `page/` exist under the fixture → both auto-registered.
        self::assertContains('component', $namespaces);
        self::assertSame(
            [$this->templatesPath . '/component'],
            $loader->getPaths('component'),
        );
        self::assertContains('page', $namespaces);
        self::assertSame(
            [$this->templatesPath . '/page'],
            $loader->getPaths('page'),
        );

        // @static always points at templates_path itself.
        self::assertContains('static', $namespaces);
        self::assertSame([$this->templatesPath], $loader->getPaths('static'));

        // No `macro/` directory in the fixture, so no namespace for it —
        // auto-discovery must skip missing dirs rather than fail loudly.
        self::assertNotContains('macro', $namespaces);
    }

    #[Test]
    public function auto_registered_namespace_resolves_templates(): void
    {
        $twig = new Environment(new FilesystemLoader(), ['cache' => false]);

        new Styleguide([
            'templates_path' => $this->templatesPath,
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => $this->missingYaml,
            'twig' => $twig,
        ]);

        // The whole point of auto-discovery: project templates that reach
        // for `@component/...` resolve without any manual addPath() call.
        $template = $twig->load('@component/sample/sample.twig');
        self::assertNotEmpty($template->render(['content' => ['title' => 'OK']]));
    }

    #[Test]
    public function namespaces_config_registers_paths_outside_templates_path(): void
    {
        $twig = new Environment(new FilesystemLoader(), ['cache' => false]);

        new Styleguide([
            'templates_path' => $this->templatesPath,
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => $this->missingYaml,
            'twig' => $twig,
            'namespaces' => [
                // Sibling of templates/ — mimics the typical `images/icons` layout.
                'fixtures' => __DIR__ . '/fixtures',
            ],
        ]);

        /** @var FilesystemLoader $loader */
        $loader = $twig->getLoader();
        self::assertContains('fixtures', $loader->getNamespaces());
        self::assertSame([__DIR__ . '/fixtures'], $loader->getPaths('fixtures'));
    }

    #[Test]
    public function namespaces_config_silently_skips_missing_paths(): void
    {
        $twig = new Environment(new FilesystemLoader(), ['cache' => false]);

        new Styleguide([
            'templates_path' => $this->templatesPath,
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => $this->missingYaml,
            'twig' => $twig,
            'namespaces' => [
                'ghost' => __DIR__ . '/this-path-does-not-exist',
            ],
        ]);

        // A typo in `namespaces` should be a no-op, not a crash — projects
        // edit this list often and shouldn't have the styleguide stop
        // rendering because one path is briefly missing.
        /** @var FilesystemLoader $loader */
        $loader = $twig->getLoader();
        self::assertNotContains('ghost', $loader->getNamespaces());
    }

    #[Test]
    public function registers_doc_namespace(): void
    {
        $twig = new Environment(new FilesystemLoader(), ['cache' => false]);

        new Styleguide([
            'templates_path' => $this->templatesPath,
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => $this->missingYaml,
            'twig' => $twig,
        ]);

        /** @var FilesystemLoader $loader */
        $loader = $twig->getLoader();
        self::assertContains('doc', $loader->getNamespaces());
        self::assertSame(
            [$this->templatesPath . '/doc'],
            $loader->getPaths('doc'),
        );
    }

    #[Test]
    public function repeated_construction_does_not_duplicate_paths(): void
    {
        $twig = new Environment(new FilesystemLoader(), ['cache' => false]);

        new Styleguide([
            'templates_path' => $this->templatesPath,
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => $this->missingYaml,
            'twig' => $twig,
        ]);
        new Styleguide([
            'templates_path' => $this->templatesPath,
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => $this->missingYaml,
            'twig' => $twig,
        ]);

        /** @var FilesystemLoader $loader */
        $loader = $twig->getLoader();
        // The second construction must detect the existing path and skip
        // re-adding it. Slow `realpath` comparison is on the construct path,
        // not on template resolve, so the cost is bounded.
        self::assertCount(1, $loader->getPaths('component'));
        self::assertCount(1, $loader->getPaths('static'));
    }
}
