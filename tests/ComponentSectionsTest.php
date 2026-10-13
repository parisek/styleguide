<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\ComponentSections;
use Parisek\Styleguide\Http\Request;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `components.sections` in styleguide.yaml: the ordered sections, the tier
 * rule, `fallback` and `hidden`, and what reaches the SPA (`componentsSections`
 * in #sg-config, `section` on each /api/components item).
 */
final class ComponentSectionsTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    /**
     * @param list<mixed> $sections
     */
    private static function sections(array $sections): ComponentSections
    {
        $parsed = ComponentSections::fromConfig(['sections' => $sections]);
        self::assertNotNull($parsed);

        return $parsed;
    }

    /**
     * @param array<string,mixed> $yaml
     */
    private function styleguide(array $yaml): Styleguide
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sg-sections-');
        file_put_contents($path, Yaml::dump($yaml, 6));
        $this->tempFiles[] = $path;

        return new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => $path,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function spaConfig(Styleguide $styleguide): array
    {
        $result = $styleguide->handle(new Request('/styleguide/'));
        self::assertNotNull($result);
        self::assertSame(1, preg_match('#<script id="sg-config" type="application/json">(.*?)</script>#s', (string) $result->body, $m));

        return (array) json_decode($m[1], true, flags: \JSON_THROW_ON_ERROR);
    }

    // ---- the tier rule ----------------------------------------------------

    /**
     * The consumer case of portadesign/draty-z-porty#243.
     */
    private static function shop(): ComponentSections
    {
        return self::sections([
            ['kind' => 'section', 'label' => ['cs' => 'Základní sekce', 'en' => 'Base sections']],
            ['kind' => 'element', 'label' => ['cs' => 'UI komponenty', 'en' => 'UI components']],
            ['id' => 'listing', 'label' => 'Výpisové prvky', 'component' => ['team', 'job-list']],
            ['kind' => 'block'],
            ['id' => 'eshop', 'label' => 'E-shop komponenty', 'category' => 'Ecommerce'],
            ['kind' => 'part'],
            ['kind' => 'utility'],
        ]);
    }

    #[Test]
    public function a_category_entry_beats_a_kind_entry_that_stands_above_it(): void
    {
        $shop = self::shop();

        self::assertSame('eshop', $shop->resolve(['id' => 'product-card', 'kind' => 'element', 'category' => 'Ecommerce'])['id']);
        self::assertSame('eshop', $shop->resolve(['id' => 'cart', 'kind' => 'block', 'category' => 'ecommerce'])['id']);
        self::assertSame('basic', $shop->resolve(['id' => 'button', 'kind' => 'element', 'category' => 'Basic'])['id']);
    }

    #[Test]
    public function a_component_entry_beats_kind_and_category(): void
    {
        $shop = self::shop();

        $resolved = $shop->resolve(['id' => 'team', 'kind' => 'block', 'category' => 'Team']);
        self::assertSame('listing', $resolved['id']);
        self::assertSame(ComponentSections::TIER_COMPONENT, $resolved['tier']);
        self::assertSame('blocks', $shop->resolve(['id' => 'hero', 'kind' => 'block', 'category' => 'Hero'])['id']);
    }

    #[Test]
    public function kind_with_category_beats_category_alone_and_kind_alone(): void
    {
        $sections = self::sections([
            ['kind' => 'element'],
            ['id' => 'ecommerce', 'label' => 'Shop', 'category' => 'Ecommerce'],
            ['id' => 'forms', 'label' => 'Forms', 'kind' => 'element', 'category' => 'Forms'],
        ]);

        $form = $sections->resolve(['id' => 'select', 'kind' => 'element', 'category' => 'Forms']);
        self::assertSame('forms', $form['id']);
        self::assertSame(ComponentSections::TIER_KIND_CATEGORY, $form['tier']);
        self::assertSame(ComponentSections::TIER_CATEGORY, $sections->resolve(['id' => 'cart', 'kind' => 'block', 'category' => 'Ecommerce'])['tier']);
        self::assertSame(ComponentSections::TIER_KIND, $sections->resolve(['id' => 'button', 'kind' => 'element', 'category' => 'Basic'])['tier']);
    }

    #[Test]
    public function the_earlier_entry_wins_inside_a_tier(): void
    {
        $sections = self::sections([
            ['id' => 'first', 'label' => 'First', 'category' => 'Ecommerce'],
            ['id' => 'second', 'label' => 'Second', 'category' => 'Ecommerce'],
        ]);

        self::assertSame('first', $sections->resolve(['id' => 'cart', 'kind' => 'block', 'category' => 'Ecommerce'])['id']);
    }

    #[Test]
    public function every_declared_field_must_match(): void
    {
        $sections = self::sections([
            ['kind' => 'block'],
            ['id' => 'pinned', 'label' => 'Pinned', 'component' => ['hero'], 'kind' => 'utility'],
        ]);

        // `hero` is a block: the component entry names the wrong kind, so it does not take it.
        self::assertSame('blocks', $sections->resolve(['id' => 'hero', 'kind' => 'block', 'category' => 'Hero'])['id']);
        self::assertSame('pinned', $sections->resolve(['id' => 'hero', 'kind' => 'utility', 'category' => ''])['id']);
    }

    #[Test]
    public function an_unmatched_component_goes_to_the_fallback_entry(): void
    {
        $sections = self::sections([
            ['kind' => 'block'],
            ['id' => 'internal', 'fallback' => true, 'hidden' => true],
        ]);

        $resolved = $sections->resolve(['id' => 'debug-grid', 'kind' => '', 'category' => 'Internal']);
        self::assertSame('internal', $resolved['id']);
        self::assertSame(ComponentSections::TIER_FALLBACK, $resolved['tier']);
        self::assertTrue($resolved['hidden']);
    }

    #[Test]
    public function without_a_fallback_entry_an_unmatched_component_goes_to_the_implicit_other(): void
    {
        $sections = self::sections([['kind' => 'block']]);

        $resolved = $sections->resolve(['id' => 'legacy-banner', 'kind' => '', 'category' => 'Legacy']);
        self::assertSame('other', $resolved['id']);
        self::assertNull($resolved['index']);
        self::assertFalse($resolved['hidden']);
    }

    #[Test]
    public function a_match_on_a_hidden_entry_is_final(): void
    {
        $sections = self::sections([
            ['kind' => 'utility', 'hidden' => true],
            ['id' => 'rest', 'label' => 'Rest', 'fallback' => true],
        ]);

        $resolved = $sections->resolve(['id' => 'spacer', 'kind' => 'utility', 'category' => 'Basic']);
        self::assertSame('utilities', $resolved['id']);
        self::assertTrue($resolved['hidden']);
    }

    // ---- the payload -------------------------------------------------------

    #[Test]
    public function the_payload_lists_the_sections_in_order_and_closes_with_other(): void
    {
        $payload = self::shop()->payload();

        self::assertSame(
            ['sections', 'basic', 'listing', 'blocks', 'eshop', 'parts', 'utilities', 'other'],
            array_column($payload, 'id'),
        );
        self::assertSame(['cs' => 'Základní sekce', 'en' => 'Base sections'], $payload[0]['label']);
        self::assertArrayNotHasKey('label', $payload[3], 'a built-in entry without a label keeps the locale heading');
        self::assertSame(['id' => 'other', 'fallback' => true], $payload[7]);
    }

    #[Test]
    public function a_fallback_entry_replaces_the_implicit_other(): void
    {
        $payload = self::sections([
            ['kind' => 'block'],
            ['id' => 'internal', 'fallback' => true, 'hidden' => true],
        ])->payload();

        self::assertSame([
            ['id' => 'blocks'],
            ['id' => 'internal', 'hidden' => true, 'fallback' => true],
        ], $payload);
    }

    #[Test]
    public function a_fallback_entry_may_write_the_id_other_and_replaces_the_implicit_section(): void
    {
        $sections = self::sections([['kind' => 'block'], ['id' => 'other', 'fallback' => true, 'label' => 'Rest']]);

        self::assertSame(['blocks', 'other'], array_column($sections->payload(), 'id'));
        self::assertSame('other', $sections->resolve(['id' => 'x', 'kind' => '', 'category' => ''])['id']);
        self::assertSame(1, $sections->resolve(['id' => 'x', 'kind' => '', 'category' => ''])['index']);
    }

    #[Test]
    public function a_fallback_entry_without_an_id_is_the_section_other(): void
    {
        $payload = self::sections([['kind' => 'block'], ['fallback' => true]])->payload();

        self::assertSame('other', $payload[1]['id']);
    }

    // ---- validation --------------------------------------------------------

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalid(): iterable
    {
        yield 'not a list' => [['a' => 1], 'non-empty list'];
        yield 'empty list' => [[], 'non-empty list'];
        yield 'entry not a map' => [['block'], 'sections[0]` must be a map'];
        yield 'unknown field' => [[['kind' => 'block', 'before' => 'x']], 'unknown field "before"'];
        yield 'nothing to match' => [[['id' => 'x', 'label' => 'X']], 'needs one of'];
        yield 'bad kind' => [[['kind' => 'ecommerce']], '`kind` must be one of'];
        yield 'empty category' => [[['id' => 'x', 'label' => 'X', 'category' => ' ']], '`category` must be a non-empty string'];
        yield 'component not a list' => [[['id' => 'x', 'label' => 'X', 'component' => 'hero']], '`component` must be a non-empty list'];
        yield 'bad component id' => [[['id' => 'x', 'label' => 'X', 'component' => ['he ro']]], 'component ids'];
        yield 'fallback with kind' => [[['fallback' => true, 'kind' => 'block']], '`fallback` does not go with'];
        yield 'two fallbacks' => [[['fallback' => true], ['id' => 'b', 'label' => 'B', 'fallback' => true]], 'second `fallback`'];
        yield 'hidden not a bool' => [[['kind' => 'block', 'hidden' => 'yes']], '`hidden` must be true or false'];
        yield 'bad id' => [[['id' => 'E-shop', 'label' => 'X', 'category' => 'Y']], '`id` must match'];
        yield 'duplicate derived id' => [[['kind' => 'block'], ['kind' => 'block']], 'repeats the id "blocks"'];
        yield 'category entry without id' => [[['category' => 'Ecommerce', 'label' => 'Shop']], 'needs an `id`'];
        yield 'custom id without label' => [[['id' => 'eshop', 'category' => 'Ecommerce']], 'needs a `label`'];
        yield 'label a list' => [[['kind' => 'block', 'label' => ['a', 'b']]], '`label` must be a string or a map'];
        yield 'null kind' => [[['kind' => null, 'id' => 'x', 'label' => 'X', 'category' => 'Y']], '`kind` must not be null'];
        yield 'null hidden' => [[['kind' => 'block', 'hidden' => null]], '`hidden` must not be null'];
        yield 'null id' => [[['kind' => 'block', 'id' => null]], '`id` must not be null'];
        yield 'custom id other' => [[['id' => 'other', 'label' => 'Other', 'category' => 'Misc']], 'reserved for the `fallback` entry'];
        yield 'label map with empty text' => [[['kind' => 'block', 'label' => ['cs' => '']]], '`label` must be a string or a map'];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function an_invalid_config_fails_at_construction(mixed $sections, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('components.sections');
        $this->expectExceptionMessage($message);

        $this->styleguide(['components' => ['sections' => $sections]]);
    }

    #[Test]
    public function sections_and_group_by_together_throw(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('replaces `components.group_by`');

        $this->styleguide(['components' => ['group_by' => 'kind', 'sections' => [['kind' => 'block']]]]);
    }

    #[Test]
    public function a_null_group_by_next_to_sections_also_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('replaces `components.group_by`');

        $this->styleguide(['components' => ['group_by' => null, 'sections' => [['kind' => 'block']]]]);
    }

    #[Test]
    public function a_category_compares_case_insensitively_beyond_ascii(): void
    {
        $sections = self::sections([['id' => 'live', 'label' => 'Live', 'category' => 'Živé']]);

        self::assertSame('live', $sections->resolve(['id' => 'a', 'kind' => 'block', 'category' => 'ŽIVÉ'])['id']);
        self::assertSame('live', $sections->resolve(['id' => 'a', 'kind' => 'block', 'category' => ' živé '])['id']);
    }

    #[Test]
    public function include_runs_first_and_a_filtered_out_component_may_still_be_named(): void
    {
        $all = $this->styleguide(['components' => ['sections' => [['kind' => 'element'], ['kind' => 'block']]]]);
        $result = $all->handle(new Request('/styleguide/api/components'));
        self::assertNotNull($result);
        $ids = array_column((array) json_decode((string) $result->body, true, flags: \JSON_THROW_ON_ERROR), 'id');
        self::assertGreaterThan(1, count($ids));
        $listed = $ids[0];
        $other = $ids[1];

        // `include` lists one id; `sections` names another that exists but is filtered out: no error.
        $styleguide = $this->styleguide(['components' => [
            'include' => [$listed],
            'sections' => [['id' => 'named', 'label' => 'Named', 'component' => [$other]], ['kind' => 'element'], ['kind' => 'block']],
        ]]);
        $result = $styleguide->handle(new Request('/styleguide/api/components'));
        self::assertNotNull($result);
        $items = (array) json_decode((string) $result->body, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([$listed], array_column($items, 'id'));
        self::assertNotSame('named', $items[0]['section']);
    }

    #[Test]
    public function a_hidden_fallback_reaches_the_api_and_the_payload(): void
    {
        $styleguide = $this->styleguide(['components' => ['sections' => [
            ['kind' => 'element'],
            ['id' => 'internal', 'fallback' => true, 'hidden' => true],
        ]]]);
        $result = $styleguide->handle(new Request('/styleguide/api/components'));
        self::assertNotNull($result);
        $items = (array) json_decode((string) $result->body, true, flags: \JSON_THROW_ON_ERROR);

        $sections = array_unique(array_column($items, 'section'));
        self::assertContains('internal', $sections, 'a component that is not an element goes to the hidden fallback');
        self::assertNotContains('other', $sections);
        self::assertTrue($this->spaConfig($styleguide)['componentsSections'][1]['hidden']);
    }

    #[Test]
    public function an_unknown_component_id_fails_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"no-such-component" (`components.sections[0]`)');

        $this->styleguide(['components' => ['sections' => [
            ['id' => 'x', 'label' => 'X', 'component' => ['no-such-component']],
        ]]]);
    }

    // ---- what reaches the SPA ----------------------------------------------

    #[Test]
    public function the_resolved_list_reaches_the_spa_and_group_by_does_not(): void
    {
        $config = $this->spaConfig($this->styleguide(['components' => ['sections' => [
            ['kind' => 'block', 'label' => ['cs' => 'Bloky', 'en' => 'Blocks']],
            ['id' => 'internal', 'fallback' => true, 'hidden' => true],
        ]]]));

        self::assertSame([
            ['id' => 'blocks', 'label' => ['cs' => 'Bloky', 'en' => 'Blocks']],
            ['id' => 'internal', 'hidden' => true, 'fallback' => true],
        ], $config['componentsSections']);
        self::assertArrayNotHasKey('componentsGroupBy', $config);
    }

    #[Test]
    public function without_the_key_the_payload_has_no_sections(): void
    {
        self::assertArrayNotHasKey('componentsSections', $this->spaConfig($this->styleguide(['project' => ['name' => 'X']])));
        self::assertArrayNotHasKey('componentsSections', $this->spaConfig($this->styleguide(['components' => ['group_by' => 'kind']])));
    }

    #[Test]
    public function every_component_names_its_section_in_the_api(): void
    {
        $styleguide = $this->styleguide(['components' => ['sections' => [
            ['kind' => 'element'],
            ['kind' => 'block'],
        ]]]);
        $result = $styleguide->handle(new Request('/styleguide/api/components'));
        self::assertNotNull($result);
        $items = (array) json_decode((string) $result->body, true, flags: \JSON_THROW_ON_ERROR);

        self::assertNotSame([], $items);
        $known = ['basic', 'blocks', 'other'];
        foreach ($items as $item) {
            self::assertContains($item['section'], $known, (string) $item['id']);
            // The rule, not a guess: the API names what resolve() returns.
            $expected = match ($item['kind']) {
                'element' => 'basic',
                'block' => 'blocks',
                default => 'other',
            };
            self::assertSame($expected, $item['section'], (string) $item['id']);
        }
    }

    #[Test]
    public function without_the_key_an_api_item_has_no_section(): void
    {
        $result = $this->styleguide(['project' => ['name' => 'X']])->handle(new Request('/styleguide/api/components'));
        self::assertNotNull($result);
        $items = (array) json_decode((string) $result->body, true, flags: \JSON_THROW_ON_ERROR);

        self::assertNotSame([], $items);
        self::assertArrayNotHasKey('section', $items[0]);
    }
}
