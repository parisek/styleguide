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

    #[Test]
    public function the_response_declares_what_it_actually_checked(): void
    {
        // The endpoint is called "health" but only parses metadata, and a
        // template with a fatal Twig error in its BODY parses its metadata
        // fine — so it is counted as a healthy component while every render of
        // it fails. Downstream, `warnings: []` plus a full component count was
        // read as "the catalogue is fine" for days while eleven templates
        // rendered nothing. The field makes the scope readable from the
        // response instead of only from the docs.
        ob_start();
        (new HealthEndpoint(new ComponentParser(__DIR__ . '/../fixtures/templates')))->handle();
        $data = json_decode((string) ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('metadata', $data['checked']);
    }
}
