# ochorocho/kate-imgproxy

typo3-kate's glue for sites with `imgproxy.enabled` in their `kate.yaml`: images are processed by the site's own [imgproxy](https://github.com/imgproxy/imgproxy) through [EXT:media_processing](https://github.com/somehow-digital/typo3-media-processing), which this package requires.

- `ext_localconf.php` configures EXT:media_processing from the environment the Helm chart sets (`TYPO3_IMGPROXY_URL`, `TYPO3_IMGPROXY_SOURCE_URL`, `IMGPROXY_KEY`, `IMGPROXY_SALT`): provider imgproxy, signed URLs, source loader `uri` (the site itself), and `storage`, so TYPO3 keeps each result in `_processed_`. Without `TYPO3_IMGPROXY_URL` the package does nothing.
- `ServeStoredResult` (an `AfterFileProcessingEvent` listener) clears `processing_url` once a result is stored: EXT:media_processing keeps the imgproxy URL there, and TYPO3 would hand that internal URL to browsers instead of the stored file.

- `kate:processed-files:reset` deletes the processed files of every storage (fileadmin and S3, through each storage's driver) and their records, so TYPO3 makes them again. `./kate reprocess <site>` runs it and flushes the page cache; `./kate deploy` does so when a site switches between ImageMagick and imgproxy. Unlike `cleanup:localprocessedfiles` from EXT:lowlevel, it isn't limited to storages with the Local driver.

```sh
./kate composer <site> require ochorocho/kate-imgproxy:@dev
```

EXT:media_processing 1.0 allows Symfony 7 only (TYPO3 v14); TYPO3 v15 is on Symfony 8, see [upstream issue #41](https://github.com/somehow-digital/typo3-media-processing/issues/41). On v15, require it with an inline alias: `./kate composer <site> require "symfony/options-resolver:8.1.10 as 7.4.99" ochorocho/kate-imgproxy:@dev`

Tests: `../../projects/kate/vendor/bin/phpunit -c packages/imgproxy/phpunit.xml.dist` (with the kate site's dependencies installed).
