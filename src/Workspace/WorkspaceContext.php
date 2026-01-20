<?php

declare(strict_types=1);

namespace Aoe\Workspace;

/**
 * Holds the current workspace context for the request lifecycle
 *
 * This is a static singleton-like class that stores the current workspace ID
 * for use throughout the application. It must be set before any workspace-scoped
 * operations are performed.
 */
class WorkspaceContext
{
    private static ?string $currentWorkspace = null;

    /**
     * Set the current workspace ID
     */
    public static function set(string $workspaceId): void
    {
        self::$currentWorkspace = $workspaceId;
    }

    /**
     * Get the current workspace ID
     *
     * @throws WorkspaceRequiredException if no workspace is set
     */
    public static function get(): string
    {
        if (self::$currentWorkspace === null) {
            throw new WorkspaceRequiredException();
        }

        return self::$currentWorkspace;
    }

    /**
     * Try to get the current workspace ID, returning null if not set
     */
    public static function tryGet(): ?string
    {
        return self::$currentWorkspace;
    }

    /**
     * Check if a workspace is currently set
     */
    public static function isSet(): bool
    {
        return self::$currentWorkspace !== null;
    }

    /**
     * Clear the current workspace context
     */
    public static function clear(): void
    {
        self::$currentWorkspace = null;
    }
}
