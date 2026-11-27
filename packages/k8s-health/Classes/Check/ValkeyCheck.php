<?php

declare(strict_types=1);

namespace Bmack\K8sHealth\Check;

/**
 * Valkey as configured by config/system/kubernetes.php: TYPO3_VALKEY_HOST for
 * sessions and locks, optionally TYPO3_VALKEY_CACHE_HOST for the caches.
 */
final readonly class ValkeyCheck
{
    private const float TIMEOUT = 1.0;

    /**
     * Sessions and locks: without it, nobody can log in.
     */
    public function checkSessions(): CheckResult
    {
        $host = self::env('TYPO3_VALKEY_HOST');
        if ($host === null) {
            return CheckResult::skipped('valkey.notConfigured');
        }
        return $this->ping($host);
    }

    /**
     * Caches, only when a dedicated instance is configured.
     */
    public function checkCaches(): ?CheckResult
    {
        $host = self::env('TYPO3_VALKEY_CACHE_HOST');
        if ($host === null || $host === self::env('TYPO3_VALKEY_HOST')) {
            return null;
        }
        return $this->ping($host);
    }

    private function ping(string $host): CheckResult
    {
        if (!class_exists(\Redis::class)) {
            return CheckResult::error('valkey.noExtension');
        }
        $port = (int)(self::env('TYPO3_VALKEY_PORT') ?? 6379);
        try {
            $redis = new \Redis();
            $redis->connect($host, $port, self::TIMEOUT);
            $password = self::env('TYPO3_VALKEY_PASSWORD');
            if ($password !== null) {
                $user = self::env('TYPO3_VALKEY_USER');
                $redis->auth($user !== null ? [$user, $password] : $password);
            }
            $pong = $redis->ping();
            $redis->close();
        } catch (\Throwable) {
            return CheckResult::error('valkey.unreachable', $host . ':' . $port);
        }
        return in_array($pong, [true, '+PONG', 'PONG'], true)
            ? CheckResult::ok('valkey.ok', $host . ':' . $port)
            : CheckResult::error('valkey.unreachable', $host . ':' . $port);
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? null : $value;
    }
}
