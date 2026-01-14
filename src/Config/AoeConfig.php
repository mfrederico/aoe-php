<?php

declare(strict_types=1);

namespace Aoe\Config;

/**
 * Configuration loader that merges defaults with tenant-specific settings
 *
 * Reads tenant-specific configuration from myctobot's INI files:
 * myctobot/conf/config.{tenant}.ini under the [aoe] section
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
     * Get configuration merged with tenant-specific overrides
     *
     * @param string $tenantId The tenant ID
     * @return array Merged configuration
     */
    public function forTenant(string $tenantId): array
    {
        if (isset($this->cache[$tenantId])) {
            return $this->cache[$tenantId];
        }

        $tenantConfig = $this->loadTenantIni($tenantId);
        $aoeConfig = $tenantConfig['aoe'] ?? [];

        // Deep merge the configs
        $merged = $this->deepMerge($this->defaults, $aoeConfig);

        $this->cache[$tenantId] = $merged;
        return $merged;
    }

    /**
     * Get a specific config value for a tenant
     *
     * @param string $tenantId The tenant ID
     * @param string $key Dot-notation key (e.g., 'websocket.port')
     * @param mixed $default Default value if key not found
     * @return mixed The config value
     */
    public function get(string $tenantId, string $key, mixed $default = null): mixed
    {
        $config = $this->forTenant($tenantId);
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
     * List all available tenants by scanning config files
     *
     * @return array List of tenant IDs
     */
    public function listTenants(): array
    {
        $pattern = "{$this->myctoConfPath}/config.*.ini";
        $files = glob($pattern);

        if ($files === false) {
            return [];
        }

        $tenants = [];
        foreach ($files as $file) {
            if (preg_match('/config\.(.+)\.ini$/', basename($file), $matches)) {
                $tenants[] = $matches[1];
            }
        }

        sort($tenants);
        return $tenants;
    }

    /**
     * Check if a tenant has a configuration file
     */
    public function tenantExists(string $tenantId): bool
    {
        $file = "{$this->myctoConfPath}/config.{$tenantId}.ini";
        return file_exists($file);
    }

    /**
     * Get the storage path for a tenant
     */
    public function getStoragePath(string $tenantId): string
    {
        $config = $this->forTenant($tenantId);
        $basePath = $config['storage_path'] ?? $_SERVER['HOME'] . '/.aoe-php';
        return "{$basePath}/tenants/{$tenantId}";
    }

    /**
     * Load tenant-specific INI configuration
     */
    private function loadTenantIni(string $tenantId): array
    {
        $file = "{$this->myctoConfPath}/config.{$tenantId}.ini";

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
