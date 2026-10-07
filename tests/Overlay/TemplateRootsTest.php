<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Overlay;

use Parisek\Styleguide\PathGuard;
use Parisek\Styleguide\Styleguide;
use Parisek\Styleguide\TemplateRoots;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class TemplateRootsTest extends OverlayTestCase
{
    #[Test]
    public function a_string_is_kept_as_it_is(): void
    {
        $dir = $this->tempDir();
        $link = $dir . '-link';
        symlink($dir, $link);
        try {
            $roots = TemplateRoots::from($link);
            self::assertSame([$link], $roots->all(), 'a string is not realpath-ed, as before');
            self::assertTrue($roots->isSingle());
            self::assertTrue($roots->isLegacyString());
        } finally {
            unlink($link);
        }
        // A string need not exist: parseAll() answers [] for it today.
        self::assertSame(['/no/such/dir'], TemplateRoots::from('/no/such/dir')->all());
    }

    #[Test]
    public function a_list_is_resolved_deduplicated_and_ordered(): void
    {
        $a = $this->tempDir();
        $b = $this->tempDir();
        $roots = TemplateRoots::from([$a, $b, $a . '/../' . basename($a)]);

        self::assertSame([$a, $b], $roots->all());
        self::assertFalse($roots->isSingle());
    }

    #[Test]
    public function an_error_names_the_index(): void
    {
        $a = $this->tempDir();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("config key 'templates_path[1]' is not an existing directory");
        TemplateRoots::from([$a, $a . '/missing']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badValues(): iterable
    {
        yield 'empty list' => [[]];
        yield 'a map' => [['a' => '/tmp']];
        yield 'an empty string' => [['/tmp', '']];
        yield 'an int' => [['/tmp', 3]];
        yield 'an empty string alone' => [''];
    }

    #[Test]
    #[DataProvider('badValues')]
    public function a_bad_value_is_refused(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TemplateRoots::from($value);
    }

    #[Test]
    public function the_first_root_with_the_template_owns_the_entry(): void
    {
        $project = $this->tempDir();
        $kit = $this->tempDir();
        self::put($project . '/component/a/a.twig', 'A');
        self::put($kit . '/component/a/a.twig', 'A');
        self::put($kit . '/component/b/b.twig', 'B');
        self::put($kit . '/component/c/styleguide.twig', 'no template');

        $roots = TemplateRoots::from([$project, $kit]);

        self::assertSame(0, $roots->ownerIndex('component', 'a'));
        self::assertSame(1, $roots->ownerIndex('component', 'b'));
        self::assertNull($roots->ownerIndex('component', 'c'), 'a folder without its template owns nothing');
        self::assertNull($roots->ownerIndex('component', '../a'));
        self::assertSame('component/b/b.twig', $roots->relative($kit . '/component/b/b.twig'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function lexicalCases(): iterable
    {
        yield 'plain' => ['component/x/x.twig', false];
        yield 'dot dot' => ['component/../x', true];
        yield 'leading dot dot' => ['../x', true];
        yield 'absolute' => ['/etc/passwd', true];
        yield 'nul byte' => ["component/x\0.twig", true];
        yield 'backslash' => ['component\\x', true];
        yield 'scheme' => ['data:text/plain,x', true];
        yield 'url' => ['https://example.com/x', true];
        yield 'drive letter' => ['C:/x', true];
        yield 'empty segment' => ['component//x', true];
        yield 'empty' => ['', true];
    }

    #[Test]
    #[DataProvider('lexicalCases')]
    public function the_lexical_guard_refuses_before_the_disk(string $path, bool $unsafe): void
    {
        self::assertSame($unsafe, PathGuard::isLexicallyUnsafe($path));
    }

    #[Test]
    public function a_symlink_from_one_root_into_another_is_refused(): void
    {
        $project = $this->tempDir();
        $kit = $this->tempDir();
        self::put($kit . '/component/card/card.twig', 'KIT');
        mkdir($project . '/component/card', 0777, true);
        symlink($kit . '/component/card/card.twig', $project . '/component/card/card.twig');
        self::put($project . '/component/card/own.css', 'own');

        $roots = [$project, $kit];

        self::assertNull(PathGuard::resolveInRoot($roots, 0, 'component/card/card.twig'), 'resolves into root 1');
        self::assertNotNull(PathGuard::resolveInRoot($roots, 1, 'component/card/card.twig'));
        self::assertNotNull(PathGuard::resolveInRoot($roots, 0, 'component/card/own.css'));
        self::assertNull(PathGuard::resolveInRoot($roots, 0, 'component/../../etc/passwd'));
        self::assertNull(PathGuard::resolveInRoot($roots, 5, 'component/card/own.css'));
        // The old methods keep their meaning.
        self::assertNotNull(PathGuard::resolvePath($project, 'component/card/own.css'));
    }

    #[Test]
    public function from_yaml_takes_a_list_relative_to_the_yaml(): void
    {
        $dir = $this->tempDir();
        self::put($dir . '/project/templates/component/a/a.twig', "{# name: A #}\nA");
        self::put($dir . '/kit/templates/component/b/b.twig', "{# name: B #}\nB");
        mkdir($dir . '/static');
        self::put($dir . '/project/styleguide.yaml', <<<'YAML'
            bootstrap:
              templates_path:
                - templates
                - ../kit/templates
              static_path: ../static
            YAML);

        $styleguide = Styleguide::fromYaml($dir . '/project/styleguide.yaml');

        self::assertEqualsCanonicalizing(['a', 'b'], self::componentIds($styleguide));
        $paths = $styleguide->diagnostics()['paths'];
        self::assertSame($dir . '/project/templates', $paths['templates_path[0]']);
        self::assertSame($dir . '/kit/templates', $paths['templates_path[1]']);
        self::assertArrayNotHasKey('templates_path', $paths);
    }

    #[Test]
    public function from_yaml_names_the_index_of_a_bad_entry(): void
    {
        $dir = $this->tempDir();
        self::put($dir . '/styleguide.yaml', "bootstrap:\n  templates_path: [templates, 3]\n  static_path: .\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bootstrap.templates_path[1]');
        Styleguide::fromYaml($dir . '/styleguide.yaml');
    }
}
