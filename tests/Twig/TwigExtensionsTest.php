<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Twig;

use Parisek\Styleguide\Styleguide;
use Parisek\Styleguide\Tests\Support\UrlExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Error\SyntaxError;

/**
 * The `twig_extensions` run-truth key: a host adds Twig extensions to the
 * package's own environment, without handing the environment over.
 */
final class TwigExtensionsTest extends TestCase
{
    private const YAML = __DIR__ . '/../fixtures/twig-extensions/styleguide.yaml';

    #[Test]
    public function a_template_calling_a_host_function_compiles_when_the_extension_is_given(): void
    {
        $sg = Styleguide::fromYaml(self::YAML, ['twig_extensions' => [new UrlExtension()]]);

        self::assertStringContainsString('href="/about"', $sg->renderTemplate('@component/linked/linked.twig'));
    }

    #[Test]
    public function an_extension_global_reaches_the_template(): void
    {
        $sg = Styleguide::fromYaml(self::YAML, ['twig_extensions' => [new UrlExtension()]]);

        self::assertStringContainsString('data-build="/build"', $sg->renderTemplate('@component/linked/linked.twig'));
    }

    #[Test]
    public function without_the_key_the_same_template_fails_and_names_the_function(): void
    {
        $sg = Styleguide::fromYaml(self::YAML);

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Unknown "url" function');
        $sg->renderTemplate('@component/linked/linked.twig');
    }

    #[Test]
    public function an_empty_list_changes_nothing(): void
    {
        $sg = Styleguide::fromYaml(self::YAML, ['twig_extensions' => []]);

        $this->expectException(SyntaxError::class);
        $sg->renderTemplate('@component/linked/linked.twig');
    }

    #[Test]
    public function an_object_that_is_not_an_extension_is_refused_at_boot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("config key 'twig_extensions' must be a list of Twig\\Extension\\ExtensionInterface objects");

        Styleguide::fromYaml(self::YAML, ['twig_extensions' => [new \stdClass()]]);
    }

    #[Test]
    public function a_service_id_string_is_refused_in_the_library(): void
    {
        // Ids belong to the Symfony bridge. The library takes objects only.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('twig_extensions');

        Styleguide::fromYaml(self::YAML, ['twig_extensions' => ['app.url']]);
    }

    #[Test]
    public function the_yaml_cannot_set_the_key(): void
    {
        $dir = sys_get_temp_dir() . '/sg-twig-ext-' . bin2hex(random_bytes(4));
        mkdir($dir . '/templates', 0o777, true);
        file_put_contents($dir . '/styleguide.yaml', "bootstrap:\n  templates_path: templates\n  static_path: .\n  twig_extensions: [x]\n");

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('twig_extensions');
            Styleguide::fromYaml($dir . '/styleguide.yaml');
        } finally {
            @unlink($dir . '/styleguide.yaml');
            @rmdir($dir . '/templates');
            @rmdir($dir);
        }
    }

    #[Test]
    public function the_key_cannot_be_combined_with_a_host_environment(): void
    {
        // The package never mutates an environment it does not own.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'twig_extensions' cannot be combined with 'twig'");

        Styleguide::fromYaml(self::YAML, [
            'twig' => new \Twig\Environment(new \Twig\Loader\ArrayLoader()),
            'twig_extensions' => [new UrlExtension()],
        ]);
    }
}
