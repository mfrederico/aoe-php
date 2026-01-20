<?php

declare(strict_types=1);

namespace Aoe\Cli\Commands;

use Aoe\Config\AoeConfig;
use Aoe\Session\Storage;
use Aoe\Workspace\WorkspaceContext;
use Aoe\Workspace\WorkspaceRequiredException;
use Aoe\Workspace\WorkspaceResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base command class with workspace support
 *
 * Provides common functionality for all AOE commands including
 * workspace resolution, configuration, and storage access.
 */
abstract class BaseCommand extends Command
{
    protected ?AoeConfig $config = null;
    protected ?Storage $storage = null;
    protected ?string $workspaceId = null;

    /**
     * Whether this command requires a workspace
     */
    protected bool $requiresTenant = true;

    protected function configure(): void
    {
        if ($this->requiresTenant) {
            $this->addOption(
                'workspace',
                't',
                InputOption::VALUE_REQUIRED,
                'Tenant ID (or set AOE_WORKSPACE environment variable)'
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
     * Initialize workspace context
     */
    protected function initializeTenant(InputInterface $input, OutputInterface $output): void
    {
        $resolver = new WorkspaceResolver();

        try {
            $explicit = $input->getOption('workspace');
            $this->workspaceId = $resolver->resolveAndSet($explicit);
            $this->storage = new Storage(
                $this->workspaceId,
                $this->config->get($this->workspaceId, 'storage_path')
            );
        } catch (WorkspaceRequiredException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            $output->writeln('');
            $output->writeln('Available workspaces:');

            $workspaces = $this->config->listTenants();
            if (empty($workspaces)) {
                $output->writeln('  <comment>No workspaces configured. Add config files to myctobot/conf/</comment>');
            } else {
                foreach ($workspaces as $workspace) {
                    $output->writeln("  - {$workspace}");
                }
            }

            throw $e;
        }
    }

    /**
     * Get the current workspace ID
     */
    protected function getWorkspaceId(): string
    {
        if ($this->workspaceId === null) {
            return WorkspaceContext::get();
        }
        return $this->workspaceId;
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
     * Get workspace-specific config value
     */
    protected function getConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->getConfig()->get($this->getWorkspaceId(), $key, $default);
    }
}
