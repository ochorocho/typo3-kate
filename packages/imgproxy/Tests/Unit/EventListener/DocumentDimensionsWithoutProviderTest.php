<?php

declare(strict_types=1);

namespace Ochorocho\KateImgproxy\Tests\Unit\EventListener;

use Ochorocho\KateImgproxy\EventListener\DocumentDimensionsWithoutProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;
use TYPO3\CMS\Core\Resource\Event\BeforeFileProcessingEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;

final class DocumentDimensionsWithoutProviderTest extends TestCase
{
    #[Test]
    public function withoutProviderNothingHappens(): void
    {
        if (!class_exists(Parser::class)) {
            self::markTestSkipped('EXT:media_processing (smalot/pdfparser) is not installed in the kate site');
        }
        $file = $this->createMock(File::class);
        // the extension's listener would ask the file first; nothing may touch it
        $file->expects($this->never())->method($this->anything());
        $event = new BeforeFileProcessingEvent(null, $this->createStub(ProcessedFile::class), $file, 'Image.CropScaleMask', []);

        (new DocumentDimensionsWithoutProvider(null, new Parser(), null))($event);
    }
}
