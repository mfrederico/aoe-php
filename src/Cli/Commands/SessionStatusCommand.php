<?php

declare(strict_types=1);

namespace Aoe\Cli\Commands;

use Aoe\Session\Status;
use Aoe\Workspace\WorkspaceRequiredException;
use Aoe\Tmux\StatusDetector;
use Aoe\Tmux\TmuxService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Show detailed status of a session
 *
 * Usage: bin/aoe --workspace=X session:status <id>
 */
class SessionStatusCommand extends BaseCommand
{
    protected static $defaultName = 'session:status';
    protected static $defaultDescription = 'Show detailed status of a session';

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Session ID or prefix')
            ->addOption('capture', 'c', InputOption::VALUE_OPTIONAL, 'Capture and show pane content (number of lines)', false)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('update', 'u', InputOption::VALUE_NONE, 'Update status by analyzing pane content');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initialize($input, $output);
        } catch (WorkspaceRequiredException) {
            return Command::FAILURE;
        }
        $idOrPrefix = $input->getArgument('id');
        $captureLines = $input->getOption('capture');
        $json = $input->getOption('json');
        $update = $input->getOption('update');

        // Find session
        $session = $this->storage->findByPrefix($idOrPrefix);
        if (!$session) {
            $output->writeln("<error>Session not found: {$idOrPrefix}</error>");
            return self::FAILURE;
        }

        $tmux = new TmuxService($this->workspaceId);
        $tmuxName = $session->getTmuxName();
        $tmuxExists = $tmux->sessionExistsByName($tmuxName);

        // Build status info
        $info = [
            'id' => $session->id,
            'title' => $session->title,
            'workspace' => $session->workspaceId,
            'tool' => $session->tool,
            'status' => $session->status->value,
            'project_path' => $session->projectPath,
            'group_path' => $session->groupPath ?: null,
            'command' => $session->command ?: null,
            'tmux' => [
                'name' => $tmuxName,
                'exists' => $tmuxExists,
            ],
            'created_at' => $session->createdAt->toIso8601String(),
            'last_accessed_at' => $session->lastAccessedAt?->toIso8601String(),
            'claude_session_id' => $session->claudeSessionId,
        ];

        // Capture pane content if requested or if updating
        if ($tmuxExists && ($captureLines !== false || $update)) {
            $lines = $captureLines !== false && $captureLines !== null ? (int) $captureLines : 20;
            $content = $tmux->capturePaneByName($tmuxName, $lines);
            $dimensions = $tmux->getPaneDimensionsByName($tmuxName);

            $info['tmux']['dimensions'] = $dimensions;

            if ($update) {
                $detector = new StatusDetector();
                $result = $detector->detectWithDetails($content);
                $detectedStatus = $result['status'];

                // Update if different
                if ($detectedStatus !== $session->status) {
                    $session->status = $detectedStatus;
                    $this->storage->save($session);
                    $info['status'] = $detectedStatus->value;
                    $info['status_updated'] = true;
                }

                $info['detection'] = [
                    'matches' => array_filter($result['matches'], fn($m) => !empty($m)),
                ];
            }

            if ($captureLines !== false) {
                $info['pane_content'] = $content;
            }
        } elseif (!$tmuxExists && $session->status->isActive()) {
            // tmux doesn't exist but status says active - fix it
            $session->status = Status::Stopped;
            $this->storage->save($session);
            $info['status'] = Status::Stopped->value;
            $info['status_updated'] = true;
        }

        // Output
        if ($json) {
            $output->writeln(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        // Pretty output
        $output->writeln("");
        $output->writeln("<info>Session: {$session->title}</info>");
        $output->writeln(str_repeat('-', 50));
        $output->writeln("");

        $output->writeln("  <comment>ID:</comment>           {$session->id}");
        $output->writeln("  <comment>Tenant:</comment>       {$session->workspaceId}");
        $output->writeln("  <comment>Status:</comment>       {$session->status->icon()} {$session->status->label()}");
        $output->writeln("  <comment>Tool:</comment>         {$session->tool}");
        $output->writeln("  <comment>Path:</comment>         {$session->projectPath}");

        if ($session->groupPath) {
            $output->writeln("  <comment>Group:</comment>        {$session->groupPath}");
        }
        if ($session->command) {
            $output->writeln("  <comment>Command:</comment>      {$session->command}");
        }

        $output->writeln("");
        $output->writeln("  <comment>tmux Name:</comment>    {$tmuxName}");
        $output->writeln("  <comment>tmux Active:</comment>  " . ($tmuxExists ? '<info>Yes</info>' : '<comment>No</comment>'));

        if ($tmuxExists && isset($info['tmux']['dimensions'])) {
            $dims = $info['tmux']['dimensions'];
            $output->writeln("  <comment>Pane Size:</comment>    {$dims['width']}x{$dims['height']}");
        }

        $output->writeln("");
        $output->writeln("  <comment>Created:</comment>      {$session->createdAt->format('Y-m-d H:i:s')}");
        if ($session->lastAccessedAt) {
            $output->writeln("  <comment>Last Access:</comment>  {$session->lastAccessedAt->format('Y-m-d H:i:s')}");
        }
        if ($session->claudeSessionId) {
            $output->writeln("  <comment>Claude ID:</comment>    {$session->claudeSessionId}");
        }

        if (isset($info['status_updated']) && $info['status_updated']) {
            $output->writeln("");
            $output->writeln("<info>Status was updated based on tmux state</info>");
        }

        if ($captureLines !== false && isset($info['pane_content'])) {
            $output->writeln("");
            $output->writeln("<comment>Pane Content (last {$lines} lines):</comment>");
            $output->writeln(str_repeat('-', 50));
            $output->writeln($info['pane_content']);
            $output->writeln(str_repeat('-', 50));
        }

        $output->writeln("");

        return self::SUCCESS;
    }
}
