<?php

declare(strict_types=1);

namespace Aoe\Workspace;

use RuntimeException;

/**
 * Exception thrown when a workspace is required but not provided
 */
class WorkspaceRequiredException extends RuntimeException
{
    public function __construct(string $message = 'Workspace required. Use --workspace=ID or set AOE_WORKSPACE environment variable.')
    {
        parent::__construct($message);
    }
}
