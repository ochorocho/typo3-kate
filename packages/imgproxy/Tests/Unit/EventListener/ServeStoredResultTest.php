<?php

declare(strict_types=1);

namespace Ochorocho\KateImgproxy\Tests\Unit\EventListener;

use Ochorocho\KateImgproxy\EventListener\ServeStoredResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\Event\AfterFileProcessingEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\Processing\TaskInterface;
use TYPO3\CMS\Core\Resource\Processing\TaskTypeRegistry;

final class ServeStoredResultTest extends TestCase
{
    private const string IMGPROXY = 'http://typo3-imgproxy:8080';

    protected function setUp(): void
    {
        putenv('TYPO3_IMGPROXY_URL=' . self::IMGPROXY);
    }

    protected function tearDown(): void
    {
        putenv('TYPO3_IMGPROXY_URL');
    }

    /**
     * The repository and processed file mocks, and a closure that runs the
     * listener for them
     *
     * @return array{0: ProcessedFileRepository&MockObject, 1: ProcessedFile&MockObject, 2: \Closure(): void}
     */
    private function prepare(string $processingUrl, bool $stored): array
    {
        $processedFile = $this->createMock(ProcessedFile::class);
        $processedFile->method('isPersisted')->willReturn(true);
        $processedFile->method('getProperty')->willReturnMap([['processing_url', $processingUrl]]);
        $processedFile->method('usesOriginalFile')->willReturn(false);
        $processedFile->method('exists')->willReturn($stored);

        $repository = $this->createMock(ProcessedFileRepository::class);
        $registry = $this->createStub(TaskTypeRegistry::class);
        $registry->method('getTaskForType')->willReturn($this->createStub(TaskInterface::class));

        $event = new AfterFileProcessingEvent(null, $processedFile, $this->createStub(File::class), 'Image.CropScaleMask', []);
        $listener = new ServeStoredResult($repository, $registry);
        return [$repository, $processedFile, fn() => $listener($event)];
    }

    #[Test]
    public function aStoredResultIsServedInsteadOfTheImgproxyUrl(): void
    {
        [$repository, $processedFile, $invoke] = $this->prepare(self::IMGPROXY . '/sig/rs:fit:100/plain/x', true);
        $processedFile->expects($this->once())->method('updateProperties')->with(['processing_url' => '']);
        $repository->expects($this->once())->method('update');

        $invoke();
    }

    #[Test]
    public function aResultNotStoredYetKeepsItsUrl(): void
    {
        [$repository, $processedFile, $invoke] = $this->prepare(self::IMGPROXY . '/sig/x', false);
        $processedFile->expects($this->never())->method('updateProperties');
        $repository->expects($this->never())->method('update');

        $invoke();
    }

    #[Test]
    public function otherProcessingUrlsStay(): void
    {
        [$repository, $processedFile, $invoke] = $this->prepare('https://cdn.example.com/x.jpg', true);
        $processedFile->expects($this->never())->method('updateProperties');
        $repository->expects($this->never())->method('update');

        $invoke();
    }

    #[Test]
    public function withoutProcessingUrlNothingChanges(): void
    {
        [$repository, $processedFile, $invoke] = $this->prepare('', true);
        $processedFile->expects($this->never())->method('updateProperties');
        $repository->expects($this->never())->method('update');

        $invoke();
    }
}
