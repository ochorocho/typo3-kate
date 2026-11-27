<?php

declare(strict_types=1);

namespace Bmack\K8sHealth\Tests\Unit\Check;

use Bmack\K8sHealth\Check\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    public static function durations(): iterable
    {
        yield 'negative clock skew' => [-5, '0 s'];
        yield 'seconds' => [45, '45 s'];
        yield 'minutes' => [600, '10 min'];
        yield 'hours' => [10800, '3 h'];
    }

    #[Test]
    #[DataProvider('durations')]
    public function formatsShortly(int $seconds, string $expected): void
    {
        self::assertSame($expected, Duration::format($seconds));
    }
}
