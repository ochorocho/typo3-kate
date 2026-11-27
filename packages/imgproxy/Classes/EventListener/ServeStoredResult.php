<?php

declare(strict_types=1);

namespace Ochorocho\KateImgproxy\EventListener;

use Ochorocho\KateImgproxy\Configuration\MediaProcessingConfiguration;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Resource\Event\AfterFileProcessingEvent;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\Processing\TaskTypeRegistry;

/**
 * EXT:media_processing stores imgproxy's result locally (storage), but keeps
 * the imgproxy URL in processing_url, and TYPO3 hands that URL to browsers
 * before the stored file. The site's imgproxy is internal, so once the result
 * is stored, clear processing_url: TYPO3 then serves the file from _processed_.
 *
 * Runs after the core saved the processed file, and on every later request
 * for it, so entries stored before this listener get fixed too.
 */
final readonly class ServeStoredResult
{
    public function __construct(
        private ProcessedFileRepository $processedFileRepository,
        private TaskTypeRegistry $taskTypeRegistry,
    ) {}

    #[AsEventListener('kate-imgproxy/serve-stored-result')]
    public function __invoke(AfterFileProcessingEvent $event): void
    {
        $processedFile = $event->getProcessedFile();
        if (!$processedFile instanceof ProcessedFile || !$processedFile->isPersisted()) {
            return;
        }
        $url = (string)$processedFile->getProperty('processing_url');
        if ($url === '' || !MediaProcessingConfiguration::isImgproxyUrl($url, getenv(...))) {
            return;
        }
        // Only once the result really is in the storage
        if ($processedFile->usesOriginalFile() || !$processedFile->exists()) {
            return;
        }
        $processedFile->updateProperties(['processing_url' => '']);
        $task = $this->taskTypeRegistry->getTaskForType($event->getTaskType(), $processedFile, $event->getConfiguration());
        $this->processedFileRepository->update($processedFile, $task);
    }
}
