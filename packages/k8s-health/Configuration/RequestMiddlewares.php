<?php

/**
 * Registered at the very top of the frontend stack: health checks must not
 * depend on site resolution, the trusted hosts check (probes use the pod IP
 * as Host header) or page rendering.
 */
return [
    'frontend' => [
        'bmack/k8s-health' => [
            'target' => \Bmack\K8sHealth\Middleware\HealthCheck::class,
            'before' => [
                'typo3/cms-core/verify-host-header',
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
];
