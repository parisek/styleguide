<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Api;

use Parisek\Styleguide\Api\DocsEndpoint;
use Parisek\Styleguide\ComponentParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocsEndpointTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = __DIR__ . '/../fixtures/templates';
    }

    #[Test]
    public function handle_returns_json_list_containing_sample_doc(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $endpoint = new DocsEndpoint($parser);

        ob_start();
        $endpoint->handle();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        self::assertIsArray($data);

        $ids = array_column($data, 'id');
        self::assertContains('sample-doc', $ids, 'Expected sample-doc in /api/docs response');
    }
}
