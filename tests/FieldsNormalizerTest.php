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
    public function names_a_non_projecting_field_by_its_key_when_it_has_no_label(): void
    {
        // definition-kit 0.6 made `label` required only of a field that
        // projects into acf.json — a `role: parent` prop has no editor to
        // write copy for. Skipping those dropped 115 declared props out of one
        // theme's documentation; the props table is developer-facing, so the
        // key names it perfectly well.
        $result = FieldsNormalizer::normalize([
            'inner' => ['type' => 'boolean', 'role' => 'parent'],
            'items' => ['type' => 'repeater', 'role' => 'query'],
        ]);

        self::assertSame([], $result['warnings']);
        self::assertSame(['inner', 'items'], array_column($result['fields'], 'label'));
    }

    #[Test]
    public function still_skips_an_unlabelled_field_that_projects(): void
    {
        // No role, or `role: field`, means an ACF field is behind it — and one
        // of those really is missing its editor label.
        $result = FieldsNormalizer::normalize([
            'a' => ['type' => 'text'],
            'b' => ['type' => 'text', 'role' => 'field'],
        ]);

        self::assertSame([], $result['fields']);
        self::assertSame([
            'field "a": missing label/title — skipped',
            'field "b": missing label/title — skipped',
        ], $result['warnings']);
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
    public function absent_or_empty_fields_yield_a_silent_empty_result(): void
    {
        self::assertSame(['fields' => [], 'warnings' => []], FieldsNormalizer::normalize(null));
        self::assertSame(['fields' => [], 'warnings' => []], FieldsNormalizer::normalize([]));
    }

    #[Test]
    public function non_array_top_level_fields_value_warns_instead_of_silently_dropping(): void
    {
        // ADR-0002: "never silently dropped" — garbage on the top-level
        // `fields:` key (e.g. a scalar YAML typo) must surface a warning,
        // not just vanish into an empty list like an absent key does.
        $result = FieldsNormalizer::normalize('x');

        self::assertSame([], $result['fields']);
        self::assertSame(['fields: value is not a map — skipped'], $result['warnings']);
    }

    #[Test]
    public function non_array_nested_fields_value_warns_but_the_parent_field_survives(): void
    {
        $result = FieldsNormalizer::normalize([
            'items' => ['type' => 'repeater', 'label' => 'Items', 'fields' => 'oops'],
        ]);

        self::assertCount(1, $result['fields']);
        $field = $result['fields'][0];
        self::assertSame('Items', $field['label']);
        self::assertSame([], $field['children']);
        self::assertSame(['field "items": nested fields value is not a map — skipped'], $result['warnings']);
    }

    #[Test]
    public function authored_key_and_children_keys_cannot_clobber_canonical_structure(): void
    {
        $result = FieldsNormalizer::normalize([
            'related' => ['type' => 'reference', 'label' => 'X', 'key' => 'field_acf_residual', 'children' => 'junk'],
        ]);

        self::assertSame('related', $result['fields'][0]['key']);
        self::assertNull($result['fields'][0]['children']);
    }
}
