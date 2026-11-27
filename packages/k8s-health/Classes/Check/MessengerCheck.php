<?php

declare(strict_types=1);

namespace Bmack\K8sHealth\Check;

use Bmack\K8sHealth\Messenger\WorkerHeartbeat;
use Bmack\K8sHealth\Messenger\WorkerRegistry;
use Bmack\K8sHealth\Messenger\WorkerState;
use Psr\Clock\ClockInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The messenger worker (from its heartbeats) and the doctrine transport's queue.
 */
final readonly class MessengerCheck
{
    /** Three missed heartbeats */
    public const int STALE_AFTER = 3 * WorkerHeartbeat::INTERVAL;
    /** The worker exits after --time-limit and Kubernetes restarts it */
    public const int RESTART_GRACE = 60;
    public const int QUEUE_WARNING_AGE = 300;
    public const int QUEUE_ERROR_AGE = 1800;
    private const string QUEUE_TABLE = 'sys_messenger_messages';

    public function __construct(
        private WorkerRegistry $registry,
        private ConnectionPool $connectionPool,
        private ClockInterface $clock,
    ) {}

    public function checkWorkers(): CheckResult
    {
        try {
            $workers = $this->registry->findAll();
        } catch (\Throwable) {
            return CheckResult::error('messenger.workers.unavailable');
        }
        return $this->evaluateWorkers($workers, $this->clock->now()->getTimestamp());
    }

    /**
     * @param list<WorkerState> $workers newest first
     */
    public function evaluateWorkers(array $workers, int $now): CheckResult
    {
        if ($workers === []) {
            return CheckResult::skipped('messenger.workers.none');
        }
        $alive = array_values(array_filter(
            $workers,
            static fn(WorkerState $worker): bool => !$worker->isStopped() && $now - $worker->lastSeen <= self::STALE_AFTER
        ));
        if ($alive !== []) {
            $failing = array_values(array_filter($alive, static fn(WorkerState $worker): bool => $worker->failed > 0));
            if ($failing !== []) {
                return CheckResult::warning('messenger.workers.failures', $failing[0]->failed, $failing[0]->lastError);
            }
            $handled = array_sum(array_map(static fn(WorkerState $worker): int => $worker->handled, $alive));
            $names = implode(', ', array_map(static fn(WorkerState $worker): string => $worker->hostname, $alive));
            return CheckResult::ok('messenger.workers.running', count($alive), $names, $handled);
        }
        $latest = $workers[0];
        $silence = $now - $latest->lastSeen;
        if (!$latest->isStopped()) {
            // no clean stop: killed, OOM, or stuck in a long message
            return CheckResult::error('messenger.workers.stale', $latest->hostname, Duration::format($silence));
        }
        if ($silence <= self::RESTART_GRACE) {
            return CheckResult::ok('messenger.workers.restarting', $latest->hostname);
        }
        return CheckResult::error('messenger.workers.stopped', Duration::format($silence));
    }

    public function checkQueue(): CheckResult
    {
        $nowUtc = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::QUEUE_TABLE);
            $queryBuilder->getRestrictions()->removeAll();
            $row = $queryBuilder
                ->selectLiteral('COUNT(*) AS waiting', 'MIN(available_at) AS oldest')
                ->from(self::QUEUE_TABLE)
                ->where(
                    $queryBuilder->expr()->isNull('delivered_at'),
                    $queryBuilder->expr()->lte('available_at', $queryBuilder->createNamedParameter($nowUtc->format('Y-m-d H:i:s')))
                )
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Throwable) {
            return CheckResult::error('messenger.queue.unavailable');
        }
        $waiting = (int)($row['waiting'] ?? 0);
        if ($waiting === 0 || empty($row['oldest'])) {
            return CheckResult::ok('messenger.queue.empty');
        }
        $oldest = new \DateTimeImmutable((string)$row['oldest'], new \DateTimeZone('UTC'));
        return $this->evaluateQueue($waiting, $nowUtc->getTimestamp() - $oldest->getTimestamp());
    }

    public function evaluateQueue(int $waiting, int $oldestAge): CheckResult
    {
        if ($waiting === 0) {
            return CheckResult::ok('messenger.queue.empty');
        }
        $age = Duration::format($oldestAge);
        return match (true) {
            $oldestAge > self::QUEUE_ERROR_AGE => CheckResult::error('messenger.queue.waiting', $waiting, $age),
            $oldestAge > self::QUEUE_WARNING_AGE => CheckResult::warning('messenger.queue.waiting', $waiting, $age),
            default => CheckResult::ok('messenger.queue.waiting', $waiting, $age),
        };
    }
}
