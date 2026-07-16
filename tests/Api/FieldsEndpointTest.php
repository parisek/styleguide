<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Api;

use Parisek\Styleguide\Api\FieldsEndpoint;
use Parisek\Styleguide\ComponentParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldsEndpointTest extends TestCase
{
    #[Test]
    public function handle_returns_only_components_that_declare_fields(): void
    {
        $parser = new ComponentParser(__DIR__ . '/../fixtures/templates');
        $endpoint = new FieldsEndpoint($parser);

        ob_start();
        $endpoint->handle();
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        self::assertIsArray($data);

        foreach ($data as $entry) {
            self::assertArrayHasKey('component_id', $entry);
            self::assertArrayHasKey('component_name', $entry);
            self::assertArrayHasKey('fields', $entry);
            self::assertNotEmpty($entry['fields'], sprintf('%s must not appear with empty fields', $entry['component_id']));
        }

        $ids = array_column($data, 'component_id');
        self::assertContains('with-fields', $ids);
        self::assertContains('defkit-card', $ids);
        // `sample` carries no `fields:` metadata — must be absent, not
        // present with an empty `fields: []`.
        self::assertNotContains('sample', $ids);
    }

    #[Test]
    public function handle_returns_component_ids_only_no_page_ids(): void
    {
        // /api/fields aggregates templates_path/component/ only (docs/API.md
        // § GET /styleguide/api/fields) — a page's fields are exposed
        // per-item on /api/pages instead, never surfaced here. The shared
        // fixture root's only page fixture (`landing`) carries no `fields:`
        // metadata, so this asserts the weaker invariant the fixtures can
        // support: the returned id set contains component ids exclusively.
        $parser = new ComponentParser(__DIR__ . '/../fixtures/templates');
        $endpoint = new FieldsEndpoint($parser);

        ob_start();
        $endpoint->handle();
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        self::assertIsArray($data);

        $ids = array_column($data, 'component_id');
        self::assertNotContains('landing', $ids, '/api/fields must never include a page id, even if the page declared fields');
    }

    #[Test]
    public function fields_carry_the_canonical_normalised_shape(): void
    {
        $parser = new ComponentParser(__DIR__ . '/../fixtures/templates');
        $endpoint = new FieldsEndpoint($parser);

        ob_start();
        $endpoint->handle();
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        $card = current(array_filter($data, static fn(array $c): bool => $c['component_id'] === 'defkit-card'));
        self::assertNotFalse($card, 'defkit-card fixture must appear in /api/fields');

        $title = $card['fields'][0];
        self::assertSame('title', $title['key']);
        self::assertSame('Nadpis', $title['label']);
        self::assertSame('text', $title['type']);
        self::assertTrue($title['required']);
        // Open contract (ADR-0002): non-core authored key passes through
        // verbatim in the canonical shape.
        self::assertSame(120, $title['maxlength']);
    }
}
