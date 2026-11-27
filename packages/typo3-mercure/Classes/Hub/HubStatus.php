<?php

declare(strict_types=1);

namespace Ochorocho\Mercure\Hub;

use Ochorocho\Mercure\Configuration\MercureConfiguration;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Whether the hub answers at its internal URL. A GET without any topic is
 * refused by the hub (400/401): that is the answer we want, and nothing is
 * subscribed or published.
 */
final readonly class HubStatus
{
    public const string NOT_CONFIGURED = 'notConfigured';
    public const string REACHABLE = 'reachable';
    public const string NOT_A_HUB = 'notAHub';
    public const string UNREACHABLE = 'unreachable';

    private HttpClientInterface $httpClient;

    public function __construct(
        private MercureConfiguration $configuration,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => 1.0, 'max_duration' => 2.0]);
    }

    public function check(): string
    {
        if (!$this->configuration->isConfigured()) {
            return self::NOT_CONFIGURED;
        }
        try {
            $status = $this->httpClient->request('GET', $this->configuration->hubUrl, ['max_redirects' => 0])->getStatusCode();
        } catch (\Throwable) {
            return self::UNREACHABLE;
        }
        return match (true) {
            $status === 404 => self::NOT_A_HUB,
            $status < 500 => self::REACHABLE,
            default => self::UNREACHABLE,
        };
    }
}
