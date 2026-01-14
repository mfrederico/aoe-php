<?php

declare(strict_types=1);

namespace Aoe\Cli\Commands;

use Aoe\Config\AoeConfig;
use Aoe\Session\Storage;
use Aoe\Tenant\TenantContext;
use Aoe\Tenant\TenantRequiredException;
use Aoe\Tenant\TenantResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base command class with tenant support
 *
 * Provides common functionality for all AOE commands including
 * tenant resolution, configuration, and storage access.
 */
abstract class BaseCommand extends Command
{
    protected ?AoeConfig $config = null;
    protected ?Storage $storage = null;
    protected ?string $tenantId = null;

    /**
     * Whether this command requires a tenant
     */
    protected bool $requiresTenant = true;

    protected function configure(): void
    {
        if ($this->requiresTenant) {
            $this->addOption(
                'tenant',
                't',
                InputOption::VALUE_REQUIRED,
                'Tenant ID (or set AOE_TENANT environment variable)'
            );
        }
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->config = AoeConfig::createDefault();

        if ($this->requiresTenant) {
            $this->initializeTenant($input, $output);
        }
    }

    /**
     * Initialize tenant context
     */
    protected function initializeTenant(InputInterface $input, OutputInterface $output): void
    {
        $resolver = new TenantResolver();

        try {
            $explicit = $input->getOption('tenant');
            $this->tenantId = $resolver->resolveAndSet($explicit);
            $this->storage = new Storage(
                $this->tenantId,
                $this->config->get($this->tenantId, 'storage_path')
            );
        } catch (TenantRequiredException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            $output->writeln('');
            $output->writeln('Available tenants:');

            $tenants = $this->config->listTenants();
            if (empty($tenants)) {
                $output->writeln('  <comment>No tenants configured. Add config files to myctobot/conf/</comment>');
            } else {
                foreach ($tenants as $tenant) {
                    $output->writeln("  - {$tenant}");
                }
            }

            throw $e;
        }
    }

    /**
     * Get the current tenant ID
     */
    protected function getTenantId(): string
    {
        if ($this->tenantId === null) {
            return TenantContext::get();
        }
        return $this->tenantId;
    }

    /**
     * Get the storage instance
     */
    protected function getStorage(): Storage
    {
        if ($this->storage === null) {
            throw new \RuntimeException('Storage not initialized. Call initializeTenant first.');
        }
        return $this->storage;
    }

    /**
     * Get the config instance
     */
    protected function getConfig(): AoeConfig
    {
        if ($this->config === null) {
            $this->config = AoeConfig::createDefault();
        }
        return $this->config;
    }

    /**
     * Get tenant-specific config value
     */
    protected function getConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->getConfig()->get($this->getTenantId(), $key, $default);
    }
}
