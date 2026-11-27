<?php

declare(strict_types=1);

namespace Ochorocho\Mercure\Hub;

use Ochorocho\Mercure\Configuration\MercureConfiguration;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\DefaultClaimsTokenFactory;
use Symfony\Component\Mercure\Jwt\FactoryTokenProvider;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

/**
 * Builds the hub from the configuration, what MercureBundle does in Symfony.
 * Tokens are minted per publish and per cookie, nothing is cached: safe for
 * long-running workers.
 */
final class HubFactory
{
    private const float HTTP_TIMEOUT = 5.0;

    public static function create(MercureConfiguration $configuration): HubInterface
    {
        if (!$configuration->isConfigured()) {
            return new UnconfiguredHub($configuration);
        }
        return new Hub(
            url: $configuration->hubUrl,
            jwtProvider: new FactoryTokenProvider(
                self::tokenFactory($configuration, $configuration->publisherJwtKey),
                [new Grant([Grant::ACTION_PUBLISH], ['*'])],
            ),
            jwtFactory: self::tokenFactory($configuration, $configuration->getSubscriberJwtKey()),
            publicUrl: $configuration->getPublicUrl(),
            httpClient: HttpClient::create(['timeout' => self::HTTP_TIMEOUT]),
            cookieName: $configuration->cookieName !== '' ? $configuration->cookieName : null,
            protocolVersion: $configuration->protocolVersion,
        );
    }

    /**
     * Mercure 1.0 tokens are RFC 9068 access tokens: iss, aud, sub and
     * client_id are required. "sub" may be overridden per cookie.
     */
    private static function tokenFactory(MercureConfiguration $configuration, string $key): TokenFactoryInterface
    {
        return new DefaultClaimsTokenFactory(
            new LcobucciFactory($key, 'hmac.sha256', $configuration->jwtLifetime, '', $configuration->protocolVersion),
            [
                'iss' => $configuration->getIssuer(),
                'aud' => $configuration->getPublicUrl(),
                'sub' => 'typo3',
                'client_id' => 'typo3',
            ]
        );
    }
}
