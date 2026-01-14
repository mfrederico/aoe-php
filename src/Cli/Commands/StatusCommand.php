<?php

declare(strict_types=1);

namespace Aoe\Cli\Commands;

use Aoe\Session\Instance;
use Aoe\Session\Status;
use Aoe\Tenant\TenantRequiredException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to show status summary
 */
class StatusCommand extends BaseCommand
{
    protected static $defaultName = 'status';
    protected static $defaultDescription = 'Show session status summary';

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output in JSON format'
            )
            ->setHelp(<<<'HELP'
Show a summary of session statuses for the specified tenant.

Examples:
  aoe --tenant=acme status
  aoe --tenant=acme status --json
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

        $jsonOutput = $input->getOption('json');
        $sessions = $this->getStorage()->loadAll();

        // Count by status
        $statusCounts = [];
        foreach (Status::cases() as $status) {
            $statusCounts[$status->value] = 0;
        }

        foreach ($sessions as $session) {
            $statusCounts[$session->status->value]++;
        }

        // Count by tool
        $toolCounts = [];
        foreach ($sessions as $session) {
            $toolCounts[$session->tool] = ($toolCounts[$session->tool] ?? 0) + 1;
        }

        // Get active sessions
        $activeSessions = array_filter(
            $sessions,
            fn(Instance $s) => $s->status->isActive()
        );

        if ($jsonOutput) {
            $output->writeln(json_encode([
                'tenant' => $this->getTenantId(),
                'total' => count($sessions),
                'active' => count($activeSessions),
                'by_status' => $statusCounts,
                'by_tool' => $toolCounts,
            ], JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $output->writeln("<info>Session Status for tenant: {$this->getTenantId()}</info>");
        $output->writeln('');

        if (empty($sessions)) {
            $output->writeln('<comment>No sessions configured.</comment>');
            return Command::SUCCESS;
        }

        // Status summary
        $output->writeln('<comment>By Status:</comment>');
        foreach (Status::cases() as $status) {
            $count = $statusCounts[$status->value];
            if ($count > 0 || in_array($status, [Status::Running, Status::Waiting, Status::Idle, Status::Stopped])) {
                $output->writeln(sprintf(
                    "  %s %-10s %d",
                    $status->icon(),
                    $status->label() . ':',
                    $count
                ));
            }
        }

        $output->writeln('');

        // Tool summary
        $output->writeln('<comment>By Tool:</comment>');
        foreach ($toolCounts as $tool => $count) {
            $output->writeln(sprintf("  %-12s %d", $tool . ':', $count));
        }

        $output->writeln('');
        $output->writeln(sprintf('Total: %d session(s), %d active', count($sessions), count($activeSessions)));

        // Show active sessions if any
        if (!empty($activeSessions)) {
            $output->writeln('');
            $output->writeln('<comment>Active Sessions:</comment>');
            foreach ($activeSessions as $session) {
                $output->writeln(sprintf(
                    "  %s %s - %s (%s)",
                    $session->status->icon(),
                    $session->getShortId(),
                    $session->title,
                    $session->status->label()
                ));
            }
        }

        return Command::SUCCESS;
    }
}
