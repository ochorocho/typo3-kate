<?php

// One codebase, two ways to run it:
//  - DDEV web container: development settings below, settings.php from `typo3 setup`
//  - everywhere else (the image in k3s, staging, production): kubernetes.php,
//    configured entirely through environment variables
if (getenv('IS_DDEV_PROJECT') === 'true') {
    $GLOBALS['TYPO3_CONF_VARS'] = array_replace_recursive(
        $GLOBALS['TYPO3_CONF_VARS'],
        [
            'GFX' => [
                'processor' => 'ImageMagick',
                'processor_path' => '/usr/bin/',
                'processor_path_lzw' => '/usr/bin/',
            ],
            'MAIL' => [
                'transport' => 'smtp',
                'transport_smtp_encrypt' => false,
                'transport_smtp_server' => 'localhost:1025',
            ],
            'SYS' => [
                'trustedHostsPattern' => '.*.*',
                'devIPmask' => '*',
                'displayErrors' => 1,
                // One process at a time is enough locally: TYPO3's own locks.
                // Locks in Valkey are tested in k3s (kubernetes.php).
                'locking' => [
                    'redis' => ['disabled' => true],
                ],
            ],
        ]
    );
    return;
}

require __DIR__ . '/kubernetes.php';

// Also for the install tool, which doesn't load ext_localconf.php, and for
// loggers created during bootstrap: no FileWriter is created at all.
\B13\StderrLogging\StderrLogging::configure();
