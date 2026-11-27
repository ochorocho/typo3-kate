<?php

declare(strict_types=1);

namespace Bmack\K8sHealth\Middleware;

use Bmack\K8sHealth\Check\CheckResult;
use Bmack\K8sHealth\Check\DatabaseCheck;
use Bmack\K8sHealth\Check\Status;
use Bmack\K8sHealth\Check\ValkeyCheck;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * /_health/live  -> process is up (liveness). Never checks dependencies,
 *                   otherwise a DB hiccup restarts every pod at once.
 * /_health/ready -> pod can serve traffic (readiness): DB + Valkey reachable.
 *                   Deliberately NOT the homepage: a broken content element
 *                   must not take all pods out of rotation. Neither the
 *                   messenger worker nor Mercure count: pages work without them.
 */
final readonly class HealthCheck implements MiddlewareInterface
{
    public function __construct(
        private DatabaseCheck $databaseCheck,
        private ValkeyCheck $valkeyCheck,
    ) {}

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
        $results = [
            'database' => $this->databaseCheck->check(),
            'valkey' => $this->valkeyCheck->checkSessions(),
        ];
        $healthy = array_filter($results, static fn(CheckResult $result): bool => $result->status === Status::Error) === [];

        return new JsonResponse(
            [
                'status' => $healthy ? 'ok' : 'fail',
                // true / false / 'skipped', as before
                'checks' => array_map(
                    static fn(CheckResult $result): bool|string => $result->status === Status::Skipped ? 'skipped' : $result->status !== Status::Error,
                    $results
                ),
            ],
            $healthy ? 200 : 503,
            ['Cache-Control' => 'no-store']
        );
    }
}
