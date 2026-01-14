<?php

declare(strict_types=1);

namespace Aoe\Tenant;

/**
 * Resolves the tenant ID from various sources
 *
 * Resolution priority:
 * 1. Explicit parameter (e.g., --tenant=X from CLI)
 * 2. Environment variable (AOE_TENANT)
 * 3. Throws TenantRequiredException
 */
class TenantResolver
{
    private const ENV_VAR = 'AOE_TENANT';

    /**
     * Resolve the tenant ID from available sources
     *
     * @param string|null $explicit Explicitly provided tenant ID (e.g., from CLI)
     * @return string The resolved tenant ID
     * @throws TenantRequiredException if no tenant can be resolved
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

        // No tenant found
        throw new TenantRequiredException();
    }

    /**
     * Try to resolve the tenant ID, returning null if not available
     *
     * @param string|null $explicit Explicitly provided tenant ID
     * @return string|null The resolved tenant ID or null
     */
    public function tryResolve(?string $explicit = null): ?string
    {
        try {
            return $this->resolve($explicit);
        } catch (TenantRequiredException) {
            return null;
        }
    }

    /**
     * Resolve and set the tenant in TenantContext
     *
     * @param string|null $explicit Explicitly provided tenant ID
     * @return string The resolved tenant ID
     * @throws TenantRequiredException if no tenant can be resolved
     */
    public function resolveAndSet(?string $explicit = null): string
    {
        $tenantId = $this->resolve($explicit);
        TenantContext::set($tenantId);
        return $tenantId;
    }

    /**
     * Normalize a tenant ID (lowercase, trim whitespace)
     */
    private function normalize(string $tenantId): string
    {
        return strtolower(trim($tenantId));
    }
}
