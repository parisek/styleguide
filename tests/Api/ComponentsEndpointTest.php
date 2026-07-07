<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Api;

use Parisek\Styleguide\Api\ComponentsEndpoint;
use Parisek\Styleguide\ComponentParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComponentsEndpointTest extends TestCase
{
    #[Test]
    public function handle_passes_through_the_variants_field(): void
    {
        $parser = new ComponentParser(__DIR__ . '/../fixtures/templates');
        $endpoint = new ComponentsEndpoint($parser);

        ob_start();
        $endpoint->handle();
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        self::assertIsArray($data);

        $multi = current(array_filter($data, static fn(array $c): bool => $c['id'] === 'multi'));
        self::assertNotFalse($multi, 'multi fixture must appear in /api/components');
        self::assertSame(['dark-bg', 'secondary'], array_column($multi['variants'], 'id'));

        $sample = current(array_filter($data, static fn(array $c): bool => $c['id'] === 'sample'));
        self::assertNotFalse($sample);
        self::assertSame([], $sample['variants'], 'a component with no variant siblings gets variants: []');
    }
}
