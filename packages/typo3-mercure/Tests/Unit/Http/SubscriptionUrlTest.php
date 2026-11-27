<?php

declare(strict_types=1);

namespace Ochorocho\Mercure\Tests\Unit\Http;

use Ochorocho\Mercure\Configuration\MercureConfiguration;
use Ochorocho\Mercure\Http\SubscriptionUrl;
use Ochorocho\Mercure\Hub\UnconfiguredHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\ProtocolVersion;

final class SubscriptionUrlTest extends TestCase
{
    private const string HUB = 'https://www.example.com/.well-known/mercure';

    #[Test]
    public function protocolOneUsesMatchParameters(): void
    {
        $hub = new UnconfiguredHub(new MercureConfiguration(publicUrl: self::HUB));

        self::assertSame(
            self::HUB . '?match=https%3A%2F%2Fexample.com%2Fbooks%2F1&match=chat',
            SubscriptionUrl::build($hub, ['https://example.com/books/1', 'chat'])
        );
    }

    #[Test]
    public function legacyProtocolUsesTopicParameters(): void
    {
        $hub = new UnconfiguredHub(new MercureConfiguration(publicUrl: self::HUB, protocolVersion: ProtocolVersion::Legacy));

        self::assertSame(self::HUB . '?topic=chat&lastEventID=urn%3A1', SubscriptionUrl::build($hub, ['chat'], 'urn:1'));
    }

    #[Test]
    public function withoutTopicsItIsTheBareHubUrl(): void
    {
        $hub = new UnconfiguredHub(new MercureConfiguration(publicUrl: self::HUB));

        self::assertSame(self::HUB, SubscriptionUrl::build($hub, []));
    }
}
