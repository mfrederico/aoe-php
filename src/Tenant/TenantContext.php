<?php

declare(strict_types=1);

namespace Aoe\Tenant;

/**
 * Holds the current tenant context for the request lifecycle
 *
 * This is a static singleton-like class that stores the current tenant ID
 * for use throughout the application. It must be set before any tenant-scoped
 * operations are performed.
 */
class TenantContext
{
    private static ?string $currentTenant = null;

    /**
     * Set the current tenant ID
     */
    public static function set(string $tenantId): void
    {
        self::$currentTenant = $tenantId;
    }

    /**
     * Get the current tenant ID
     *
     * @throws TenantRequiredException if no tenant is set
     */
    public static function get(): string
    {
        if (self::$currentTenant === null) {
            throw new TenantRequiredException();
        }

        return self::$currentTenant;
    }

    /**
     * Try to get the current tenant ID, returning null if not set
     */
    public static function tryGet(): ?string
    {
        return self::$currentTenant;
    }

    /**
     * Check if a tenant is currently set
     */
    public static function isSet(): bool
    {
        return self::$currentTenant !== null;
    }

    /**
     * Clear the current tenant context
     */
    public static function clear(): void
    {
        self::$currentTenant = null;
    }
}
