<?php

declare(strict_types=1);

namespace Bmack\K8sHealth\EventListener;

use Bmack\K8sHealth\Check\CheckResult;
use Bmack\K8sHealth\Check\MessengerCheck;
use Bmack\K8sHealth\Check\Status;
use Bmack\K8sHealth\Check\ValkeyCheck;
use TYPO3\CMS\Backend\Backend\Event\SystemInformationToolbarCollectorEvent;
use TYPO3\CMS\Backend\Toolbar\InformationStatus;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Adds the pod and the state of the platform services to the System
 * Information menu in the backend top bar. Problems also become messages,
 * so the menu's badge turns yellow or red.
 */
final readonly class AddServiceStatus
{
    private const string LLL = 'LLL:EXT:k8s_health/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private ValkeyCheck $valkeyCheck,
        private MessengerCheck $messengerCheck,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    #[AsEventListener('k8s-health/system-information')]
    public function __invoke(SystemInformationToolbarCollectorEvent $event): void
    {
        $toolbarItem = $event->getToolbarItem();
        $languageService = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);

        $toolbarItem->addSystemInformation(self::LLL . 'pod.title', gethostname() ?: '-', 'actions-server');

        $checks = [
            'valkey.title' => [$this->valkeyCheck->checkSessions(), 'actions-bolt'],
            'valkeyCache.title' => [$this->valkeyCheck->checkCaches(), 'actions-bolt'],
            'messengerWorker.title' => [$this->messengerCheck->checkWorkers(), 'actions-synchronize'],
            'messengerQueue.title' => [$this->messengerCheck->checkQueue(), 'actions-document-synchronize'],
        ];
        foreach ($checks as $title => [$result, $icon]) {
            if ($result === null) {
                continue;
            }
            $text = $this->translate($languageService, $result);
            $status = self::informationStatus($result->status);
            $toolbarItem->addSystemInformation(self::LLL . $title, $text, $icon, $status);
            if ($result->status === Status::Warning || $result->status === Status::Error) {
                $toolbarItem->addSystemMessage(
                    htmlspecialchars($languageService->sL(self::LLL . $title) . ': ' . $text),
                    $status,
                    1
                );
            }
        }
    }

    private function translate(LanguageService $languageService, CheckResult $result): string
    {
        $label = $languageService->sL(self::LLL . $result->label) ?: $result->label;
        return $result->arguments === [] ? $label : vsprintf($label, $result->arguments);
    }

    private static function informationStatus(Status $status): InformationStatus
    {
        return match ($status) {
            Status::Ok => InformationStatus::OK,
            Status::Warning => InformationStatus::WARNING,
            Status::Error => InformationStatus::ERROR,
            Status::Skipped => InformationStatus::NOTICE,
        };
    }
}
