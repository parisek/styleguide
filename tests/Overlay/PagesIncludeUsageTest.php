<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Overlay;

use Parisek\Styleguide\Cli\Command;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;

/**
 * `pages.include` and `components.include` shape the `usage` data. Each entry
 * names only listed components and listed pages, and no id that is nothing at
 * all. With both keys set, both lists apply.
 */
final class PagesIncludeUsageTest extends OverlayTestCase
{
    private string $kit;
    private string $static;

    protected function setUp(): void
    {
        $this->kit = $this->tempDir();
        $this->static = $this->tempDir();
        $twig = static fn(string $name, string $usage): string => "{# name: {$name}\nusage: {$usage} #}\n<i>{$name}</i>";
        self::put($this->kit . '/component/card/card.twig', $twig('Card', 'home,secret,ghost-id,about'));
        self::put($this->kit . '/component/hidden/hidden.twig', $twig('Hidden', 'home'));
        self::put($this->kit . '/page/home/home.twig', $twig('Home', 'card,hidden,ghost-id'));
        self::put($this->kit . '/page/secret/secret.twig', $twig('Secret', 'card'));
        self::put($this->kit . '/page/about/about.twig', $twig('About', 'card,secret'));
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
    public function without_a_key_the_usage_is_untouched(): void
    {
        $styleguide = $this->catalogue('');

        self::assertSame(['home', 'secret', 'ghost-id', 'about'], self::usageOf($styleguide, 'components')['card']);
        self::assertSame(['card', 'secret'], self::usageOf($styleguide, 'pages')['about']);
    }

    #[Test]
    public function pages_include_drops_a_hidden_page_from_a_components_usage(): void
    {
        $styleguide = $this->catalogue("pages:\n  include: [home, about]\n");

        $components = self::usageOf($styleguide, 'components');
        self::assertSame(['home', 'about'], $components['card'], 'no hidden page, no unknown id');
        self::assertSame(['home'], $components['hidden']);
        $pages = self::usageOf($styleguide, 'pages');
        self::assertSame(['card', 'hidden'], $pages['home'], 'components are not filtered by this key');
        self::assertSame(['card'], $pages['about'], 'the hidden page is not named');
    }

    #[Test]
    public function both_keys_apply_together(): void
    {
        $styleguide = $this->catalogue("components:\n  include: [card]\npages:\n  include: [home]\n");

        $components = self::usageOf($styleguide, 'components');
        self::assertSame(['card'], array_keys($components));
        self::assertSame(['home'], $components['card']);
        self::assertSame(['card'], self::usageOf($styleguide, 'pages')['home']);
    }

    #[Test]
    public function the_cli_applies_both_filters(): void
    {
        file_put_contents($this->static . '/styleguide.yaml', "components:\n  include: [card]\npages:\n  include: [home]\n");
        $run = function (array $argv): string {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            (new Command())->run($argv, $stdout, $stderr);
            rewind($stdout);

            return (string) stream_get_contents($stdout);
        };
        $args = ['--templates=' . $this->kit, '--config=' . $this->static . '/styleguide.yaml'];

        $card = (array) json_decode($run(['show', 'card', ...$args]), true);
        self::assertSame(['home'], $card['usage']);

        $pages = (array) json_decode($run(['list', '--type=page', ...$args]), true);
        self::assertSame(['card'], array_column($pages, 'usage', 'id')['home']);
    }
}
