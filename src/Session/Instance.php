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
 * Each instance is associated with a tenant and maps to a tmux session.
 */
class Instance implements JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
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
        string $tenantId,
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
            tenantId: $tenantId,
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
            tenantId: $data['tenant_id'] ?? $data['tenantId'] ?? '',
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
     * Format: aoe-{tenantId}-{reference}-{shortId} (if reference set)
     *         aoe-{tenantId}-{id} (if no reference)
     */
    public function getTmuxName(): string
    {
        if ($this->reference) {
            $safeRef = $this->sanitizeReference($this->reference);
            return "aoe-{$this->tenantId}-{$safeRef}-{$this->getShortId()}";
        }
        return "aoe-{$this->tenantId}-{$this->id}";
    }

    /**
     * Sanitize a reference string for use in tmux session name
     *
     * Converts special characters to hyphens:
     * - SSI-1883 -> SSI-1883
     * - owner/repo#123 -> owner-repo-123
     */
    private function sanitizeReference(string $ref): string
    {
        // Replace non-alphanumeric chars (except hyphen) with hyphen
        $sanitized = preg_replace('/[^a-zA-Z0-9-]/', '-', $ref);
        // Remove consecutive hyphens
        $sanitized = preg_replace('/-+/', '-', $sanitized);
        // Trim hyphens from ends
        return trim($sanitized, '-');
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
        $tmux = new TmuxService($this->tenantId);
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
        $tmux = new TmuxService($this->tenantId);

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
        $tmux = new TmuxService($this->tenantId);

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
        $tmux = new TmuxService($this->tenantId);

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
        $tmux = new TmuxService($this->tenantId);

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
        $tmux = new TmuxService($this->tenantId);

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
            'tenant_id' => $this->tenantId,
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
