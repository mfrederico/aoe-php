<?php

declare(strict_types=1);

namespace Aoe\Cli\Commands;

use Aoe\Session\Instance;
use Aoe\Session\Status;
use Aoe\Tenant\TenantRequiredException;
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
List all sessions for the specified tenant.

Status is always detected live from tmux.

Examples:
  aoe --tenant=acme sessions
  aoe --tenant=acme sessions --group="frontend"
  aoe --tenant=acme sessions --json
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

        $groupFilter = $input->getOption('group');
        $jsonOutput = $input->getOption('json');

        // Load sessions
        $sessions = $groupFilter
            ? $this->getStorage()->findByGroup($groupFilter)
            : $this->getStorage()->loadAll();

        // Always refresh status live from tmux
        if (!empty($sessions)) {
            $tmux = new TmuxService($this->getTenantId());
            $detector = new StatusDetector();
            $updated = false;

            foreach ($sessions as $session) {
                $tmuxName = $session->getTmuxName();
                if ($tmux->sessionExistsByName($tmuxName)) {
                    // Session exists - detect status from pane content
                    $content = $tmux->capturePaneByName($tmuxName, 20);
                    $newStatus = $detector->detect($content);
                    if ($newStatus !== $session->status) {
                        $session->status = $newStatus;
                        $updated = true;
                    }
                } elseif ($session->status->isActive()) {
                    // Tmux doesn't exist but status says active - mark stopped
                    $session->status = Status::Stopped;
                    $updated = true;
                }
            }

            if ($updated) {
                // Re-save all sessions
                foreach ($sessions as $session) {
                    $this->getStorage()->save($session);
                }
            }
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
            $output->writeln("  aoe --tenant={$this->getTenantId()} add /path/to/project");
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
