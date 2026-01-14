<?php

declare(strict_types=1);

namespace Aoe\Session;

use RuntimeException;

/**
 * JSON file-based storage for session instances
 *
 * Each tenant has isolated storage at:
 * {basePath}/tenants/{tenantId}/sessions.json
 */
class Storage
{
    private string $tenantId;
    private string $basePath;
    private ?array $cache = null;

    public function __construct(string $tenantId, ?string $basePath = null)
    {
        $this->tenantId = $tenantId;
        $this->basePath = $basePath ?? ($_SERVER['HOME'] . '/.aoe-php');
    }

    /**
     * Get the storage directory for this tenant
     */
    public function getStorageDir(): string
    {
        return "{$this->basePath}/tenants/{$this->tenantId}";
    }

    /**
     * Get the sessions file path
     */
    public function getSessionsPath(): string
    {
        return "{$this->getStorageDir()}/sessions.json";
    }

    /**
     * Get the groups file path
     */
    public function getGroupsPath(): string
    {
        return "{$this->getStorageDir()}/groups.json";
    }

    /**
     * Ensure the storage directory exists
     */
    public function ensureStorageDir(): void
    {
        $dir = $this->getStorageDir();
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("Failed to create storage directory: {$dir}");
            }
        }
    }

    /**
     * Load all sessions for this tenant
     *
     * @return Instance[]
     */
    public function loadAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $path = $this->getSessionsPath();

        if (!file_exists($path)) {
            $this->cache = [];
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read sessions file: {$path}");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON in sessions file: " . json_last_error_msg());
        }

        $sessions = [];
        foreach ($data['sessions'] ?? [] as $sessionData) {
            // Ensure tenant ID is set
            $sessionData['tenant_id'] = $this->tenantId;
            $sessions[] = Instance::fromArray($sessionData);
        }

        $this->cache = $sessions;
        return $sessions;
    }

    /**
     * Find a session by ID
     */
    public function find(string $id): ?Instance
    {
        $sessions = $this->loadAll();
        foreach ($sessions as $session) {
            if ($session->id === $id) {
                return $session;
            }
        }
        return null;
    }

    /**
     * Find a session by ID prefix (for short ID lookups)
     */
    public function findByPrefix(string $prefix): ?Instance
    {
        $sessions = $this->loadAll();
        $matches = [];

        foreach ($sessions as $session) {
            if (str_starts_with($session->id, $prefix)) {
                $matches[] = $session;
            }
        }

        // Return only if there's exactly one match
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Save a session (create or update)
     */
    public function save(Instance $instance): void
    {
        $sessions = $this->loadAll();

        // Find and replace existing, or add new
        $found = false;
        foreach ($sessions as $i => $session) {
            if ($session->id === $instance->id) {
                $sessions[$i] = $instance;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $sessions[] = $instance;
        }

        $this->saveAll($sessions);
    }

    /**
     * Delete a session by ID
     */
    public function delete(string $id): bool
    {
        $sessions = $this->loadAll();
        $initialCount = count($sessions);

        $sessions = array_values(array_filter(
            $sessions,
            fn(Instance $s) => $s->id !== $id
        ));

        if (count($sessions) === $initialCount) {
            return false;
        }

        $this->saveAll($sessions);
        return true;
    }

    /**
     * Get sessions filtered by group path
     *
     * @return Instance[]
     */
    public function findByGroup(string $groupPath): array
    {
        $sessions = $this->loadAll();

        if ($groupPath === '') {
            return $sessions;
        }

        return array_values(array_filter(
            $sessions,
            fn(Instance $s) => $s->groupPath === $groupPath || str_starts_with($s->groupPath, $groupPath . '/')
        ));
    }

    /**
     * Save all sessions to disk
     *
     * @param Instance[] $sessions
     */
    private function saveAll(array $sessions): void
    {
        $this->ensureStorageDir();
        $path = $this->getSessionsPath();

        // Create backup before saving
        if (file_exists($path)) {
            $backupPath = "{$path}.bak";
            copy($path, $backupPath);
        }

        $data = [
            'version' => 1,
            'sessions' => array_map(fn(Instance $s) => $s->toArray(), $sessions),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("Failed to encode sessions to JSON");
        }

        $result = file_put_contents($path, $json, LOCK_EX);
        if ($result === false) {
            throw new RuntimeException("Failed to write sessions file: {$path}");
        }

        // Update cache
        $this->cache = $sessions;
    }

    /**
     * Clear the in-memory cache
     */
    public function clearCache(): void
    {
        $this->cache = null;
    }

    /**
     * Get the count of sessions
     */
    public function count(): int
    {
        return count($this->loadAll());
    }
}
