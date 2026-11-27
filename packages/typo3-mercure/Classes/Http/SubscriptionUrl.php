<?php

declare(strict_types=1);

namespace Ochorocho\Mercure\Http;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\ProtocolVersion;

/**
 * The hub URL a browser subscribes to, as the Twig function mercure() of
 * symfony/mercure builds it: "match" parameters for Mercure 1.0, "topic"
 * parameters for 0.x.
 */
final class SubscriptionUrl
{
    /**
     * @param list<string> $topics exact topics
     */
    public static function build(HubInterface $hub, array $topics, string $lastEventId = ''): string
    {
        $parameter = $hub->getProtocolVersion() === ProtocolVersion::V1 ? 'match' : 'topic';
        $query = [];
        foreach ($topics as $topic) {
            $query[] = $parameter . '=' . rawurlencode($topic);
        }
        if ($lastEventId !== '') {
            $query[] = 'lastEventID=' . rawurlencode($lastEventId);
        }
        $url = $hub->getPublicUrl();
        if ($query === []) {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . implode('&', $query);
    }
}
