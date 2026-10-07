<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Overlay;

use Parisek\Styleguide\Cli\Command;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;

/**
 * `components.include` and the `usage` data. The catalogue must not name a
 * component outside the list, and must not name an id that is nothing at all.
 * A page id stays: pages are not filtered by this key.
 */
final class ComponentsIncludeUsageTest extends OverlayTestCase
{
    private string $kit;
    private string $static;

    protected function setUp(): void
    {
        $this->kit = $this->tempDir();
        $this->static = $this->tempDir();
        $twig = static fn(string $name, string $usage): string => "{# name: {$name}\nusage: {$usage} #}\n<i>{$name}</i>";
        self::put($this->kit . '/component/referencer/referencer.twig', $twig('Referencer', 'clean,ghost-id,hidden,home'));
        self::put($this->kit . '/component/clean/clean.twig', $twig('Clean', 'referencer'));
        self::put($this->kit . '/component/hidden/hidden.twig', $twig('Hidden', 'referencer'));
        self::put($this->kit . '/page/home/home.twig', $twig('Home', 'clean,hidden,ghost-id'));
    }

    private function catalogue(string $yaml): Styleguide
    {
        return $this->styleguide($this->kit, $this->static, [], $yaml);
    }

    /**
     * @return array<string, list<string>> id => usage
     */
    private static function usageOf(Styleguide $styleguide, string $kind): array
    {
        $rows = (array) json_decode((string) self::get($styleguide, '/styleguide/api/' . $kind)->body, true);

        return array_column(array_map(
            static fn(array $row): array => ['id' => $row['id'], 'usage' => $row['usage']],
            $rows,
        ), 'usage', 'id');
    }

    #[Test]
    public function without_the_key_the_usage_is_untouched(): void
    {
        $styleguide = $this->catalogue('');

        self::assertSame(['clean', 'ghost-id', 'hidden', 'home'], self::usageOf($styleguide, 'components')['referencer']);
        self::assertSame(['clean', 'hidden', 'ghost-id'], self::usageOf($styleguide, 'pages')['home']);
    }

    #[Test]
    public function the_api_names_only_listed_components_and_known_pages(): void
    {
        $styleguide = $this->catalogue("components:\n  include: [referencer, clean]\n");

        $components = self::usageOf($styleguide, 'components');
        self::assertSame(['clean', 'home'], $components['referencer'], 'no hidden id, no unknown id, the page stays');
        self::assertSame(['referencer'], $components['clean']);
        self::assertSame(['clean'], self::usageOf($styleguide, 'pages')['home'], 'a page names only listed components');
    }

    #[Test]
    public function an_empty_list_leaves_no_component_in_any_usage(): void
    {
        $styleguide = $this->catalogue("components:\n  include: []\n");

        self::assertSame([], self::usageOf($styleguide, 'components'));
        self::assertSame([], self::usageOf($styleguide, 'pages')['home']);
    }

    #[Test]
    public function the_cli_show_and_list_name_only_listed_ids(): void
    {
        file_put_contents($this->static . '/styleguide.yaml', "components:\n  include: [referencer, clean]\n");
        $run = function (array $argv): string {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            (new Command())->run($argv, $stdout, $stderr);
            rewind($stdout);

            return (string) stream_get_contents($stdout);
        };
        $args = ['--templates=' . $this->kit, '--config=' . $this->static . '/styleguide.yaml'];

        $shown = (array) json_decode($run(['show', 'referencer', ...$args]), true);
        self::assertSame(['clean', 'home'], $shown['usage']);

        $listed = (array) json_decode($run(['list', ...$args]), true);
        self::assertSame(['clean', 'home'], array_column($listed, 'usage', 'id')['referencer']);

        $pages = (array) json_decode($run(['list', '--type=page', ...$args]), true);
        self::assertSame(['clean'], array_column($pages, 'usage', 'id')['home']);
    }
}
