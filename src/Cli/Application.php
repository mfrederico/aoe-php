<?php

declare(strict_types=1);

namespace Aoe\Cli;

use Aoe\Cli\Commands\AddCommand;
use Aoe\Cli\Commands\ListCommand;
use Aoe\Cli\Commands\RemoveCommand;
use Aoe\Cli\Commands\SessionAttachCommand;
use Aoe\Cli\Commands\SessionRestartCommand;
use Aoe\Cli\Commands\SessionStartCommand;
use Aoe\Cli\Commands\SessionStatusCommand;
use Aoe\Cli\Commands\SessionStopCommand;
use Aoe\Cli\Commands\StatusCommand;
use Aoe\Cli\Commands\WorkspaceCommand;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * AOE CLI Application
 *
 * Main entry point for the command-line interface.
 * All workspace-scoped commands require --workspace option or AOE_WORKSPACE env var.
 */
class Application extends ConsoleApplication
{
    public const VERSION = '0.1.0';

    public function __construct()
    {
        parent::__construct('AOE - AI Agent Session Manager', self::VERSION);

        $this->registerCommands();
    }

    /**
     * Override default help to show clean command list
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        // If no command specified, show our custom help
        if (!$input->getFirstArgument()) {
            $this->showHelp($output);
            return 0;
        }

        return parent::doRun($input, $output);
    }

    /**
     * Show clean help output
     */
    private function showHelp(OutputInterface $output): void
    {
        $output->writeln('<info>AOE - AI Agent Session Manager</info>');
        $output->writeln('');
        $output->writeln('<comment>Usage:</comment>');
        $output->writeln('  aoe --workspace=<workspace> <command> [options]');
        $output->writeln('');
        $output->writeln('<comment>Session List:</comment>');
        $output->writeln('  <info>sessions</info>                      List all sessions (cached status)');
        $output->writeln('  <info>sessions -r, --refresh</info>        List sessions (detect status from tmux pane)');
        $output->writeln('');
        $output->writeln('<comment>Session Control:</comment>');
        $output->writeln('  <info>session:status <id></info>           Show detailed session status');
        $output->writeln('  <info>session:status <id> -u</info>        Detect and update status from tmux pane');
        $output->writeln('  <info>session:status <id> -c</info>        Show captured pane content (for debugging)');
        $output->writeln('  <info>session:attach <id></info>           Attach to tmux session');
        $output->writeln('  <info>session:start <id></info>            Start tmux session');
        $output->writeln('  <info>session:stop <id></info>             Stop tmux session');
        $output->writeln('  <info>session:restart <id></info>          Restart tmux session');
        $output->writeln('');
        $output->writeln('<comment>Session Management:</comment>');
        $output->writeln('  <info>add <path></info>                    Add a new session');
        $output->writeln('  <info>remove <id></info>                   Remove a session');
        $output->writeln('  <info>remove <id> --force</info>           Remove session and kill tmux');
        $output->writeln('  <info>status</info>                        Show status summary');
        $output->writeln('');
        $output->writeln('<comment>Tenants:</comment>');
        $output->writeln('  <info>workspace:list</info>                   List available workspaces');
        $output->writeln('');
        $output->writeln('<comment>Status Detection:</comment>');
        $output->writeln('  --refresh / -u reads tmux pane content to detect if agent is:');
        $output->writeln('  Running (actively processing), Waiting (needs input), Idle, or Error');
        $output->writeln('');
        $output->writeln('<comment>Examples:</comment>');
        $output->writeln('  aoe --workspace=gwt sessions -r             # Check live status');
        $output->writeln('  aoe --workspace=gwt session:status fd95 -u  # Update specific session');
        $output->writeln('  aoe --workspace=gwt session:attach fd95     # Attach to session');
        $output->writeln('  aoe --workspace=gwt remove fd95 --force     # Remove and kill tmux');
    }

    /**
     * Register all available commands
     */
    private function registerCommands(): void
    {
        // Tenant commands (no workspace required)
        $this->add(new WorkspaceCommand());

        // Session management commands (workspace required)
        $this->add(new AddCommand());
        $this->add(new ListCommand());
        $this->add(new RemoveCommand());
        $this->add(new StatusCommand());

        // Tmux session commands (workspace required)
        $this->add(new SessionStartCommand());
        $this->add(new SessionStopCommand());
        $this->add(new SessionRestartCommand());
        $this->add(new SessionAttachCommand());
        $this->add(new SessionStatusCommand());
    }
}
