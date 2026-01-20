<?php

declare(strict_types=1);

namespace Aoe\Cli\Commands;

use Aoe\Session\Instance;
use Aoe\Workspace\WorkspaceRequiredException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to add a new session
 */
class AddCommand extends BaseCommand
{
    protected static $defaultName = 'add';
    protected static $defaultDescription = 'Add a new AI agent session';

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Project path (defaults to current directory)'
            )
            ->addOption(
                'title',
                null,
                InputOption::VALUE_REQUIRED,
                'Session title (defaults to directory name)'
            )
            ->addOption(
                'group',
                'g',
                InputOption::VALUE_REQUIRED,
                'Group path (e.g., "frontend/web")'
            )
            ->addOption(
                'tool',
                null,
                InputOption::VALUE_REQUIRED,
                'Tool to use (claude, opencode)',
                'claude'
            )
            ->addOption(
                'cmd',
                'c',
                InputOption::VALUE_REQUIRED,
                'Custom command to run instead of default tool'
            )
            ->setHelp(<<<'HELP'
Add a new AI agent session for the specified project.

Examples:
  aoe --workspace=acme add
  aoe --workspace=acme add /path/to/project
  aoe --workspace=acme add --title="My Project" --group="frontend/web"
  aoe --workspace=acme add --tool=opencode
  aoe --workspace=acme add --cmd="claude --model opus"
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

        // Get project path
        $path = $input->getArgument('path') ?? getcwd();
        $path = realpath($path);

        if ($path === false) {
            $output->writeln('<error>Invalid path: ' . ($input->getArgument('path') ?? getcwd()) . '</error>');
            return Command::FAILURE;
        }

        if (!is_dir($path)) {
            $output->writeln('<error>Path is not a directory: ' . $path . '</error>');
            return Command::FAILURE;
        }

        // Get title (default to directory name)
        $title = $input->getOption('title') ?? basename($path);

        // Get other options
        $groupPath = $input->getOption('group') ?? '';
        $tool = $input->getOption('tool') ?? $this->getConfigValue('default_tool', 'claude');
        $command = $input->getOption('cmd') ?? '';

        // Create the instance
        $instance = Instance::create(
            workspaceId: $this->getWorkspaceId(),
            title: $title,
            projectPath: $path,
            tool: $tool,
            groupPath: $groupPath,
            command: $command,
        );

        // Save to storage
        $this->getStorage()->save($instance);

        $output->writeln('<info>Session created successfully!</info>');
        $output->writeln('');
        $output->writeln("  ID:      {$instance->id}");
        $output->writeln("  Title:   {$instance->title}");
        $output->writeln("  Path:    {$instance->projectPath}");
        $output->writeln("  Tool:    {$instance->tool}");

        if ($groupPath !== '') {
            $output->writeln("  Group:   {$groupPath}");
        }

        if ($command !== '') {
            $output->writeln("  Command: {$command}");
        }

        $output->writeln('');
        $output->writeln("To start this session:");
        $output->writeln("  aoe --workspace={$this->getWorkspaceId()} session:start {$instance->getShortId()}");

        return Command::SUCCESS;
    }
}
