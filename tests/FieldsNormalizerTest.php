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
            'a' => ['type' => 'repeater', 'role' => 'query'],
            'b' => ['type' => 'text', 'role' => 'global'],
            'c' => ['type' => 'boolean', 'role' => 'parent'],
            'd' => ['type' => 'text', 'role' => 'inherited'],
            'e' => ['type' => 'text', 'role' => 'derived', 'from' => 'video'],
        ]);

        self::assertSame([], $result['warnings']);
        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_column($result['fields'], 'label'));
        // The fallback must not disturb the rest of the canonical output, nor
        // the open contract: `role` and `from` are not core keys and ride
        // through verbatim (ADR-0002).
        self::assertSame('derived', $result['fields'][4]['role']);
        self::assertSame('video', $result['fields'][4]['from']);
        self::assertSame('e', $result['fields'][4]['key']);
        self::assertSame('text', $result['fields'][4]['type']);
    }

    #[Test]
    public function still_skips_an_unlabelled_field_whose_role_is_not_a_known_non_projecting_one(): void
    {
        // The vocabulary is closed on purpose. A typo, a role removed upstream
        // (`computed`, dropped in definition-kit 0.6), a non-string or an empty
        // one must not buy a field its way past the label requirement.
        $result = FieldsNormalizer::normalize([
            'no_role' => ['type' => 'text'],
            'projecting' => ['type' => 'text', 'role' => 'field'],
            'typo' => ['type' => 'text', 'role' => 'filed'],
            'removed' => ['type' => 'text', 'role' => 'computed'],
            'empty' => ['type' => 'text', 'role' => ''],
            'not_a_string' => ['type' => 'text', 'role' => 1],
            'null_role' => ['type' => 'text', 'role' => null],
        ]);

        self::assertSame([], $result['fields']);
        self::assertSame([
            'field "no_role": missing label/title — skipped',
            'field "projecting": missing label/title — skipped',
            'field "typo": missing label/title — skipped',
            'field "removed": missing label/title — skipped',
            'field "empty": missing label/title — skipped',
            'field "not_a_string": missing label/title — skipped',
            'field "null_role": missing label/title — skipped',
        ], $result['warnings']);
    }

    #[Test]
    public function the_fallback_recurses_and_keeps_warning_order(): void
    {
        $result = FieldsNormalizer::normalize([
            'items' => [
                'type' => 'repeater',
                'role' => 'parent',
                'fields' => [
                    'title' => ['type' => 'text', 'role' => 'parent'],
                    'broken' => ['type' => 'text'],
                ],
            ],
            'after' => ['type' => 'text'],
        ]);

        self::assertSame(['items'], array_column($result['fields'], 'label'));
        self::assertSame(['title'], array_column($result['fields'][0]['children'], 'label'));
        // Nested warnings are emitted while walking the parent, so they come
        // before the sibling that follows it.
        self::assertSame([
            'field "items.broken": missing label/title — skipped',
            'field "after": missing label/title — skipped',
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
