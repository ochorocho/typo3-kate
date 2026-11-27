<?php

declare(strict_types=1);

namespace Ochorocho\KateImgproxy\Tests\Unit\Configuration;

use Ochorocho\KateImgproxy\Configuration\MediaProcessingConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MediaProcessingConfigurationTest extends TestCase
{
    private static function env(array $variables): \Closure
    {
        return static fn(string $name): string|false => $variables[$name] ?? false;
    }

    private const array ON = [
        'TYPO3_IMGPROXY_URL' => 'http://typo3-imgproxy:8080/',
        'TYPO3_IMGPROXY_SOURCE_URL' => 'https://shop.example.com/',
        'IMGPROXY_KEY' => 'abcd',
        'IMGPROXY_SALT' => 'ef01',
    ];

    #[Test]
    public function withoutImgproxyUrlNothingIsConfigured(): void
    {
        self::assertNull(MediaProcessingConfiguration::fromEnvironment(self::env([])));
    }

    #[Test]
    public function imgproxyIsTheProviderWithStorageAndSigning(): void
    {
        $configuration = MediaProcessingConfiguration::fromEnvironment(self::env(self::ON));

        self::assertSame('imgproxy', $configuration['common']['provider']);
        self::assertSame('1', $configuration['common']['storage']);
        self::assertSame('http://typo3-imgproxy:8080', $configuration['provider']['imgproxy']['api_endpoint']);
        self::assertSame('uri', $configuration['provider']['imgproxy']['source_loader']);
        self::assertSame('https://shop.example.com', $configuration['provider']['imgproxy']['source_uri']);
        self::assertSame('1', $configuration['provider']['imgproxy']['signature']);
        self::assertSame('abcd', $configuration['provider']['imgproxy']['signature_key']);
        self::assertSame('ef01', $configuration['provider']['imgproxy']['signature_salt']);
    }

    #[Test]
    public function missingKeysFail(): void
    {
        $this->expectExceptionCode(1795100001);

        MediaProcessingConfiguration::fromEnvironment(self::env(['IMGPROXY_KEY' => ''] + self::ON));
    }

    #[Test]
    public function onlyUrlsOfTheSitesImgproxyMatch(): void
    {
        $env = self::env(self::ON);

        self::assertTrue(MediaProcessingConfiguration::isImgproxyUrl('http://typo3-imgproxy:8080/sig/rs:fit:100/plain/x', $env));
        self::assertFalse(MediaProcessingConfiguration::isImgproxyUrl('http://typo3-imgproxy:8080.evil/x', $env));
        self::assertFalse(MediaProcessingConfiguration::isImgproxyUrl('https://cdn.example.com/x', $env));
        self::assertFalse(MediaProcessingConfiguration::isImgproxyUrl('http://typo3-imgproxy:8080/x', self::env([])));
    }
}
