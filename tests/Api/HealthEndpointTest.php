<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Api;

use Parisek\Styleguide\Api\HealthEndpoint;
use Parisek\Styleguide\ComponentParser;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// See ComponentParserTest for why: the mock below is a stub (willReturnCallback)
// exercising the resilience path, not an interaction expectation.
#[AllowMockObjectsWithoutExpectations]
final class HealthEndpointTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = __DIR__ . '/../fixtures/templates';
    }

    #[Test]
    public function handle_returns_counts_and_empty_warnings_for_healthy_fixtures(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $endpoint = new HealthEndpoint($parser);

        ob_start();
        $endpoint->handle();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        self::assertIsArray($data);
        self::assertSame([], $data['warnings']);
        self::assertGreaterThan(0, $data['counts']['components']);
        self::assertArrayHasKey('pages', $data['counts']);
        self::assertArrayHasKey('docs', $data['counts']);
    }

    #[Test]
    public function handle_surfaces_warnings_the_parser_already_collected(): void
    {
        $real = new ComponentParser($this->fixturesPath);
        $parser = $this->getMockBuilder(ComponentParser::class)
            ->setConstructorArgs([$this->fixturesPath])
            ->onlyMethods(['parseTwigComment'])
            ->getMock();
        $parser->method('parseTwigComment')->willReturnCallback(
            static function (string $content) use ($real) {
                if (str_contains($content, 'name: "Sample"')) {
                    throw new \RuntimeException('simulated parser fault');
                }
                return $real->parseTwigComment($content);
            },
        );

        $endpoint = new HealthEndpoint($parser);
        ob_start();
        $endpoint->handle();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        self::assertNotEmpty($data['warnings']);
        self::assertSame('component/sample/sample.twig', $data['warnings'][0]['file']);
    }
}
