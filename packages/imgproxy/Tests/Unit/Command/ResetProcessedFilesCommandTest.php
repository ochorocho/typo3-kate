<?php

declare(strict_types=1);

namespace Ochorocho\KateImgproxy\Tests\Unit\Command;

use Ochorocho\KateImgproxy\Command\ResetProcessedFilesCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;

final class ResetProcessedFilesCommandTest extends TestCase
{
    #[Test]
    public function everyStorageIsReset(): void
    {
        $repository = $this->createMock(ProcessedFileRepository::class);
        // no storage UID: every storage, then the whole table
        $repository->expects($this->once())->method('removeAll')->with()->willReturn(0);

        $tester = new CommandTester(new ResetProcessedFilesCommand($repository));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Processed files reset', $tester->getDisplay());
    }

    #[Test]
    public function filesThatCouldNotBeDeletedAreReported(): void
    {
        $repository = $this->createStub(ProcessedFileRepository::class);
        $repository->method('removeAll')->willReturn(3);

        $tester = new CommandTester(new ResetProcessedFilesCommand($repository));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('3 file(s) could not be deleted', $tester->getDisplay());
    }
}
