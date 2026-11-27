<?php

declare(strict_types=1);

namespace Ochorocho\Mercure\EventListener;

use Ochorocho\Mercure\Configuration\MercureConfiguration;
use Ochorocho\Mercure\Hub\HubStatus;
use TYPO3\CMS\Backend\Backend\Event\SystemInformationToolbarCollectorEvent;
use TYPO3\CMS\Backend\Toolbar\InformationStatus;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The hub's state in the System Information menu of the backend top bar.
 */
final readonly class AddHubStatus
{
    private const string LLL = 'LLL:EXT:mercure/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private HubStatus $hubStatus,
        private MercureConfiguration $configuration,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    #[AsEventListener('mercure/system-information')]
    public function __invoke(SystemInformationToolbarCollectorEvent $event): void
    {
        $languageService = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        $state = $this->hubStatus->check();
        $status = match ($state) {
            HubStatus::REACHABLE => InformationStatus::OK,
            HubStatus::NOT_CONFIGURED => InformationStatus::NOTICE,
            default => InformationStatus::ERROR,
        };
        $text = sprintf($languageService->sL(self::LLL . 'hub.' . $state), $this->configuration->hubUrl);
        if ($state === HubStatus::REACHABLE) {
            $text .= ', ' . ($this->configuration->transport !== ''
                ? sprintf($languageService->sL(self::LLL . 'hub.async'), $this->configuration->transport)
                : $languageService->sL(self::LLL . 'hub.sync'));
        }

        $toolbarItem = $event->getToolbarItem();
        $toolbarItem->addSystemInformation(self::LLL . 'hub.title', $text, 'actions-cloud', $status);
        if ($status === InformationStatus::ERROR) {
            $toolbarItem->addSystemMessage(
                htmlspecialchars($languageService->sL(self::LLL . 'hub.title') . ': ' . $text),
                $status,
                1
            );
        }
    }
}
