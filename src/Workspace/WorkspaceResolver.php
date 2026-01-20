<?php

declare(strict_types=1);

namespace Aoe\Workspace;

/**
 * Resolves the workspace ID from various sources
 *
 * Resolution priority:
 * 1. Explicit parameter (e.g., --workspace=X from CLI)
 * 2. Environment variable (AOE_WORKSPACE)
 * 3. Throws WorkspaceRequiredException
 */
class WorkspaceResolver
{
    private const ENV_VAR = 'AOE_WORKSPACE';

    /**
     * Resolve the workspace ID from available sources
     *
     * @param string|null $explicit Explicitly provided workspace ID (e.g., from CLI)
     * @return string The resolved workspace ID
     * @throws WorkspaceRequiredException if no workspace can be resolved
     */
    public function resolve(?string $explicit = null): string
    {
        // Priority 1: Explicit parameter
        if ($explicit !== null && $explicit !== '') {
            return $this->normalize($explicit);
        }

        // Priority 2: Environment variable
        $env = getenv(self::ENV_VAR);
        if ($env !== false && $env !== '') {
            return $this->normalize($env);
        }

        // No workspace found
        throw new WorkspaceRequiredException();
    }

    /**
     * Try to resolve the workspace ID, returning null if not available
     *
     * @param string|null $explicit Explicitly provided workspace ID
     * @return string|null The resolved workspace ID or null
     */
    public function tryResolve(?string $explicit = null): ?string
    {
        try {
            return $this->resolve($explicit);
        } catch (WorkspaceRequiredException) {
            return null;
        }
    }

    /**
     * Resolve and set the workspace in WorkspaceContext
     *
     * @param string|null $explicit Explicitly provided workspace ID
     * @return string The resolved workspace ID
     * @throws WorkspaceRequiredException if no workspace can be resolved
     */
    public function resolveAndSet(?string $explicit = null): string
    {
        $workspaceId = $this->resolve($explicit);
        WorkspaceContext::set($workspaceId);
        return $workspaceId;
    }

    /**
     * Normalize a workspace ID (lowercase, trim whitespace)
     */
    private function normalize(string $workspaceId): string
    {
        return strtolower(trim($workspaceId));
    }
}
