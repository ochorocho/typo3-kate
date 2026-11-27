<?php

declare(strict_types=1);

namespace Ochorocho\Mercure\Configuration;

use Symfony\Component\Mercure\ProtocolVersion;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The extension configuration of "mercure", each empty value filled from its
 * environment variable. The variable names match the Helm chart.
 */
final readonly class MercureConfiguration
{
    private const array ENVIRONMENT = [
        'hubUrl' => 'MERCURE_URL',
        'publicUrl' => 'MERCURE_PUBLIC_URL',
        'publisherJwtKey' => 'MERCURE_PUBLISHER_JWT_KEY',
        'subscriberJwtKey' => 'MERCURE_SUBSCRIBER_JWT_KEY',
        'jwtIssuer' => 'MERCURE_JWT_ISSUER',
    ];

    public function __construct(
        public string $hubUrl = '',
        public string $publicUrl = '',
        public ProtocolVersion $protocolVersion = ProtocolVersion::V1,
        public string $publisherJwtKey = '',
        public string $subscriberJwtKey = '',
        public string $jwtIssuer = '',
        public int $jwtLifetime = 3600,
        public string $cookieName = '',
        public string $transport = 'doctrine',
    ) {}

    public static function fromExtensionConfiguration(ExtensionConfiguration $extensionConfiguration): self
    {
        try {
            $settings = $extensionConfiguration->get('mercure');
        } catch (\Throwable) {
            $settings = [];
        }
        return self::fromArray(is_array($settings) ? $settings : [], getenv(...));
    }

    /**
     * @param array<string, mixed> $settings
     * @param callable(string): (string|false) $env
     */
    public static function fromArray(array $settings, callable $env): self
    {
        $value = static function (string $key) use ($settings, $env): string {
            $configured = trim((string)($settings[$key] ?? ''));
            if ($configured !== '' || !isset(self::ENVIRONMENT[$key])) {
                return $configured;
            }
            return trim((string)$env(self::ENVIRONMENT[$key]));
        };
        $protocolVersion = ProtocolVersion::tryFrom($value('protocolVersion'));
        if ($protocolVersion === null && $value('protocolVersion') !== '') {
            throw new \InvalidArgumentException(
                sprintf('Mercure protocol version "%s" is unknown, use "1.0" or "0.x".', $value('protocolVersion')),
                1795000001
            );
        }
        return new self(
            hubUrl: $value('hubUrl'),
            publicUrl: $value('publicUrl'),
            protocolVersion: $protocolVersion ?? ProtocolVersion::V1,
            publisherJwtKey: $value('publisherJwtKey'),
            subscriberJwtKey: $value('subscriberJwtKey'),
            jwtIssuer: $value('jwtIssuer'),
            jwtLifetime: max(1, (int)($settings['jwtLifetime'] ?? 3600)),
            cookieName: $value('cookieName'),
            transport: array_key_exists('transport', $settings) ? trim((string)$settings['transport']) : 'doctrine',
        );
    }

    public function isConfigured(): bool
    {
        return $this->hubUrl !== '' && $this->publisherJwtKey !== '';
    }

    public function getPublicUrl(): string
    {
        return $this->publicUrl !== '' ? $this->publicUrl : $this->hubUrl;
    }

    public function getSubscriberJwtKey(): string
    {
        return $this->subscriberJwtKey !== '' ? $this->subscriberJwtKey : $this->publisherJwtKey;
    }

    /**
     * Must be one of the hub's MERCURE_TRUSTED_ISSUERS (Mercure 1.0).
     */
    public function getIssuer(): string
    {
        if ($this->jwtIssuer !== '') {
            return $this->jwtIssuer;
        }
        $url = parse_url($this->getPublicUrl());
        if (!isset($url['scheme'], $url['host'])) {
            return '';
        }
        return $url['scheme'] . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
    }
}
