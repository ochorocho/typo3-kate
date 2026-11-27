<?php

declare(strict_types=1);

namespace Ochorocho\KateImgproxy\Configuration;

/**
 * The extension configuration of EXT:media_processing for the site's imgproxy,
 * from the environment the Helm chart sets (imgproxy.enabled):
 *   TYPO3_IMGPROXY_URL         internal URL of the site's imgproxy
 *   TYPO3_IMGPROXY_SOURCE_URL  where imgproxy fetches originals: the site's URL
 *   IMGPROXY_KEY, IMGPROXY_SALT  signing key and salt (hex), from typo3-env
 *
 * imgproxy stays internal: TYPO3 stores each result (storage) and serves it
 * from _processed_, see ServeStoredResult.
 */
final class MediaProcessingConfiguration
{
    /**
     * @param callable(string): (string|false) $env
     * @return array<string, mixed>|null null without TYPO3_IMGPROXY_URL
     */
    public static function fromEnvironment(callable $env): ?array
    {
        $url = trim((string)$env('TYPO3_IMGPROXY_URL'));
        if ($url === '') {
            return null;
        }
        $source = trim((string)$env('TYPO3_IMGPROXY_SOURCE_URL'));
        $key = trim((string)$env('IMGPROXY_KEY'));
        $salt = trim((string)$env('IMGPROXY_SALT'));
        if ($source === '' || $key === '' || $salt === '') {
            throw new \RuntimeException(
                'imgproxy is on (TYPO3_IMGPROXY_URL), but TYPO3_IMGPROXY_SOURCE_URL, IMGPROXY_KEY or IMGPROXY_SALT is missing',
                1795100001
            );
        }
        return [
            'common' => [
                'provider' => 'imgproxy',
                'storage' => '1',
                'private' => '0',
                'backend' => '1',
                'frontend' => '1',
            ],
            'provider' => [
                'imgproxy' => [
                    'api_endpoint' => rtrim($url, '/'),
                    'source_loader' => 'uri',
                    'source_uri' => rtrim($source, '/'),
                    'signature' => '1',
                    'signature_key' => $key,
                    'signature_salt' => $salt,
                    'signature_size' => '',
                    'encryption' => '0',
                    'encryption_key' => '',
                ],
            ],
        ];
    }

    /**
     * Whether a processing URL points to the site's own imgproxy.
     *
     * @param callable(string): (string|false) $env
     */
    public static function isImgproxyUrl(string $url, callable $env): bool
    {
        $endpoint = rtrim(trim((string)$env('TYPO3_IMGPROXY_URL')), '/');
        return $endpoint !== '' && str_starts_with($url, $endpoint . '/');
    }
}
