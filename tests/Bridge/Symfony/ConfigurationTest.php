<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Bridge\Symfony;

use Parisek\Styleguide\Bridge\Symfony\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * The bundle's config tree refuses a bad `twig_extensions` value when the
 * container compiles, not at the first catalogue request.
 */
final class ConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    #[Test]
    public function a_list_of_service_ids_is_accepted(): void
    {
        $out = self::process(['config' => '/tmp/styleguide.yaml', 'twig_extensions' => ['app.url', 'app.asset']]);

        self::assertSame(['app.url', 'app.asset'], $out['twig_extensions']);
    }

    #[Test]
    public function a_missing_key_is_accepted(): void
    {
        self::assertSame([], self::process(['config' => '/tmp/styleguide.yaml'])['twig_extensions']);
    }

    #[Test]
    public function a_map_is_refused_and_the_message_names_the_key(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('styleguide.twig_extensions');

        self::process(['config' => '/tmp/styleguide.yaml', 'twig_extensions' => ['url' => 'App\\Styleguide\\UrlExtension']]);
    }

    #[Test]
    public function a_list_with_gaps_in_its_keys_is_refused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('styleguide.twig_extensions');

        self::process(['config' => '/tmp/styleguide.yaml', 'twig_extensions' => [1 => 'app.url']]);
    }
}
