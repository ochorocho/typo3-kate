<?php

declare(strict_types=1);

namespace Bmack\K8sHealth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * /_health/live  -> process is up (liveness). Never checks dependencies,
 *                   otherwise a DB hiccup restarts every pod at once.
 * /_health/ready -> pod can serve traffic (readiness): DB + Valkey reachable.
 *                   Deliberately NOT the homepage: a broken content element
 *                   must not take all pods out of rotation.
 */
final class HealthCheck implements MiddlewareInterface
{
    public function __construct(private readonly ConnectionPool $connectionPool) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rtrim($request->getUri()->getPath(), '/');

        return match ($path) {
            '/_health/live' => new JsonResponse(['status' => 'ok']),
            '/_health/ready' => $this->readiness(),
            default => $handler->handle($request),
        };
    }

    private function readiness(): ResponseInterface
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'valkey' => $this->checkValkey(),
        ];
        $healthy = !in_array(false, $checks, true);

        return new JsonResponse(
            ['status' => $healthy ? 'ok' : 'fail', 'checks' => $checks],
            $healthy ? 200 : 503,
            ['Cache-Control' => 'no-store']
        );
    }

    private function checkDatabase(): bool
    {
        try {
            $this->connectionPool
                ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
                ->executeQuery('SELECT 1')
                ->fetchOne();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return bool|string true/false, or 'skipped' when Valkey is not configured
     */
    private function checkValkey(): bool|string
    {
        $host = getenv('TYPO3_VALKEY_HOST');
        if ($host === false || $host === '' || !class_exists(\Redis::class)) {
            return 'skipped';
        }
        try {
            $redis = new \Redis();
            $redis->connect($host, (int)(getenv('TYPO3_VALKEY_PORT') ?: 6379), 1.0);
            $password = getenv('TYPO3_VALKEY_PASSWORD');
            if ($password !== false && $password !== '') {
                $user = getenv('TYPO3_VALKEY_USER');
                $redis->auth($user !== false && $user !== '' ? [$user, $password] : $password);
            }
            $pong = $redis->ping();
            $redis->close();
            return $pong === true || $pong === '+PONG' || $pong === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }
}
