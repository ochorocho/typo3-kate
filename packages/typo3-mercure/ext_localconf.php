<?php

defined('TYPO3') or die();

// Updates dispatched on the bus are published by the messenger worker, unless
// "transport" is empty or the project routes Update itself
$transport = trim((string)($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mercure']['transport'] ?? 'doctrine'));
if ($transport !== '') {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'][\Symfony\Component\Mercure\Update::class] ??= $transport;
}
