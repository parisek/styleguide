<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Overlay;

use Parisek\Styleguide\ComponentParser;
use Parisek\Styleguide\TemplateRoots;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Only a scalar `templates_path` keeps the legacy behaviour. A list, even one
 * that holds a single root after deduplication, enforces owner-root
 * containment (ADR-0008).
 */
final class ListIsNotLegacyTest extends OverlayTestCase
{
    private const SECRET = 'SECRET-FROM-OTHER-ROOT';

    private string $root1;
    private string $static;

    protected function setUp(): void
    {
        $this->root1 = $this->tempDir();
        $outside = $this->tempDir();
        $this->static = $this->tempDir();
        self::put($outside . '/component/card/card.twig', "{# name: Leaked #}\n" . self::SECRET);
        mkdir($this->root1 . '/component/card', 0777, true);
        symlink($outside . '/component/card/card.twig', $this->root1 . '/component/card/card.twig');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function listForms(): iterable
    {
        yield 'one element' => ['one'];
        yield 'duplicates' => ['twice'];
    }

    /**
     * @return list<string>
     */
    private function asList(string $form): array
    {
        return $form === 'one' ? [$this->root1] : [$this->root1, $this->root1];
    }

    #[Test]
    public function only_a_scalar_is_single(): void
    {
        $dir = $this->tempDir();

        self::assertTrue(TemplateRoots::from($dir)->isSingle());
        self::assertFalse(TemplateRoots::from([$dir])->isSingle());
        self::assertFalse(TemplateRoots::from([$dir, $dir])->isSingle());
        self::assertSame([$dir], TemplateRoots::from([$dir, $dir])->all());
    }

    #[Test]
    #[DataProvider('listForms')]
    public function the_parser_refuses_a_symlink_out_of_the_only_root(string $form): void
    {
        $parser = new ComponentParser($this->asList($form));

        self::assertSame([], $parser->parseAll('component'));
        self::assertSame('templates_path[0]', $parser->getWarnings()[0]['root'] ?? null);
    }

    #[Test]
    public function a_scalar_keeps_following_the_symlink(): void
    {
        $parser = new ComponentParser($this->root1);

        self::assertSame(['Leaked'], array_column($parser->parseAll('component'), 'name'));
        self::assertSame([], $parser->getWarnings());
    }

    #[Test]
    #[DataProvider('listForms')]
    public function the_renderer_and_the_files_endpoint_refuse_it_too(string $form): void
    {
        $styleguide = $this->styleguide(
            $this->asList($form),
            $this->static,
            ['show_source' => true, 'source_views' => ['twig', 'css', 'js']],
        );

        self::assertStringNotContainsString(
            self::SECRET,
            (string) self::get($styleguide, '/styleguide/render/component/card')->body,
        );
        self::assertStringNotContainsString(
            self::SECRET,
            (string) self::get($styleguide, '/styleguide/api/files/component/card')->body,
        );
    }

    #[Test]
    public function a_scalar_renders_the_symlink_as_before(): void
    {
        $styleguide = $this->styleguide($this->root1, $this->static);

        self::assertStringContainsString(
            self::SECRET,
            (string) self::get($styleguide, '/styleguide/render/component/card')->body,
        );
    }
}
