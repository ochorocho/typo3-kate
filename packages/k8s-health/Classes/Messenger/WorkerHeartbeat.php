<?php

declare(strict_types=1);

namespace Bmack\K8sHealth\Messenger;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Reports a running `messenger:consume` to the WorkerRegistry: on start, on
 * stop and every INTERVAL seconds in between. TYPO3 hands the worker its
 * PSR-14 dispatcher, so Symfony's worker events arrive here.
 *
 * Holds counters, so it only makes sense in the long-running CLI worker.
 * A failing heartbeat is logged, never thrown: it must not stop the worker.
 */
final class WorkerHeartbeat
{
    public const int INTERVAL = 30;

    private int $lastBeat = 0;
    private int $handled = 0;
    private int $failed = 0;
    private string $lastError = '';

    public function __construct(
        private readonly WorkerRegistry $registry,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    #[AsEventListener('k8s-health/worker-started')]
    public function onStarted(WorkerStartedEvent $event): void
    {
        $now = $this->now();
        $this->lastBeat = $now;
        $this->safely(function () use ($now): void {
            $this->registry->prune($now - 86400);
            $this->registry->started($this->hostname(), $now);
        });
    }

    #[AsEventListener('k8s-health/worker-handled')]
    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->handled++;
    }

    #[AsEventListener('k8s-health/worker-failed')]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }
        $this->failed++;
        $this->lastError = get_class($event->getEnvelope()->getMessage()) . ': ' . $event->getThrowable()->getMessage();
        // report failures right away, not with the next heartbeat
        $this->lastBeat = 0;
    }

    #[AsEventListener('k8s-health/worker-running')]
    public function onRunning(WorkerRunningEvent $event): void
    {
        $now = $this->now();
        if ($now - $this->lastBeat < self::INTERVAL) {
            return;
        }
        $this->lastBeat = $now;
        $this->safely(fn() => $this->registry->beat($this->hostname(), $now, $this->handled, $this->failed, $this->lastError));
    }

    #[AsEventListener('k8s-health/worker-stopped')]
    public function onStopped(WorkerStoppedEvent $event): void
    {
        $this->safely(fn() => $this->registry->stopped($this->hostname(), $this->now(), $this->handled, $this->failed, $this->lastError));
    }

    private function safely(\Closure $write): void
    {
        try {
            $write();
        } catch (\Throwable $e) {
            $this->logger->warning('Messenger heartbeat could not be written: {message}', ['message' => $e->getMessage()]);
        }
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function hostname(): string
    {
        return gethostname() ?: 'unknown';
    }
}
