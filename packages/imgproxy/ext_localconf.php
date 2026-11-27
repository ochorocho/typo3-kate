<?php

defined('TYPO3') or die();

// EXT:media_processing for the site's imgproxy, when the Helm chart enables it
// (imgproxy.enabled); otherwise this extension does nothing
$configuration = \Ochorocho\KateImgproxy\Configuration\MediaProcessingConfiguration::fromEnvironment(getenv(...));
if ($configuration !== null) {
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['media_processing'] = array_replace_recursive(
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['media_processing'] ?? [],
        $configuration
    );
}
