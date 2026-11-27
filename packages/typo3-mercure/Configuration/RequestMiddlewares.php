<?php

use Ochorocho\Mercure\Http\MercureMiddleware;

/**
 * After authentication, so the subscriber cookie can name the user.
 */
return [
    'frontend' => [
        'ochorocho/mercure' => [
            'target' => MercureMiddleware::class,
            'after' => ['typo3/cms-frontend/authentication'],
        ],
    ],
    'backend' => [
        'ochorocho/mercure' => [
            'target' => MercureMiddleware::class,
            'after' => ['typo3/cms-backend/authentication'],
        ],
    ],
];
