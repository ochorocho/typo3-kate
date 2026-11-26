<?php

// settings.php of the production image. Copied to config/system/settings.php
// by docker/Dockerfile. Deliberately empty of anything environment-specific:
// credentials, hosts and keys come from environment variables (kubernetes.php).
// TYPO3 never writes this file at runtime, the image's filesystem is read-only.
return [
    'BE' => [
        'installToolPassword' => '',
    ],
    'DB' => [
        'Connections' => [
            'Default' => [
                'driver' => 'mysqli',
            ],
        ],
    ],
    'GFX' => [
        'processor' => 'GraphicsMagick',
        'processor_path' => '/usr/bin/',
    ],
    'SYS' => [
        'sitename' => 'K8s Starter',
        // Not a secret: kubernetes.php always replaces it with TYPO3_ENCRYPTION_KEY
        // (empty when unset, then TYPO3 refuses to start). It only has to be non-empty,
        // or the silent configuration upgrade in `upgrade:run` writes a random key here.
        'encryptionKey' => 'set-from-env-TYPO3_ENCRYPTION_KEY',
        'features' => [],
    ],
];
