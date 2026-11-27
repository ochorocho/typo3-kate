<?php

declare(strict_types=1);

namespace Ochorocho\KateImgproxy\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;

/**
 * Deletes every processed file (thumbnails, scaled and cropped images) of every
 * storage, through each storage's own driver (fileadmin and S3 alike), and
 * their records. TYPO3 creates them again on the next request, with the image
 * processing configured now: ./kate deploy runs it when a site switches
 * between ImageMagick and imgproxy. Flush the page cache afterwards, or cached
 * pages keep pointing to the deleted files.
 *
 * Unlike cleanup:localprocessedfiles --all (EXT:lowlevel), which only covers
 * storages with the Local driver.
 */
#[AsCommand('kate:processed-files:reset', 'Deletes the processed files of every storage, so TYPO3 creates them again')]
final class ResetProcessedFilesCommand extends Command
{
    public function __construct(
        private readonly ProcessedFileRepository $processedFileRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = $this->processedFileRepository->removeAll();
        if ($errors > 0) {
            $io->warning(sprintf(
                'Processed files reset; %d file(s) could not be deleted (see the log). Their records are gone, so TYPO3 creates them again anyway.',
                $errors
            ));
            return Command::SUCCESS;
        }
        $io->success('Processed files reset: TYPO3 creates them again on the next request.');
        return Command::SUCCESS;
    }
}
