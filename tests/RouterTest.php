<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    #[Test]
    public function parses_landing(): void
    {
        self::assertSame(['type' => 'landing'], Router::parse('/styleguide'));
        self::assertSame(['type' => 'landing'], Router::parse('/styleguide/'));
    }

    #[Test]
    public function parses_component_deep_link(): void
    {
        self::assertSame(
            ['type' => 'component', 'slug' => 'hero'],
            Router::parse('/styleguide/component/hero')
        );
    }

    #[Test]
    public function parses_page_deep_link(): void
    {
        self::assertSame(
            ['type' => 'page', 'slug' => 'homepage'],
            Router::parse('/styleguide/page/homepage')
        );
    }

    #[Test]
    public function parses_overview_foundations_and_fields(): void
    {
        // /overview is the SPA-only Components & Pages index (kind=overview is
        // never dispatched to Renderer — Styleguide::dispatchSpa handles it).
        self::assertSame(['type' => 'overview'], Router::parse('/styleguide/overview'));
        // /foundations renders the logo/colors/typography page in the iframe
        // (renamed from /overview in the slug swap).
        self::assertSame(['type' => 'foundations'], Router::parse('/styleguide/foundations'));
        self::assertSame(['type' => 'fields'], Router::parse('/styleguide/fields'));
    }

    #[Test]
    public function parses_render_endpoint(): void
    {
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero'],
            Router::parse('/styleguide/render/component/hero')
        );
        self::assertSame(
            ['type' => 'render', 'kind' => 'page', 'slug' => 'homepage'],
            Router::parse('/styleguide/render/page/homepage')
        );
    }

    #[Test]
    public function parses_api_endpoints(): void
    {
        self::assertSame(['type' => 'api', 'endpoint' => 'components'], Router::parse('/styleguide/api/components'));
        self::assertSame(['type' => 'api', 'endpoint' => 'pages'], Router::parse('/styleguide/api/pages'));
        self::assertSame(['type' => 'api', 'endpoint' => 'fields'], Router::parse('/styleguide/api/fields'));
    }

    #[Test]
    public function parses_asset_paths(): void
    {
        self::assertSame(
            ['type' => 'asset', 'path' => 'styleguide.abc.css'],
            Router::parse('/styleguide/assets/styleguide.abc.css')
        );
        self::assertSame(
            ['type' => 'asset', 'path' => 'locales/cs.json'],
            Router::parse('/styleguide/assets/locales/cs.json')
        );
    }

    #[Test]
    public function strips_query_string(): void
    {
        self::assertSame(
            ['type' => 'component', 'slug' => 'hero'],
            Router::parse('/styleguide/component/hero?lang=cs')
        );
    }

    #[Test]
    public function returns_null_for_non_styleguide_urls(): void
    {
        self::assertNull(Router::parse('/about'));
        self::assertNull(Router::parse('/'));
        self::assertNull(Router::parse('/styleguidedark')); // not a real /styleguide/ prefix
    }

    #[Test]
    public function parses_theme_on_render_routes(): void
    {
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero', 'theme' => 'dark'],
            Router::parse('/styleguide/render/component/hero?theme=dark')
        );
        self::assertSame(
            ['type' => 'render', 'kind' => 'page', 'slug' => 'home', 'theme' => 'light'],
            Router::parse('/styleguide/render/page/home?theme=light')
        );
    }

    #[Test]
    public function ignores_invalid_theme_values(): void
    {
        // Unknown string, empty value, garbage — none should land on the route.
        // The strict whitelist matters because the value flows into HTML as a
        // class name; only the two known modes are honored.
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero'],
            Router::parse('/styleguide/render/component/hero?theme=neon')
        );
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero'],
            Router::parse('/styleguide/render/component/hero?theme=')
        );
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero'],
            Router::parse('/styleguide/render/component/hero')
        );
    }

    #[Test]
    public function parse_theme_helper_whitelists_values(): void
    {
        self::assertSame('dark', Router::parseTheme('/anything?theme=dark'));
        self::assertSame('light', Router::parseTheme('/anything?theme=light&extra=ignored'));
        self::assertNull(Router::parseTheme('/anything?theme=neon'));
        self::assertNull(Router::parseTheme('/anything?theme='));
        self::assertNull(Router::parseTheme('/anything'));
        self::assertNull(Router::parseTheme('/anything?lang=cs'));
    }
}
