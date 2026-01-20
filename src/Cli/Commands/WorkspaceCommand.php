<?php

declare(strict_types=1);

namespace Aoe\Cli\Commands;

use Aoe\Config\AoeConfig;
use Aoe\Session\Storage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

/**
 * Command to list available workspaces
 *
 * This command does not require a workspace to be specified.
 */
class WorkspaceCommand extends Command
{
    protected static $defaultName = 'workspace:list';
    protected static $defaultDescription = 'List available workspaces';

    protected function configure(): void
    {
        $this->setHelp('Lists all available workspaces discovered from myctobot config files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = AoeConfig::createDefault();
        $workspaces = $config->listTenants();

        if (empty($workspaces)) {
            $output->writeln('<comment>No workspaces found.</comment>');
            $output->writeln('');
            $output->writeln('Tenants are discovered from myctobot config files:');
            $output->writeln('  myctobot/conf/config.{workspace}.ini');
            $output->writeln('');
            $output->writeln('Create a config file to add a workspace.');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Tenant', 'Sessions', 'Has AOE Config']);

        foreach ($workspaces as $workspace) {
            $storage = new Storage($workspace, $config->get($workspace, 'storage_path'));
            $sessionCount = $storage->count();

            // Check if workspace has [aoe] section in config
            $workspaceConfig = $config->forWorkspace($workspace);
            $hasAoeConfig = $workspaceConfig !== $config->getDefaults() ? 'Yes' : 'No';

            $table->addRow([
                $workspace,
                $sessionCount,
                $hasAoeConfig,
            ]);
        }

        $table->render();

        $output->writeln('');
        $output->writeln(sprintf('<info>Total: %d workspace(s)</info>', count($workspaces)));

        return Command::SUCCESS;
    }
}
