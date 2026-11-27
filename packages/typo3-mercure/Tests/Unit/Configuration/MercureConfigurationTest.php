<?php

declare(strict_types=1);

namespace Ochorocho\Mercure\Tests\Unit\Configuration;

use Ochorocho\Mercure\Configuration\MercureConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\ProtocolVersion;

final class MercureConfigurationTest extends TestCase
{
    private static function env(array $variables): \Closure
    {
        return static fn(string $name): string|false => $variables[$name] ?? false;
    }

    #[Test]
    public function emptySettingsFallBackToTheEnvironment(): void
    {
        $subject = MercureConfiguration::fromArray(['hubUrl' => ''], self::env([
            'MERCURE_URL' => 'http://typo3-mercure/.well-known/mercure',
            'MERCURE_PUBLIC_URL' => 'https://www.example.com/.well-known/mercure',
            'MERCURE_PUBLISHER_JWT_KEY' => 'pub',
        ]));

        self::assertTrue($subject->isConfigured());
        self::assertSame('http://typo3-mercure/.well-known/mercure', $subject->hubUrl);
        self::assertSame('https://www.example.com/.well-known/mercure', $subject->getPublicUrl());
    }

    #[Test]
    public function settingsWinOverTheEnvironment(): void
    {
        $subject = MercureConfiguration::fromArray(
            ['hubUrl' => 'https://hub.test/.well-known/mercure'],
            self::env(['MERCURE_URL' => 'http://other'])
        );

        self::assertSame('https://hub.test/.well-known/mercure', $subject->hubUrl);
    }

    #[Test]
    public function withoutPublisherKeyItIsNotConfigured(): void
    {
        $subject = MercureConfiguration::fromArray([], self::env(['MERCURE_URL' => 'http://hub']));

        self::assertFalse($subject->isConfigured());
    }

    #[Test]
    public function issuerDefaultsToTheOriginOfThePublicUrl(): void
    {
        $subject = MercureConfiguration::fromArray(
            ['publicUrl' => 'https://www.example.com:8443/.well-known/mercure'],
            self::env([])
        );

        self::assertSame('https://www.example.com:8443', $subject->getIssuer());
    }

    #[Test]
    public function subscriberKeyDefaultsToThePublisherKey(): void
    {
        $subject = MercureConfiguration::fromArray(['publisherJwtKey' => 'pub'], self::env([]));

        self::assertSame('pub', $subject->getSubscriberJwtKey());
    }

    #[Test]
    public function protocolVersionAndTransportAreRead(): void
    {
        $subject = MercureConfiguration::fromArray(['protocolVersion' => '0.x', 'transport' => ''], self::env([]));

        self::assertSame(ProtocolVersion::Legacy, $subject->protocolVersion);
        self::assertSame('', $subject->transport);
    }

    #[Test]
    public function unknownProtocolVersionFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1795000001);

        MercureConfiguration::fromArray(['protocolVersion' => '2.0'], self::env([]));
    }
}
