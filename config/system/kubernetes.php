<?php

/**
 * Environment-driven TYPO3 configuration for Kubernetes (included from
 * additional.php everywhere except in the DDEV web container).
 *
 * Everything that differs between environments comes from environment
 * variables (ConfigMap / Secret in the Helm chart). Nothing here is written
 * at runtime – settings.php stays identical across all environments.
 *
 * Requires: ext-redis, b13/distributed-locks (Valkey-based locking),
 * b13/stderr-logging (logs as JSON lines to stderr)
 *
 * Tip: with b13/typo3-config this file can be split per TYPO3 context
 * (config/system/production.php, config/system/production/ddev.php, ...).
 */

defined('TYPO3') or die();

use B13\StderrLogging\Log\Writer\StderrWriter;
use TYPO3\CMS\Core\Cache\Backend\RedisBackend;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Session\Backend\RedisSessionBackend;

$env = static fn (string $name, ?string $default = null): ?string
    => ($value = getenv($name)) === false || $value === '' ? $default : $value;

// ---------------------------------------------------------------------------
// Secrets & basics
// ---------------------------------------------------------------------------
$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $env('TYPO3_ENCRYPTION_KEY', '');
// A password hash, e.g. php -r 'echo password_hash("...", PASSWORD_ARGON2ID), "\n";'
// Empty = no Install Tool login in the cluster.
$GLOBALS['TYPO3_CONF_VARS']['BE']['installToolPassword'] = $env('TYPO3_INSTALL_TOOL_PASSWORD', '');
$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = $env('TYPO3_TRUSTED_HOSTS_PATTERN', 'SERVER_NAME');
$GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors'] = 0;
$GLOBALS['TYPO3_CONF_VARS']['BE']['debug'] = false;
$GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] = false;

// Pods sit behind a gateway/ingress that terminates TLS.
if ($proxies = $env('TYPO3_REVERSE_PROXY_IP')) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP'] = $proxies;          // comma-separated IPs/ranges
    // 'last': take the address appended by the closest trusted proxy. Let the
    // gateway resolve the real client IP from trusted CDN ranges.
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyHeaderMultiValue'] = $env('TYPO3_REVERSE_PROXY_HEADER_MULTI_VALUE', 'last');
    // TLS ends at the gateway: the same proxies talk HTTPS to the client
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxySSL'] = $env('TYPO3_REVERSE_PROXY_SSL', $proxies);
}

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------
$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = array_replace(
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? [],
    [
        'driver'   => $env('TYPO3_DB_DRIVER', 'mysqli'),
        'host'     => $env('TYPO3_DB_HOST', 'mariadb-primary'),
        'port'     => (int)$env('TYPO3_DB_PORT', '3306'),
        'dbname'   => $env('TYPO3_DB_NAME', 'typo3'),
        'user'     => $env('TYPO3_DB_USER', 'typo3'),
        'password' => $env('TYPO3_DB_PASSWORD', ''),
        'charset'  => 'utf8mb4',
        'tableoptions' => ['charset' => 'utf8mb4', 'collate' => 'utf8mb4_unicode_ci'],
    ]
);
if ($env('TYPO3_DB_TLS') === '1') {
    // Encrypt traffic to the DB (pair with require_secure_transport=ON on the server)
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['driverOptions']['flags'] = MYSQLI_CLIENT_SSL;
}

// ---------------------------------------------------------------------------
// Valkey (Redis-compatible): shared caches, sessions, install tool sessions
// Use one Valkey database per purpose, so you can flush/inspect them separately.
// ---------------------------------------------------------------------------
$valkeyHost = $env('TYPO3_VALKEY_HOST');
if ($valkeyHost !== null) {
    $valkeyPort = (int)$env('TYPO3_VALKEY_PORT', '6379');
    $valkeyPassword = $env('TYPO3_VALKEY_PASSWORD');
    $valkeyCacheHost = $env('TYPO3_VALKEY_CACHE_HOST', $valkeyHost); // optional: dedicated cache instance

    // Caches that must be consistent across pods -> shared in Valkey.
    // Caches that only depend on the deployed code (core, di, fluid_template,
    // typoscript, l10n) stay pod-local in var/cache – they are rebuilt per pod.
    $sharedCaches = [
        'pages'      => 2,
        'pagesection'=> 3,
        'hash'       => 4,
        'rootline'   => 5,
        'imagesizes' => 6,
    ];
    foreach ($sharedCaches as $cacheName => $database) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$cacheName]['backend'] = RedisBackend::class;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$cacheName]['options'] = array_filter([
            'hostname' => $valkeyCacheHost,
            'port' => $valkeyPort,
            'database' => $database,
            'password' => $valkeyPassword,
            'defaultLifetime' => 86400,
            'compression' => true,
        ], static fn ($v) => $v !== null);
    }

    // Frontend + backend user sessions. TYPO3's default database sessions
    // already work across pods; Valkey takes this load off the database.
    foreach (['BE' => 0, 'FE' => 1] as $mode => $database) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['session'][$mode] = [
            'backend' => RedisSessionBackend::class,
            'options' => array_filter([
                'hostname' => $valkeyHost,
                'port' => $valkeyPort,
                'database' => $database,
                'username' => $env('TYPO3_VALKEY_USER'),
                'password' => $valkeyPassword,
            ], static fn ($v) => $v !== null),
        ];
    }

    // Install Tool / Admin Tools sessions (TYPO3 v14+)
    if (class_exists(\TYPO3\CMS\Install\Service\Session\RedisSessionHandler::class)) {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['installToolSessionHandler'] = [
            'className' => \TYPO3\CMS\Install\Service\Session\RedisSessionHandler::class,
            'options' => [
                'host' => $valkeyHost,
                'port' => $valkeyPort,
                'database' => 7,
                'authentication' => array_filter([
                    'user' => $env('TYPO3_VALKEY_USER'),
                    'pass' => $valkeyPassword,
                ], static fn ($v) => $v !== null),
            ],
        ];
    }

    // Page generation locks shared by all pods (b13/distributed-locks).
    // If Valkey goes away, the extension falls back to file locks per pod.
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['redis'] = array_filter([
        'hostname' => $valkeyHost,
        'port' => $valkeyPort,
        'database' => 8,
        'username' => $env('TYPO3_VALKEY_USER'),
        'password' => $valkeyPassword,
        'ttl' => 30,
        // default 0 = PHP's default_socket_timeout (60s) per attempt while Valkey is down
        'connectionTimeout' => 1.0,
    ], static fn ($v) => $v !== null);
} else {
    // Without this, b13/distributed-locks registers its strategy anyway and
    // every lock fails with "No configuration for Redis Locking Strategy found"
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['redis']['disabled'] = true;
}

// ---------------------------------------------------------------------------
// Logging: JSON lines to stderr, no files in var/log (b13/stderr-logging).
// The root level comes from TYPO3_LOG_LEVEL; StderrLogging::configure() in
// additional.php turns every other FileWriter into a StderrWriter.
// TYPO3_STDERR_LOGGING_LEVEL=debug forces one level everywhere, e.g. on one pod.
// ---------------------------------------------------------------------------
$GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = [
    $env('TYPO3_LOG_LEVEL', LogLevel::WARNING) => [
        StderrWriter::class => [],
    ],
];
// Deprecations are a build/CI concern, not a production log concern
$GLOBALS['TYPO3_CONF_VARS']['LOG']['TYPO3']['CMS']['deprecations']['writerConfiguration'] = [];

// ---------------------------------------------------------------------------
// Mail
// ---------------------------------------------------------------------------
if ($dsn = $env('TYPO3_MAIL_DSN')) {
    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'dsn';
    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['dsn'] = $dsn;
}
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] = $env('TYPO3_MAIL_FROM', 'noreply@example.com');

// ---------------------------------------------------------------------------
// Image processing
// ---------------------------------------------------------------------------
// The base image says what it has (docker/base.Dockerfile): ImageMagick 7, or
// no image tools for sites with imgproxy (EXT:media_processing then processes
// the images, and TYPO3 itself only hands out originals)
$GLOBALS['TYPO3_CONF_VARS']['GFX']['processor'] = 'ImageMagick';
$GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path'] = '/usr/bin/';
if ($env('TYPO3_KATE_IMAGE_TOOLS', 'imagemagick') === 'none') {
    $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_enabled'] = false;
}
