<?php

// The package's tests run with the kate site's dependencies (TYPO3 core,
// phpunit) plus this package's own classes.
$loader = require __DIR__ . '/../../../projects/kate/vendor/autoload.php';
$loader->addPsr4('Ochorocho\\KateImgproxy\\', __DIR__ . '/../Classes/');
$loader->addPsr4('Ochorocho\\KateImgproxy\\Tests\\', __DIR__ . '/');
