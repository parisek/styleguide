<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_autoload_works(): void
    {
        self::assertTrue(class_exists(\PHPUnit\Framework\TestCase::class));
    }
}
