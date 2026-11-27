<?php

declare(strict_types=1);

namespace Bmack\K8sHealth\Messenger;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Heartbeats of the messenger workers, kept in the database so that every
 * backend pod sees the workers running in other pods.
 */
final readonly class WorkerRegistry
{
    public const string TABLE = 'tx_k8shealth_messenger_worker';

    public function __construct(private ConnectionPool $connectionPool) {}

    public function started(string $hostname, int $now): void
    {
        $this->write($hostname, [
            'started_at' => $now,
            'last_seen' => $now,
            'stopped_at' => 0,
            'handled' => 0,
            'failed' => 0,
            'last_error' => '',
        ]);
    }

    public function beat(string $hostname, int $now, int $handled, int $failed, string $lastError): void
    {
        $this->write($hostname, [
            'last_seen' => $now,
            'handled' => $handled,
            'failed' => $failed,
            'last_error' => mb_substr($lastError, 0, 1000),
        ]);
    }

    public function stopped(string $hostname, int $now, int $handled, int $failed, string $lastError): void
    {
        $this->write($hostname, [
            'last_seen' => $now,
            'stopped_at' => $now,
            'handled' => $handled,
            'failed' => $failed,
            'last_error' => mb_substr($lastError, 0, 1000),
        ]);
    }

    /**
     * Pods get new names on every rollout: forget the old ones.
     */
    public function prune(int $lastSeenBefore): void
    {
        $queryBuilder = $this->connection()->createQueryBuilder();
        $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt(
                'last_seen',
                $queryBuilder->createNamedParameter($lastSeenBefore, Connection::PARAM_INT)
            ))
            ->executeStatement();
    }

    /**
     * @return list<WorkerState>
     */
    public function findAll(): array
    {
        $rows = $this->connection()->select(['*'], self::TABLE, [], [], ['last_seen' => 'DESC'])->fetchAllAssociative();
        return array_map(
            static fn(array $row): WorkerState => new WorkerState(
                (string)$row['hostname'],
                (int)$row['started_at'],
                (int)$row['last_seen'],
                (int)$row['stopped_at'],
                (int)$row['handled'],
                (int)$row['failed'],
                (string)$row['last_error'],
            ),
            $rows
        );
    }

    /**
     * @param array<string, int|string> $values
     */
    private function write(string $hostname, array $values): void
    {
        $connection = $this->connection();
        if ($connection->count('*', self::TABLE, ['hostname' => $hostname]) > 0) {
            $connection->update(self::TABLE, $values, ['hostname' => $hostname]);
            return;
        }
        $connection->insert(self::TABLE, ['hostname' => $hostname] + $values);
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
