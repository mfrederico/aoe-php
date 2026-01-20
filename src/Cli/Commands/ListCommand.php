<?php

declare(strict_types=1);

namespace Aoe\Cli\Commands;

use Aoe\Session\Instance;
use Aoe\Session\Status;
use Aoe\Workspace\WorkspaceRequiredException;
use Aoe\Tmux\TmuxService;
use Aoe\Tmux\StatusDetector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to list sessions
 */
class ListCommand extends BaseCommand
{
    protected static $defaultName = 'sessions';
    protected static $defaultDescription = 'List AI agent sessions';

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption(
                'group',
                'g',
                InputOption::VALUE_REQUIRED,
                'Filter by group path'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output in JSON format'
            )
            ->setHelp(<<<'HELP'
List all sessions for the specified workspace.

Status is always detected live from tmux (source of truth).

Examples:
  aoe --workspace=acme sessions
  aoe --workspace=acme sessions --group="frontend"
  aoe --workspace=acme sessions --json
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initialize($input, $output);
        } catch (WorkspaceRequiredException) {
            return Command::FAILURE;
        }

        $groupFilter = $input->getOption('group');
        $jsonOutput = $input->getOption('json');

        // TMUX IS THE SOURCE OF TRUTH
        // Query tmux first, then match against storage for metadata
        $tmux = new TmuxService($this->getWorkspaceId());
        $detector = new StatusDetector();
        $tmuxSessions = $tmux->listSessions();

        // Load stored sessions for metadata lookup
        $storedSessions = $this->getStorage()->loadAll();
        $storedByTmuxName = [];
        foreach ($storedSessions as $stored) {
            $storedByTmuxName[$stored->getTmuxName()] = $stored;
        }

        // Build session list from tmux (source of truth)
        $sessions = [];
        foreach ($tmuxSessions as $sessionId => $tmuxInfo) {
            $tmuxName = $tmuxInfo['name'];

            if (isset($storedByTmuxName[$tmuxName])) {
                // Found in storage - use stored metadata
                $session = $storedByTmuxName[$tmuxName];
                unset($storedByTmuxName[$tmuxName]); // Mark as matched
            } else {
                // Not in storage - create session from tmux info
                // Parse session name: aoe-{workspace}-{reference}-{shortId}
                $parts = explode('-', $tmuxName);
                $shortId = end($parts);
                // Reference is everything between workspace and shortId
                $reference = implode('-', array_slice($parts, 2, -1));

                // Create session with the actual short ID from tmux name
                // Pad the ID to 16 chars to match expected format
                $fullId = str_pad($shortId, 16, '0');

                $session = Instance::fromArray([
                    'id' => $fullId,
                    'workspace_id' => $this->getWorkspaceId(),
                    'title' => $reference ?: $sessionId,
                    'project_path' => "/tmp/{$tmuxName}",
                    'group_path' => $this->getWorkspaceId(),
                    'command' => '',
                    'tool' => 'claude',
                    'status' => 'idle',
                    'created_at' => $tmuxInfo['created'],
                    'reference' => $reference,
                ]);

                // Save to storage so it's tracked
                $this->getStorage()->save($session);
                $output->writeln(sprintf(
                    '<comment>Discovered session from tmux: %s</comment>',
                    $reference ?: $sessionId
                ));
            }

            // Detect live status from tmux pane content
            $content = $tmux->capturePaneByName($tmuxName, 20);
            $newStatus = $detector->detect($content);
            if ($newStatus !== $session->status) {
                $session->status = $newStatus;
                $this->getStorage()->save($session);
            }

            // Apply group filter if specified
            if ($groupFilter && $session->groupPath !== $groupFilter &&
                !str_starts_with($session->groupPath, $groupFilter . '/')) {
                continue;
            }

            $sessions[] = $session;
        }

        // Clean up stored sessions that no longer exist in tmux
        foreach ($storedByTmuxName as $tmuxName => $orphanedSession) {
            $this->getStorage()->delete($orphanedSession->id);
            $output->writeln(sprintf(
                '<comment>Removed stopped session: %s (%s)</comment>',
                $orphanedSession->title,
                $orphanedSession->getShortId()
            ));
        }

        if ($jsonOutput) {
            $output->writeln(json_encode(
                array_map(fn(Instance $s) => $s->toArray(), $sessions),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));
            return Command::SUCCESS;
        }

        if (empty($sessions)) {
            $output->writeln('<comment>No sessions found.</comment>');
            $output->writeln('');
            $output->writeln('Create a session with:');
            $output->writeln("  aoe --workspace={$this->getWorkspaceId()} add /path/to/project");
            return Command::SUCCESS;
        }

        // Sort by group path, then by title
        usort($sessions, function (Instance $a, Instance $b) {
            $groupCmp = strcmp($a->groupPath, $b->groupPath);
            if ($groupCmp !== 0) {
                return $groupCmp;
            }
            return strcmp($a->title, $b->title);
        });

        $table = new Table($output);
        $table->setHeaders(['ID', 'Title', 'Status', 'Tool', 'Group', 'Path']);

        foreach ($sessions as $session) {
            $statusDisplay = $session->status->icon() . ' ' . $session->status->label();

            $table->addRow([
                $session->getShortId(),
                $this->truncate($session->title, 30),
                $statusDisplay,
                $session->tool,
                $session->groupPath ?: '-',
                $this->truncate($session->projectPath, 40),
            ]);
        }

        $table->render();

        $output->writeln('');
        $output->writeln(sprintf('<info>Total: %d session(s)</info>', count($sessions)));

        return Command::SUCCESS;
    }

    /**
     * Truncate a string to a maximum length
     */
    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3) . '...';
    }
}
