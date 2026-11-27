<?php

declare(strict_types=1);

namespace Ochorocho\Mercure\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'mercure:publish', description: 'Publish an update to the Mercure hub')]
final class PublishCommand extends Command
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('topic', InputArgument::REQUIRED, 'Topic, e.g. https://example.com/books/1')
            ->addArgument('data', InputArgument::OPTIONAL, 'Payload, usually JSON', '')
            ->addOption('private', null, InputOption::VALUE_NONE, 'Only for subscribers authorized for the topic')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'SSE event type')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch on the message bus instead of publishing directly');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $update = new Update(
            (string)$input->getArgument('topic'),
            (string)$input->getArgument('data'),
            (bool)$input->getOption('private'),
            type: $input->getOption('type') !== null ? (string)$input->getOption('type') : null,
        );
        if ($input->getOption('async')) {
            $this->messageBus->dispatch($update);
            $io->success('Update dispatched on the message bus.');
            return Command::SUCCESS;
        }
        $id = $this->hub->publish($update);
        $io->success(sprintf('Published, update id %s', $id));
        return Command::SUCCESS;
    }
}
