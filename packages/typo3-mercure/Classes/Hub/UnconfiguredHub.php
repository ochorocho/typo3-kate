<?php

declare(strict_types=1);

namespace Ochorocho\Mercure\Hub;

use Ochorocho\Mercure\Configuration\MercureConfiguration;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * Stands in when no hub is configured, so that services depending on the hub
 * can still be built. Publishing fails with a message saying what is missing.
 */
final readonly class UnconfiguredHub implements HubInterface
{
    public function __construct(private MercureConfiguration $configuration) {}

    public function getPublicUrl(): string
    {
        return $this->configuration->getPublicUrl();
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        throw new RuntimeException(
            'Mercure is not configured: set MERCURE_URL and MERCURE_PUBLISHER_JWT_KEY, or hubUrl and publisherJwtKey in the extension configuration of "mercure".'
        );
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->configuration->protocolVersion;
    }

    public function getCookieName(): string
    {
        return $this->configuration->cookieName !== ''
            ? $this->configuration->cookieName
            : $this->configuration->protocolVersion->getDefaultCookieName();
    }
}
