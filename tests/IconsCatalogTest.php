<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\IconsCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see IconsCatalog::build()} — server-side icon catalog backing the
 * standalone `/styleguide/icons` page (#87). Fixture assets live under
 * `tests/fixtures/images/icons/`:
 *  - ico-test-arrow.svg — contract-following: `{{ attribute }}` placeholder,
 *    viewBox, `fill="currentColor"`
 *  - ico-test-legacy.svg — pre-contract: fixed width/height (px-suffixed),
 *    no viewBox, own per-path fills (multi)
 *  - ico-test-evil.svg — xml prolog, comment, mixed-case event handlers,
 *    <script>, <style> with external @import, style attribute with external
 *    url(), entity-encoded and whitespace-padded javascript: hrefs, a
 *    legitimate fragment href, <foreignObject>
 *  - ico-test-units.svg — non-px dimensions (100%/2em), no viewBox
 *  - ico-test-doctype.svg — carries a DTD (entity vector) — rejected whole
 *  - ico-test-malformed.svg — unquoted attribute (invalid XML, the shape a
 *    regex sanitizer would mis-handle) — rejected whole by the parser
 *  - ico-test-notsvg.svg — HTML masquerading under an .svg extension
 */
final class IconsCatalogTest extends TestCase
{
    private const STATIC_PATH = __DIR__ . '/fixtures';

    /** @return array<string, mixed> */
    private function config(array $items): array
    {
        return [
            'groups' => [
                ['key' => 'test', 'label' => 'Test icons', 'items' => $items],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function arrowItem(): array
    {
        return ['name' => 'test-arrow', 'file' => '/images/icons/ico-test-arrow.svg'];
    }

    #[DataProvider('absentConfigProvider')]
    public function testAbsentOrEmptyConfigReturnsNull(mixed $icons): void
    {
        self::assertNull(IconsCatalog::build(self::STATIC_PATH, $icons));
    }

    /** @return array<string, array{mixed}> */
    public static function absentConfigProvider(): array
    {
        return [
            'null' => [null],
            'string' => ['icons'],
            'empty array' => [[]],
            'groups not a list' => [['groups' => 'nope']],
            'empty groups' => [['groups' => []]],
            'group without items' => [['groups' => [['key' => 'base']]]],
            'items all nameless' => [['groups' => [['key' => 'base', 'items' => [['file' => '/images/icons/ico-test-arrow.svg']]]]]],
        ];
    }

    public function testContractIconInlinesWithPlaceholderStripped(): void
    {
        $result = IconsCatalog::build(self::STATIC_PATH, $this->config([$this->arrowItem()]));

        self::assertNotNull($result);
        self::assertSame(1, $result['total']);
        $icon = $result['groups'][0]['items'][0];

        self::assertSame('test-arrow', $icon['name']);
        self::assertTrue($icon['exists']);
        self::assertSame('mono', $icon['color']);
        self::assertIsString($icon['svg']);
        self::assertStringStartsWith('<svg', $icon['svg']);
        self::assertStringNotContainsString('{{', $icon['svg']);
        self::assertStringNotContainsString('attribute', $icon['svg']);
        self::assertStringContainsString('viewBox="0 0 24 24"', $icon['svg']);
        self::assertStringContainsString('currentColor', $icon['svg']);
    }

    public function testGroupMetadataAndLabelsPassThrough(): void
    {
        $item = $this->arrowItem() + ['label' => 'Šipka'];
        $result = IconsCatalog::build(self::STATIC_PATH, $this->config([$item]));

        self::assertNotNull($result);
        $group = $result['groups'][0];
        self::assertSame('test', $group['key']);
        self::assertSame('Test icons', $group['label']);
        self::assertSame('Šipka', $group['items'][0]['label']);
    }

    public function testLegacyIconGetsSynthesizedViewBoxAndDroppedDimensions(): void
    {
        $result = IconsCatalog::build(self::STATIC_PATH, $this->config([
            ['name' => 'test-legacy', 'file' => '/images/icons/ico-test-legacy.svg'],
        ]));

        self::assertNotNull($result);
        $icon = $result['groups'][0]['items'][0];

        self::assertIsString($icon['svg']);
        self::assertStringContainsString('viewBox="0 0 119 119"', $icon['svg']);
        self::assertDoesNotMatchRegularExpression('/<svg[^>]*\swidth=/i', $icon['svg']);
        self::assertDoesNotMatchRegularExpression('/<svg[^>]*\sheight=/i', $icon['svg']);
        // No currentColor anywhere → auto-detected as multi.
        self::assertSame('multi', $icon['color']);
    }

    public function testConfiguredColorWinsOverAutoDetection(): void
    {
        $result = IconsCatalog::build(self::STATIC_PATH, $this->config([
            ['name' => 'test-legacy', 'file' => '/images/icons/ico-test-legacy.svg', 'color' => 'mono'],
        ]));

        self::assertNotNull($result);
        self::assertSame('mono', $result['groups'][0]['items'][0]['color']);
    }

    public function testScriptVectorsAreStripped(): void
    {
        $result = IconsCatalog::build(self::STATIC_PATH, $this->config([
            ['name' => 'test-evil', 'file' => '/images/icons/ico-test-evil.svg'],
        ]));

        self::assertNotNull($result);
        $icon = $result['groups'][0]['items'][0];

        self::assertTrue($icon['exists']);
        self::assertIsString($icon['svg']);
        self::assertStringStartsWith('<svg', $icon['svg']);
        self::assertStringNotContainsString('<?xml', $icon['svg']);
        self::assertStringNotContainsString('<!--', $icon['svg']);
        self::assertStringNotContainsString('<script', $icon['svg']);
        self::assertStringNotContainsString('foreignObject', $icon['svg']);
        // Event handlers in any casing — the parser normalizes what a regex
        // would have to pattern-match (unquoted variants don't parse as XML
        // at all and reject the whole file, see the malformed fixture).
        self::assertDoesNotMatchRegularExpression('/\son\w+\s*=/i', $icon['svg']);
        // javascript: URLs in raw, entity-encoded and whitespace-padded
        // shapes — all decoded by the parser before the check, so none may
        // survive; the same-document fragment reference stays.
        self::assertStringNotContainsStringIgnoringCase('javascript', $icon['svg']);
        self::assertStringNotContainsString('avascript', $icon['svg']);
        self::assertStringContainsString('#safe-ref', $icon['svg']);
        // CSS layer — <style> elements and style attributes can carry
        // external url(…) fetches, both are removed outright.
        self::assertStringNotContainsStringIgnoringCase('<style', $icon['svg']);
        self::assertStringNotContainsStringIgnoringCase('style=', $icon['svg']);
        self::assertStringNotContainsString('evil.example', $icon['svg']);
        // Content still intact.
        self::assertStringContainsString('<path d="M4 12h16"/>', $icon['svg']);
    }

    public function testNonPixelDimensionsAreDroppedWithoutViewBoxSynthesis(): void
    {
        // width="100%" / height="2em" must not be truncated into a bogus
        // viewBox="0 0 100 2" — non-px units are rejected, the dimensions
        // are still dropped, and no viewBox is synthesized.
        $result = IconsCatalog::build(self::STATIC_PATH, $this->config([
            ['name' => 'test-units', 'file' => '/images/icons/ico-test-units.svg'],
        ]));

        self::assertNotNull($result);
        $icon = $result['groups'][0]['items'][0];

        self::assertTrue($icon['exists']);
        self::assertIsString($icon['svg']);
        self::assertStringNotContainsString('viewBox', $icon['svg']);
        self::assertDoesNotMatchRegularExpression('/<svg[^>]*\swidth=/i', $icon['svg']);
        self::assertDoesNotMatchRegularExpression('/<svg[^>]*\sheight=/i', $icon['svg']);
    }

    public function testDoctypeCarryingFileIsRejectedWhole(): void
    {
        // A DTD is an entity-expansion vector — the file is rejected as not
        // inlinable rather than partially cleaned.
        $result = IconsCatalog::build(self::STATIC_PATH, $this->config([
            ['name' => 'test-doctype', 'file' => '/images/icons/ico-test-doctype.svg'],
        ]));

        self::assertNotNull($result);
        $icon = $result['groups'][0]['items'][0];

        self::assertFalse($icon['exists']);
        self::assertNull($icon['svg']);
    }

    #[DataProvider('notInlinableProvider')]
    public function testNotInlinableEntriesRenderAsMissing(string $file): void
    {
        $result = IconsCatalog::build(self::STATIC_PATH, $this->config([
            ['name' => 'test-bad', 'file' => $file],
        ]));

        self::assertNotNull($result);
        $icon = $result['groups'][0]['items'][0];

        self::assertFalse($icon['exists']);
        self::assertNull($icon['svg']);
    }

    /** @return array<string, array{string}> */
    public static function notInlinableProvider(): array
    {
        return [
            'missing file' => ['/images/icons/ico-test-nonexistent.svg'],
            'no file key at all' => [''],
            'html under .svg extension' => ['/images/icons/ico-test-notsvg.svg'],
            'invalid xml (unquoted attribute)' => ['/images/icons/ico-test-malformed.svg'],
            'external url' => ['https://evil.example/ico.svg'],
            'protocol-relative url' => ['//evil.example/ico.svg'],
            'data uri' => ['data:image/svg+xml,<svg onload=alert(1)></svg>'],
            'path escaping static root' => ['/../composer.json'],
        ];
    }

    public function testNamelessItemsAreDroppedAndEmptyGroupsCollapse(): void
    {
        $result = IconsCatalog::build(self::STATIC_PATH, [
            'groups' => [
                ['key' => 'empty', 'items' => [['file' => '/images/icons/ico-test-arrow.svg']]],
                ['key' => 'kept', 'items' => [$this->arrowItem(), 'not-an-array']],
            ],
        ]);

        self::assertNotNull($result);
        self::assertCount(1, $result['groups']);
        self::assertSame('kept', $result['groups'][0]['key']);
        self::assertSame(1, $result['total']);
    }

    public function testGroupKeyFallsBackWhenLabelMissing(): void
    {
        $result = IconsCatalog::build(self::STATIC_PATH, [
            'groups' => [
                ['key' => 'social', 'items' => [$this->arrowItem()]],
            ],
        ]);

        self::assertNotNull($result);
        self::assertSame('social', $result['groups'][0]['label']);
    }
}
