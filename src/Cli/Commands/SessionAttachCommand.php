<?php

declare(strict_types=1);

namespace Aoe\Cli\Commands;

use Aoe\Workspace\WorkspaceRequiredException;
use Aoe\Tmux\TmuxService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Attach to a session's tmux session
 *
 * Usage: bin/aoe --workspace=X session:attach <id>
 */
class SessionAttachCommand extends BaseCommand
{
    protected static $defaultName = 'session:attach';
    protected static $defaultDescription = 'Attach to a session\'s tmux session';

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Session ID or prefix');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initialize($input, $output);
        } catch (WorkspaceRequiredException) {
            return Command::FAILURE;
        }
        $idOrPrefix = $input->getArgument('id');

        // Find session
        $session = $this->storage->findByPrefix($idOrPrefix);
        if (!$session) {
            $output->writeln("<error>Session not found: {$idOrPrefix}</error>");
            return self::FAILURE;
        }

        $tmux = new TmuxService($this->workspaceId);

        // Check if tmux session exists
        if (!$tmux->sessionExists($session->id)) {
            $output->writeln("<error>No tmux session is running for this session</error>");
            $output->writeln("Use <info>session:start {$session->id}</info> to start it first");
            return self::FAILURE;
        }

        // Update last accessed
        $session->updateLastAccessed();
        $this->storage->save($session);

        $output->writeln("Attaching to <info>{$session->title}</info>...");
        $output->writeln("<comment>Press Ctrl+B then D to detach</comment>");
        $output->writeln("");

        // This will replace the current process
        $tmux->attachSession($session->id);

        // We only get here if attach fails
        return self::FAILURE;
    }
}
