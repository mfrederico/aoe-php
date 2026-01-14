<?php

declare(strict_types=1);

namespace Aoe\Session;

use Carbon\Carbon;
use JsonSerializable;

/**
 * Information about a git worktree associated with a session
 */
class WorktreeInfo implements JsonSerializable
{
    public function __construct(
        public readonly string $branch,
        public readonly string $mainRepoPath,
        public readonly bool $managedByAoe,
        public readonly Carbon $createdAt,
        public readonly bool $cleanupOnDelete = true,
    ) {
    }

    /**
     * Create from array (e.g., from JSON)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            branch: $data['branch'] ?? '',
            mainRepoPath: $data['main_repo_path'] ?? $data['mainRepoPath'] ?? '',
            managedByAoe: $data['managed_by_aoe'] ?? $data['managedByAoe'] ?? false,
            createdAt: isset($data['created_at']) || isset($data['createdAt'])
                ? Carbon::parse($data['created_at'] ?? $data['createdAt'])
                : Carbon::now(),
            cleanupOnDelete: $data['cleanup_on_delete'] ?? $data['cleanupOnDelete'] ?? true,
        );
    }

    /**
     * Convert to array for JSON serialization
     */
    public function toArray(): array
    {
        return [
            'branch' => $this->branch,
            'main_repo_path' => $this->mainRepoPath,
            'managed_by_aoe' => $this->managedByAoe,
            'created_at' => $this->createdAt->toIso8601String(),
            'cleanup_on_delete' => $this->cleanupOnDelete,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
