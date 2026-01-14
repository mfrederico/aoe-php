<?php

declare(strict_types=1);

namespace Aoe\Tenant;

use RuntimeException;

/**
 * Exception thrown when a tenant is required but not provided
 */
class TenantRequiredException extends RuntimeException
{
    public function __construct(string $message = 'Tenant required. Use --tenant=ID or set AOE_TENANT environment variable.')
    {
        parent::__construct($message);
    }
}
