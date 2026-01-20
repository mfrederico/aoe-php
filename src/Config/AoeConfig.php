<?php

declare(strict_types=1);

namespace Aoe\Config;

/**
 * Configuration loader that merges defaults with workspace-specific settings
 *
 * Reads workspace-specific configuration from myctobot's INI files:
 * myctobot/conf/config.{workspace}.ini under the [aoe] section
 */
class AoeConfig
{
    private array $defaults;
    private string $myctoConfPath;
    private array $cache = [];

    /**
     * @param array $defaults Default configuration values
     * @param string $myctoConfPath Path to myctobot conf directory
     */
    public function __construct(array $defaults, string $myctoConfPath)
    {
        $this->defaults = $defaults;
        $this->myctoConfPath = rtrim($myctoConfPath, '/');
    }

    /**
     * Create an instance using the default config file
     */
    public static function createDefault(?string $myctoConfPath = null): self
    {
        $configFile = dirname(__DIR__, 2) . '/config/aoe.php';
        $defaults = file_exists($configFile) ? require $configFile : [];

        $confPath = $myctoConfPath ?? ($defaults['myctobot_conf_path'] ?? dirname(__DIR__, 3) . '/myctobot/conf');

        return new self($defaults, $confPath);
    }

    /**
     * Get configuration merged with workspace-specific overrides
     *
     * @param string $workspaceId The workspace ID
     * @return array Merged configuration
     */
    public function forWorkspace(string $workspaceId): array
    {
        if (isset($this->cache[$workspaceId])) {
            return $this->cache[$workspaceId];
        }

        $workspaceConfig = $this->loadWorkspaceIni($workspaceId);
        $aoeConfig = $workspaceConfig['aoe'] ?? [];

        // Deep merge the configs
        $merged = $this->deepMerge($this->defaults, $aoeConfig);

        $this->cache[$workspaceId] = $merged;
        return $merged;
    }

    /**
     * Get a specific config value for a workspace
     *
     * @param string $workspaceId The workspace ID
     * @param string $key Dot-notation key (e.g., 'websocket.port')
     * @param mixed $default Default value if key not found
     * @return mixed The config value
     */
    public function get(string $workspaceId, string $key, mixed $default = null): mixed
    {
        $config = $this->forWorkspace($workspaceId);
        return $this->getByDotNotation($config, $key, $default);
    }

    /**
     * Get the default configuration
     */
    public function getDefaults(): array
    {
        return $this->defaults;
    }

    /**
     * List all available workspaces by scanning config files
     *
     * @return array List of workspace IDs
     */
    public function listTenants(): array
    {
        $pattern = "{$this->myctoConfPath}/config.*.ini";
        $files = glob($pattern);

        if ($files === false) {
            return [];
        }

        $workspaces = [];
        foreach ($files as $file) {
            if (preg_match('/config\.(.+)\.ini$/', basename($file), $matches)) {
                $workspaces[] = $matches[1];
            }
        }

        sort($workspaces);
        return $workspaces;
    }

    /**
     * Check if a workspace has a configuration file
     */
    public function workspaceExists(string $workspaceId): bool
    {
        $file = "{$this->myctoConfPath}/config.{$workspaceId}.ini";
        return file_exists($file);
    }

    /**
     * Get the storage path for a workspace
     */
    public function getStoragePath(string $workspaceId): string
    {
        $config = $this->forWorkspace($workspaceId);
        $basePath = $config['storage_path'] ?? $_SERVER['HOME'] . '/.aoe-php';
        return "{$basePath}/workspaces/{$workspaceId}";
    }

    /**
     * Load workspace-specific INI configuration
     */
    private function loadWorkspaceIni(string $workspaceId): array
    {
        $file = "{$this->myctoConfPath}/config.{$workspaceId}.ini";

        if (!file_exists($file)) {
            return [];
        }

        $parsed = parse_ini_file($file, true, INI_SCANNER_TYPED);
        return $parsed !== false ? $parsed : [];
    }

    /**
     * Deep merge two arrays
     */
    private function deepMerge(array $base, array $override): array
    {
        $result = $base;

        foreach ($override as $key => $value) {
            if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = $this->deepMerge($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get value by dot notation key
     */
    private function getByDotNotation(array $array, string $key, mixed $default): mixed
    {
        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
