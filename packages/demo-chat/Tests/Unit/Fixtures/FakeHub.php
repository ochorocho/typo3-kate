<?php

declare(strict_types=1);

namespace Ochorocho\KateDemoChat\Tests\Unit\Fixtures;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * Records what is published; publish() throws with $failWith set.
 */
final class FakeHub implements HubInterface
{
    /** @var list<Update> */
    public array $published = [];

    public function __construct(
        private readonly string $publicUrl = 'https://example.com/.well-known/mercure',
        private readonly ?TokenFactoryInterface $factory = null,
        public ?\Throwable $failWith = null,
    ) {}

    public function getPublicUrl(): string
    {
        return $this->publicUrl;
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->factory;
    }

    public function publish(Update $update): string
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->published[] = $update;
        return 'urn:uuid:' . count($this->published);
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::V1;
    }

    public function getCookieName(): string
    {
        return '__Secure-mercure_access_token';
    }
}
