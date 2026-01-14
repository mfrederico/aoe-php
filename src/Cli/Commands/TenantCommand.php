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
 * Command to list available tenants
 *
 * This command does not require a tenant to be specified.
 */
class TenantCommand extends Command
{
    protected static $defaultName = 'tenant:list';
    protected static $defaultDescription = 'List available tenants';

    protected function configure(): void
    {
        $this->setHelp('Lists all available tenants discovered from myctobot config files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = AoeConfig::createDefault();
        $tenants = $config->listTenants();

        if (empty($tenants)) {
            $output->writeln('<comment>No tenants found.</comment>');
            $output->writeln('');
            $output->writeln('Tenants are discovered from myctobot config files:');
            $output->writeln('  myctobot/conf/config.{tenant}.ini');
            $output->writeln('');
            $output->writeln('Create a config file to add a tenant.');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Tenant', 'Sessions', 'Has AOE Config']);

        foreach ($tenants as $tenant) {
            $storage = new Storage($tenant, $config->get($tenant, 'storage_path'));
            $sessionCount = $storage->count();

            // Check if tenant has [aoe] section in config
            $tenantConfig = $config->forTenant($tenant);
            $hasAoeConfig = $tenantConfig !== $config->getDefaults() ? 'Yes' : 'No';

            $table->addRow([
                $tenant,
                $sessionCount,
                $hasAoeConfig,
            ]);
        }

        $table->render();

        $output->writeln('');
        $output->writeln(sprintf('<info>Total: %d tenant(s)</info>', count($tenants)));

        return Command::SUCCESS;
    }
}
