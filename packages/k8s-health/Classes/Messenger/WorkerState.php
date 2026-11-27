<?php

declare(strict_types=1);

namespace Bmack\K8sHealth\Messenger;

/**
 * What one messenger:consume process last reported (one row per pod).
 */
final readonly class WorkerState
{
    public function __construct(
        public string $hostname,
        public int $startedAt,
        public int $lastSeen,
        public int $stoppedAt,
        public int $handled,
        public int $failed,
        public string $lastError,
    ) {}

    public function isStopped(): bool
    {
        return $this->stoppedAt > 0;
    }
}
