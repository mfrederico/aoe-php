<?php

declare(strict_types=1);

namespace Aoe\Session;

use Aoe\Tmux\StatusDetector;
use Aoe\Tmux\TmuxService;
use Carbon\Carbon;
use JsonSerializable;
use Ramsey\Uuid\Uuid;

/**
 * Represents an AI agent session instance
 *
 * This is the core entity that tracks a running or configured AI coding agent session.
 * Each instance is associated with a workspace and maps to a tmux session.
 */
class Instance implements JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $workspaceId,
        public string $title,
        public string $projectPath,
        public string $groupPath,
        public string $command,
        public string $tool,
        public Status $status,
        public readonly Carbon $createdAt,
        public ?Carbon $lastAccessedAt = null,
        public ?string $claudeSessionId = null,
        public ?WorktreeInfo $worktreeInfo = null,
        public ?string $reference = null,  // Ticket/issue reference (visible in tmux name)
    ) {
    }

    /**
     * Create a new instance with a generated ID
     */
    public static function create(
        string $workspaceId,
        string $title,
        string $projectPath,
        string $tool = 'claude',
        string $groupPath = '',
        string $command = '',
        ?string $reference = null,
    ): self {
        // Generate a 16-character ID (similar to Rust version)
        $uuid = Uuid::uuid4()->toString();
        $id = substr(str_replace('-', '', $uuid), 0, 16);

        return new self(
            id: $id,
            workspaceId: $workspaceId,
            title: $title,
            projectPath: $projectPath,
            groupPath: $groupPath,
            command: $command,
            tool: $tool,
            status: Status::Stopped,
            createdAt: Carbon::now(),
            reference: $reference,
        );
    }

    /**
     * Create from array (e.g., from JSON storage)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            workspaceId: $data['workspace_id'] ?? $data['workspaceId'] ?? '',
            title: $data['title'] ?? '',
            projectPath: $data['project_path'] ?? $data['projectPath'] ?? '',
            groupPath: $data['group_path'] ?? $data['groupPath'] ?? '',
            command: $data['command'] ?? '',
            tool: $data['tool'] ?? 'claude',
            status: Status::fromString($data['status'] ?? 'stopped'),
            createdAt: isset($data['created_at']) || isset($data['createdAt'])
                ? Carbon::parse($data['created_at'] ?? $data['createdAt'])
                : Carbon::now(),
            lastAccessedAt: isset($data['last_accessed_at']) || isset($data['lastAccessedAt'])
                ? Carbon::parse($data['last_accessed_at'] ?? $data['lastAccessedAt'])
                : null,
            claudeSessionId: $data['claude_session_id'] ?? $data['claudeSessionId'] ?? null,
            worktreeInfo: isset($data['worktree_info']) || isset($data['worktreeInfo'])
                ? WorktreeInfo::fromArray($data['worktree_info'] ?? $data['worktreeInfo'])
                : null,
            reference: $data['reference'] ?? null,
        );
    }

    /**
     * Get the tmux session name for this instance
     *
     * Format: aoe-{workspaceId}-{reference}-{shortId} (if reference set)
     *         aoe-{workspaceId}-{id} (if no reference)
     */
    public function getTmuxName(): string
    {
        if ($this->reference) {
            $safeRef = $this->sanitizeReference($this->reference);
            return "aoe-{$this->workspaceId}-{$safeRef}-{$this->getShortId()}";
        }
        return "aoe-{$this->workspaceId}-{$this->id}";
    }

    /**
     * Sanitize a reference string for use in tmux session name
     *
     * Delegates to TmuxService::sanitize() for consistency.
     * - SSI-1883 -> SSI-1883
     * - owner/repo#123 -> owner-repo-123
     * - agent_tmp -> agent_tmp (underscores preserved)
     */
    private function sanitizeReference(string $ref): string
    {
        return TmuxService::sanitize($ref);
    }

    /**
     * Get the command to run in the session
     *
     * If no custom command is set, returns the default tool command
     */
    public function getEffectiveCommand(): string
    {
        if ($this->command !== '') {
            return $this->command;
        }

        return match ($this->tool) {
            'claude' => 'claude',
            'opencode' => 'opencode',
            default => $this->tool,
        };
    }

    /**
     * Update the last accessed timestamp
     */
    public function touch(): void
    {
        $this->lastAccessedAt = Carbon::now();
    }

    /**
     * Alias for touch() - update last accessed timestamp
     */
    public function updateLastAccessed(): void
    {
        $this->touch();
    }

    /**
     * Check if the session has a worktree
     */
    public function hasWorktree(): bool
    {
        return $this->worktreeInfo !== null;
    }

    /**
     * Get a short display ID
     */
    public function getShortId(): string
    {
        return substr($this->id, 0, 8);
    }

    /**
     * Check if this session's tmux session is running
     */
    public function isTmuxRunning(): bool
    {
        $tmux = new TmuxService($this->workspaceId);
        return $tmux->sessionExists($this->id);
    }

    /**
     * Start this session's tmux session
     *
     * @param string|null $customCommand Override the default command
     * @return bool Success
     */
    public function start(?string $customCommand = null): bool
    {
        $tmux = new TmuxService($this->workspaceId);

        // Don't start if already running
        if ($tmux->sessionExists($this->id)) {
            return true;
        }

        $command = $customCommand ?? $this->getEffectiveCommand();
        $success = $tmux->createSession($this->id, $this->projectPath, $command);

        if ($success) {
            $this->status = Status::Starting;
            $this->touch();
        }

        return $success;
    }

    /**
     * Stop this session's tmux session
     *
     * @return bool Success
     */
    public function stop(): bool
    {
        $tmux = new TmuxService($this->workspaceId);

        // Already stopped
        if (!$tmux->sessionExists($this->id)) {
            $this->status = Status::Stopped;
            return true;
        }

        $success = $tmux->killSession($this->id);

        if ($success) {
            $this->status = Status::Stopped;
            $this->touch();
        }

        return $success;
    }

    /**
     * Restart this session's tmux session
     *
     * @param string|null $customCommand Override the default command
     * @return bool Success
     */
    public function restart(?string $customCommand = null): bool
    {
        $this->stop();
        return $this->start($customCommand);
    }

    /**
     * Capture the pane content from the tmux session
     *
     * @param int $lines Number of lines to capture
     * @return string|null Captured content, or null if not running
     */
    public function captureOutput(int $lines = 50): ?string
    {
        $tmux = new TmuxService($this->workspaceId);

        if (!$tmux->sessionExists($this->id)) {
            return null;
        }

        return $tmux->capturePane($this->id, $lines);
    }

    /**
     * Send keys to the tmux session
     *
     * @param string $keys Keys to send
     * @return bool Success
     */
    public function sendKeys(string $keys): bool
    {
        $tmux = new TmuxService($this->workspaceId);

        if (!$tmux->sessionExists($this->id)) {
            return false;
        }

        return $tmux->sendKeys($this->id, $keys);
    }

    /**
     * Update status by analyzing pane content
     *
     * @return Status The detected status
     */
    public function refreshStatus(): Status
    {
        $tmux = new TmuxService($this->workspaceId);

        // If no tmux session, it's stopped
        if (!$tmux->sessionExists($this->id)) {
            $this->status = Status::Stopped;
            return $this->status;
        }

        // Capture and analyze pane content
        $content = $tmux->capturePane($this->id, 20);
        $detector = new StatusDetector();
        $this->status = $detector->detect($content);

        return $this->status;
    }

    /**
     * Convert to array for JSON serialization
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspaceId,
            'title' => $this->title,
            'project_path' => $this->projectPath,
            'group_path' => $this->groupPath,
            'command' => $this->command,
            'tool' => $this->tool,
            'status' => $this->status->value,
            'created_at' => $this->createdAt->toIso8601String(),
            'last_accessed_at' => $this->lastAccessedAt?->toIso8601String(),
            'claude_session_id' => $this->claudeSessionId,
            'worktree_info' => $this->worktreeInfo?->toArray(),
            'reference' => $this->reference,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
