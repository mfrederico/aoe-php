<?php

declare(strict_types=1);

namespace Aoe\Cli\Commands;

use Aoe\Session\Status;
use Aoe\Tenant\TenantRequiredException;
use Aoe\Tmux\TmuxService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Restart a session's tmux session
 *
 * Usage: bin/aoe --tenant=X session:restart <id>
 */
class SessionRestartCommand extends BaseCommand
{
    protected static $defaultName = 'session:restart';
    protected static $defaultDescription = 'Restart a session (stop and start tmux session)';

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Session ID or prefix')
            ->addOption('command', 'c', InputOption::VALUE_REQUIRED, 'Override the command to run')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force restart without confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initialize($input, $output);
        } catch (TenantRequiredException) {
            return Command::FAILURE;
        }
        $idOrPrefix = $input->getArgument('id');
        $customCommand = $input->getOption('command');
        $force = $input->getOption('force');

        // Find session
        $session = $this->storage->findByPrefix($idOrPrefix);
        if (!$session) {
            $output->writeln("<error>Session not found: {$idOrPrefix}</error>");
            return self::FAILURE;
        }

        $tmux = new TmuxService($this->tenantId);
        $isRunning = $tmux->sessionExists($session->id);

        // Confirm if running and not forced
        if ($isRunning && !$force) {
            $helper = $this->getHelper('question');
            $question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
                "Restart session <info>{$session->title}</info> ({$session->getShortId()})? This will kill the current tmux session. [y/N] ",
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln("<comment>Cancelled</comment>");
                return self::SUCCESS;
            }
        }

        // Stop if running
        if ($isRunning) {
            $output->writeln("Stopping existing session...");
            $tmux->killSession($session->id);
        }

        // Validate project path exists
        if (!is_dir($session->projectPath)) {
            $output->writeln("<error>Project path does not exist: {$session->projectPath}</error>");
            return self::FAILURE;
        }

        // Determine command to run
        $command = $customCommand ?? $session->command;
        if (empty($command)) {
            $command = match ($session->tool) {
                'claude' => 'claude',
                'opencode' => 'opencode',
                default => '',
            };
        }

        // Start the session
        $output->writeln("Starting session <info>{$session->title}</info>...");

        $success = $tmux->createSession($session->id, $session->projectPath, $command);

        if (!$success) {
            $output->writeln("<error>Failed to create tmux session</error>");
            $session->status = Status::Error;
            $this->storage->save($session);
            return self::FAILURE;
        }

        // Update session status
        $session->status = Status::Starting;
        $session->updateLastAccessed();
        $this->storage->save($session);

        $tmuxName = $tmux->buildSessionName($session->id);
        $output->writeln("<info>Session restarted successfully</info>");
        $output->writeln("  tmux session: {$tmuxName}");
        $output->writeln("");
        $output->writeln("To attach: <info>bin/aoe --tenant={$this->tenantId} session:attach {$session->id}</info>");

        return self::SUCCESS;
    }
}
