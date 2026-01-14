<?php

declare(strict_types=1);

namespace Aoe\Cli\Commands;

use Aoe\Tenant\TenantRequiredException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Command to remove a session
 */
class RemoveCommand extends BaseCommand
{
    protected static $defaultName = 'remove';
    protected static $defaultDescription = 'Remove an AI agent session';

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                'Session ID (or prefix)'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Skip confirmation prompt'
            )
            ->setHelp(<<<'HELP'
Remove a session by its ID (or ID prefix).

Examples:
  aoe --tenant=acme remove abc12345
  aoe --tenant=acme remove abc1 --force
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initialize($input, $output);
        } catch (TenantRequiredException) {
            return Command::FAILURE;
        }

        $idOrPrefix = $input->getArgument('id');
        $force = $input->getOption('force');

        // Try to find the session
        $session = $this->getStorage()->find($idOrPrefix);

        // If not found by exact ID, try prefix
        if ($session === null) {
            $session = $this->getStorage()->findByPrefix($idOrPrefix);
        }

        if ($session === null) {
            $output->writeln("<error>Session not found: {$idOrPrefix}</error>");
            return Command::FAILURE;
        }

        // Confirm deletion
        if (!$force) {
            $output->writeln("About to remove session:");
            $output->writeln("  ID:    {$session->id}");
            $output->writeln("  Title: {$session->title}");
            $output->writeln("  Path:  {$session->projectPath}");
            $output->writeln('');

            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                'Are you sure you want to remove this session? [y/N] ',
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<comment>Cancelled.</comment>');
                return Command::SUCCESS;
            }
        }

        // TODO: If session is running, stop the tmux session first
        // This will be implemented when we add TmuxService

        // Delete from storage
        $deleted = $this->getStorage()->delete($session->id);

        if ($deleted) {
            $output->writeln("<info>Session removed: {$session->title}</info>");
            return Command::SUCCESS;
        } else {
            $output->writeln('<error>Failed to remove session.</error>');
            return Command::FAILURE;
        }
    }
}
