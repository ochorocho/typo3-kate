<?php

declare(strict_types=1);

namespace Ochorocho\KateImgproxy\EventListener;

use Smalot\PdfParser\Parser;
use SomehowDigital\Typo3\MediaProcessing\EventListener\DocumentDimensionsEventListener;
use SomehowDigital\Typo3\MediaProcessing\Provider\ProviderInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\Event\BeforeFileProcessingEvent;

/**
 * Takes the place of EXT:media_processing's DocumentDimensionsEventListener
 * (Configuration/Services.yaml). Without a configured provider the extension's
 * provider service is null, and its listener, which requires one, breaks every
 * image processing with a 500. A site with the package installed and imgproxy
 * switched off (imgproxy.enabled: false) is such a case.
 *
 * With a provider this hands the event to the extension's listener unchanged;
 * without one it does nothing, so TYPO3 processes images itself.
 */
final readonly class DocumentDimensionsWithoutProvider
{
    public function __construct(
        private ?ProviderInterface $provider,
        private Parser $parser,
        private ?ExtensionConfiguration $configuration,
    ) {}

    public function __invoke(BeforeFileProcessingEvent $event): void
    {
        if ($this->provider === null) {
            return;
        }
        (new DocumentDimensionsEventListener($this->provider, $this->parser, $this->configuration))($event);
    }
}
