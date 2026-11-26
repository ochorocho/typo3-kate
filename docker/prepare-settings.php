<?php

// Build time (docker/Dockerfile): bring config/system/settings.php up to date,
// so TYPO3 never needs to write it at runtime (the image's copy is read-only):
//  1. the default configuration of every extension (ext_conf_template.txt),
//     otherwise `extension:setup` in the migrations Job writes it
//  2. TYPO3's silent configuration upgrades, otherwise `upgrade:run` writes them
// Needs no database. The throwaway encryption key only satisfies the boot;
// only the paths changed here end up in settings.php.

use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Service\Exception\ConfigurationChangedException;
use TYPO3\CMS\Core\Service\SilentConfigurationUpgradeService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$classLoader = require dirname(__DIR__) . '/vendor/autoload.php';
SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
Bootstrap::init($classLoader);

GeneralUtility::makeInstance(ExtensionConfiguration::class)->synchronizeExtConfTemplateWithLocalConfigurationOfAllExtensions();
echo 'Extension defaults: ' . implode(', ', array_keys($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'] ?? [])) . "\n";

// Each upgrade step writes one change and throws; repeat until nothing is left.
$upgrades = new SilentConfigurationUpgradeService(new ConfigurationManager());
for ($run = 1; $run <= 50; $run++) {
    try {
        $upgrades->execute();
        echo 'Silent configuration upgrades applied: ' . ($run - 1) . "\n";
        exit(0);
    } catch (ConfigurationChangedException) {
        continue;
    }
}
fwrite(STDERR, "Silent configuration upgrades did not settle after 50 runs\n");
exit(1);
