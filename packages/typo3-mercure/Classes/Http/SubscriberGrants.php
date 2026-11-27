<?php

declare(strict_types=1);

namespace Ochorocho\Mercure\Http;

/**
 * Collects, during one request, the private topics the page subscribes to.
 * The MercureMiddleware puts one into the request and turns it into the
 * subscriber cookie on the way out. Request-scoped, never a shared service.
 */
final class SubscriberGrants
{
    public const string ATTRIBUTE = 'mercure.subscriberGrants';

    /** @var array<string, true> */
    private array $topics = [];
    private bool $used = false;

    public function markUsed(): void
    {
        $this->used = true;
    }

    public function authorize(string ...$topics): void
    {
        $this->used = true;
        foreach ($topics as $topic) {
            $this->topics[$topic] = true;
        }
    }

    public function isUsed(): bool
    {
        return $this->used;
    }

    /**
     * @return list<string>
     */
    public function getTopics(): array
    {
        return array_map(strval(...), array_keys($this->topics));
    }
}
