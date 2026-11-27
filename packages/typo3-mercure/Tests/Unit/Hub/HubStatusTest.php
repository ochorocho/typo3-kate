<?php

declare(strict_types=1);

namespace Ochorocho\Mercure\Tests\Unit\Hub;

use Ochorocho\Mercure\Configuration\MercureConfiguration;
use Ochorocho\Mercure\Hub\HubStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HubStatusTest extends TestCase
{
    private static function configured(): MercureConfiguration
    {
        return new MercureConfiguration(hubUrl: 'http://typo3-mercure/.well-known/mercure', publisherJwtKey: 'key');
    }

    #[Test]
    public function withoutConfigurationNothingIsRequested(): void
    {
        $client = new MockHttpClient(static fn() => throw new \LogicException('no request expected'));

        self::assertSame(HubStatus::NOT_CONFIGURED, (new HubStatus(new MercureConfiguration(), $client))->check());
    }

    public static function responses(): iterable
    {
        yield 'missing topic' => [400, HubStatus::REACHABLE];
        yield 'no token' => [401, HubStatus::REACHABLE];
        yield 'wrong path' => [404, HubStatus::NOT_A_HUB];
        yield 'broken hub' => [502, HubStatus::UNREACHABLE];
    }

    #[Test]
    #[DataProvider('responses')]
    public function answersOfTheHubAreInterpreted(int $statusCode, string $expected): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => $statusCode]));

        self::assertSame($expected, (new HubStatus(self::configured(), $client))->check());
    }

    #[Test]
    public function connectionErrorsAreUnreachable(): void
    {
        $client = new MockHttpClient(static fn() => throw new TransportException('Connection refused'));

        self::assertSame(HubStatus::UNREACHABLE, (new HubStatus(self::configured(), $client))->check());
    }
}
