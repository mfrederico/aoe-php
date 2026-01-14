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
 * Start a session's tmux session
 *
 * Usage: bin/aoe --tenant=X session:start <id>
 */
class SessionStartCommand extends BaseCommand
{
    protected static $defaultName = 'session:start';
    protected static $defaultDescription = 'Start a session (create tmux session)';

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Session ID or prefix')
            ->addOption('command', 'c', InputOption::VALUE_REQUIRED, 'Override the command to run')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without doing it');
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
        $dryRun = $input->getOption('dry-run');

        // Find session
        $session = $this->storage->findByPrefix($idOrPrefix);
        if (!$session) {
            $output->writeln("<error>Session not found: {$idOrPrefix}</error>");
            return self::FAILURE;
        }

        $tmux = new TmuxService($this->tenantId);

        // Check if already running
        if ($tmux->sessionExists($session->id)) {
            $output->writeln("<comment>Session already has a tmux session running</comment>");
            $output->writeln("Use <info>session:attach {$session->id}</info> to attach");
            return self::SUCCESS;
        }

        // Validate project path exists
        if (!is_dir($session->projectPath)) {
            $output->writeln("<error>Project path does not exist: {$session->projectPath}</error>");
            return self::FAILURE;
        }

        // Determine command to run
        $command = $customCommand ?? $session->command;
        if (empty($command)) {
            // Default command based on tool
            $command = match ($session->tool) {
                'claude' => 'claude',
                'opencode' => 'opencode',
                default => '',
            };
        }

        $tmuxName = $tmux->buildSessionName($session->id);

        if ($dryRun) {
            $output->writeln("<info>Would create tmux session:</info>");
            $output->writeln("  Name: {$tmuxName}");
            $output->writeln("  Directory: {$session->projectPath}");
            $output->writeln("  Command: " . ($command ?: '(shell)'));
            return self::SUCCESS;
        }

        // Create the tmux session
        $output->writeln("Starting session <info>{$session->title}</info>...");

        $success = $tmux->createSession($session->id, $session->projectPath, $command);

        if (!$success) {
            $output->writeln("<error>Failed to create tmux session</error>");
            return self::FAILURE;
        }

        // Update session status
        $session->status = Status::Starting;
        $session->updateLastAccessed();
        $this->storage->save($session);

        $output->writeln("<info>Session started successfully</info>");
        $output->writeln("  tmux session: {$tmuxName}");
        $output->writeln("  Directory: {$session->projectPath}");
        if ($command) {
            $output->writeln("  Command: {$command}");
        }
        $output->writeln("");
        $output->writeln("To attach: <info>bin/aoe --tenant={$this->tenantId} session:attach {$session->id}</info>");

        return self::SUCCESS;
    }
}
