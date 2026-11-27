<?php

declare(strict_types=1);

namespace Bmack\K8sHealth\Check;

use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class DatabaseCheck
{
    public function __construct(private ConnectionPool $connectionPool) {}

    public function check(): CheckResult
    {
        try {
            $this->connectionPool
                ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
                ->executeQuery('SELECT 1')
                ->fetchOne();
            return CheckResult::ok('database.ok');
        } catch (\Throwable) {
            return CheckResult::error('database.unreachable');
        }
    }
}
