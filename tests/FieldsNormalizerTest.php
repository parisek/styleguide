<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\FieldsNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldsNormalizerTest extends TestCase
{
    #[Test]
    public function normalizes_old_doctrine_title_to_label(): void
    {
        $result = FieldsNormalizer::normalize([
            'title' => ['type' => 'text', 'title' => 'Nadpis', 'required' => true],
        ]);

        self::assertSame([], $result['warnings']);
        self::assertSame([[
            'key' => 'title',
            'label' => 'Nadpis',
            'type' => 'text',
            'description' => '',
            'required' => true,
            'children' => null,
        ]], $result['fields']);
    }

    #[Test]
    public function normalizes_definition_kit_label_and_recurses_children(): void
    {
        $result = FieldsNormalizer::normalize([
            'heading' => [
                'type' => 'group',
                'label' => 'Heading',
                'fields' => [
                    'subtitle' => ['type' => 'text', 'label' => 'Podnadpis', 'multiline' => true],
                ],
            ],
        ]);

        self::assertSame([], $result['warnings']);
        $heading = $result['fields'][0];
        self::assertSame('Heading', $heading['label']);
        self::assertSame('Podnadpis', $heading['children'][0]['label']);
        // Open contract: non-core authored key passes through verbatim.
        self::assertTrue($heading['children'][0]['multiline']);
    }

    #[Test]
    public function passes_every_non_core_key_through_verbatim(): void
    {
        $result = FieldsNormalizer::normalize([
            'related' => [
                'type' => 'reference',
                'label' => 'Články',
                'of' => 'post:article',
                'multiple' => true,
                'mcp' => ['Pick 3-5 related articles'],
                'wp' => ['allow_in_bindings' => 1],
                'visible_when' => ['field' => 'layout', 'equals' => 'list'],
                'maxlength' => 120,
            ],
        ]);

        $field = $result['fields'][0];
        self::assertSame('post:article', $field['of']);
        self::assertTrue($field['multiple']);
        self::assertSame(['Pick 3-5 related articles'], $field['mcp']);
        self::assertSame(['allow_in_bindings' => 1], $field['wp']);
        self::assertSame(['field' => 'layout', 'equals' => 'list'], $field['visible_when']);
        self::assertSame(120, $field['maxlength']);
    }

    #[Test]
    public function skips_malformed_entries_with_a_warning(): void
    {
        $result = FieldsNormalizer::normalize([
            'ok' => ['type' => 'text', 'label' => 'Fine'],
            'scalar' => 'not-a-map',
            'unlabeled' => ['type' => 'text'],
            'nested' => ['type' => 'group', 'label' => 'Nest', 'fields' => ['bad' => 42]],
        ]);

        self::assertCount(2, $result['fields']); // ok + nested (with empty children)
        self::assertSame([
            'field "scalar": definition is not a map — skipped',
            'field "unlabeled": missing label/title — skipped',
            'field "nested.bad": definition is not a map — skipped',
        ], $result['warnings']);
    }

    #[Test]
    public function non_array_input_yields_empty_result(): void
    {
        self::assertSame(['fields' => [], 'warnings' => []], FieldsNormalizer::normalize(null));
        self::assertSame(['fields' => [], 'warnings' => []], FieldsNormalizer::normalize('x'));
        self::assertSame(['fields' => [], 'warnings' => []], FieldsNormalizer::normalize([]));
    }
}
