<?php

declare(strict_types=1);

namespace Aoe\Cli\Commands;

use Aoe\Session\Status;
use Aoe\Workspace\WorkspaceRequiredException;
use Aoe\Tmux\TmuxService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stop a session's tmux session
 *
 * Usage: bin/aoe --workspace=X session:stop <id>
 */
class SessionStopCommand extends BaseCommand
{
    protected static $defaultName = 'session:stop';
    protected static $defaultDescription = 'Stop a session (kill tmux session)';

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Session ID or prefix')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force stop without confirmation')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without doing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initialize($input, $output);
        } catch (WorkspaceRequiredException) {
            return Command::FAILURE;
        }
        $idOrPrefix = $input->getArgument('id');
        $force = $input->getOption('force');
        $dryRun = $input->getOption('dry-run');

        // Find session
        $session = $this->storage->findByPrefix($idOrPrefix);
        if (!$session) {
            $output->writeln("<error>Session not found: {$idOrPrefix}</error>");
            return self::FAILURE;
        }

        $tmux = new TmuxService($this->workspaceId);
        $tmuxName = $tmux->buildSessionName($session->id);

        // Check if tmux session exists
        if (!$tmux->sessionExists($session->id)) {
            $output->writeln("<comment>No tmux session is running for this session</comment>");

            // Update status if it thinks it's running
            if ($session->status->isActive()) {
                $session->status = Status::Stopped;
                $this->storage->save($session);
                $output->writeln("Status updated to Stopped");
            }

            return self::SUCCESS;
        }

        if ($dryRun) {
            $output->writeln("<info>Would kill tmux session:</info> {$tmuxName}");
            return self::SUCCESS;
        }

        // Confirm if not forced
        if (!$force) {
            $helper = $this->getHelper('question');
            $question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
                "Stop session <info>{$session->title}</info> ({$session->getShortId()})? [y/N] ",
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln("<comment>Cancelled</comment>");
                return self::SUCCESS;
            }
        }

        // Kill the tmux session
        $output->writeln("Stopping session <info>{$session->title}</info>...");

        $success = $tmux->killSession($session->id);

        if (!$success) {
            $output->writeln("<error>Failed to kill tmux session</error>");
            return self::FAILURE;
        }

        // Update session status
        $session->status = Status::Stopped;
        $session->updateLastAccessed();
        $this->storage->save($session);

        $output->writeln("<info>Session stopped successfully</info>");

        return self::SUCCESS;
    }
}
