<?php

declare(strict_types=1);

namespace Aoe\Tmux;

use Aoe\Tenant\TenantContext;

/**
 * TmuxService - Wrapper for tmux CLI commands
 *
 * All session names are prefixed with tenant ID: aoe-{tenant}-{session_id}
 * This ensures isolation between tenants and avoids collisions with
 * myctobot's existing aidev-{domain}-{member}-{issue} sessions.
 */
class TmuxService
{
    private string $tenantId;
    private string $prefix;

    public function __construct(?string $tenantId = null, string $prefix = 'aoe')
    {
        $this->tenantId = $tenantId ?? TenantContext::get();
        $this->prefix = $prefix;
    }

    /**
     * Build full tmux session name: aoe-{tenant}-{sessionId}
     */
    public function buildSessionName(string $sessionId): string
    {
        return sprintf('%s-%s-%s', $this->prefix, $this->tenantId, $sessionId);
    }

    /**
     * Check if a tmux session exists
     */
    public function sessionExists(string $sessionId): bool
    {
        $name = $this->buildSessionName($sessionId);
        $cmd = sprintf('tmux has-session -t %s 2>/dev/null', escapeshellarg($name));
        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Create a new tmux session
     *
     * @param string $sessionId The AOE session ID
     * @param string $cwd Working directory
     * @param string $command Command to run in the session
     * @return bool Success
     */
    public function createSession(string $sessionId, string $cwd, string $command = ''): bool
    {
        $name = $this->buildSessionName($sessionId);
        return $this->createSessionWithName($name, $cwd, $command);
    }

    /**
     * Create a new tmux session with a custom name
     *
     * Use this when you need a specific session name (e.g., with ticket reference)
     *
     * @param string $name The exact tmux session name to use
     * @param string $cwd Working directory
     * @param string $command Command to run in the session
     * @return bool Success
     */
    public function createSessionWithName(string $name, string $cwd, string $command = ''): bool
    {
        // Kill existing session if present
        if ($this->sessionExistsByName($name)) {
            $this->killSessionByName($name);
        }

        // Build tmux new-session command
        $cmd = sprintf(
            'tmux new-session -d -s %s -c %s',
            escapeshellarg($name),
            escapeshellarg($cwd)
        );

        if (!empty($command)) {
            // Send the command after session creation
            $cmd .= sprintf(' %s', escapeshellarg($command));
        }

        exec($cmd, $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Check if a tmux session exists by exact name
     */
    public function sessionExistsByName(string $name): bool
    {
        $cmd = sprintf('tmux has-session -t %s 2>/dev/null', escapeshellarg($name));
        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Kill a tmux session by exact name
     */
    public function killSessionByName(string $name): bool
    {
        $cmd = sprintf('tmux kill-session -t %s 2>/dev/null', escapeshellarg($name));
        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Kill a tmux session
     */
    public function killSession(string $sessionId): bool
    {
        $name = $this->buildSessionName($sessionId);
        $cmd = sprintf('tmux kill-session -t %s 2>/dev/null', escapeshellarg($name));
        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * List all tmux sessions for this tenant
     *
     * @return array<string, array{name: string, created: string, attached: bool}>
     */
    public function listSessions(): array
    {
        $prefix = sprintf('%s-%s-', $this->prefix, $this->tenantId);
        $cmd = "tmux list-sessions -F '#{session_name}|#{session_created}|#{session_attached}' 2>/dev/null";
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return [];
        }

        $sessions = [];
        foreach ($output as $line) {
            $parts = explode('|', $line);
            if (count($parts) !== 3) {
                continue;
            }

            [$name, $created, $attached] = $parts;

            // Only include sessions for this tenant
            if (!str_starts_with($name, $prefix)) {
                continue;
            }

            // Extract session ID from name
            $sessionId = substr($name, strlen($prefix));

            $sessions[$sessionId] = [
                'name' => $name,
                'created' => date('Y-m-d H:i:s', (int) $created),
                'attached' => $attached === '1',
            ];
        }

        return $sessions;
    }

    /**
     * Capture pane content
     *
     * @param string $sessionId Session ID
     * @param int $lines Number of lines to capture (0 = all visible)
     * @param bool $includeHistory Include scrollback history
     * @return string Captured content
     */
    public function capturePane(string $sessionId, int $lines = 50, bool $includeHistory = false): string
    {
        $name = $this->buildSessionName($sessionId);
        return $this->capturePaneByName($name, $lines, $includeHistory);
    }

    /**
     * Capture pane content by exact session name
     */
    public function capturePaneByName(string $name, int $lines = 50, bool $includeHistory = false): string
    {
        if ($includeHistory) {
            // Capture entire scrollback buffer
            $cmd = sprintf(
                'tmux capture-pane -t %s -p -S - -E - 2>/dev/null',
                escapeshellarg($name)
            );
        } elseif ($lines > 0) {
            // Capture last N lines
            $cmd = sprintf(
                'tmux capture-pane -t %s -p -S -%d 2>/dev/null',
                escapeshellarg($name),
                $lines
            );
        } else {
            // Capture visible pane only
            $cmd = sprintf(
                'tmux capture-pane -t %s -p 2>/dev/null',
                escapeshellarg($name)
            );
        }

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return '';
        }

        return implode("\n", $output);
    }

    /**
     * Send keys to a tmux session
     *
     * @param string $sessionId Session ID
     * @param string $keys Keys to send (use \n for Enter)
     * @return bool Success
     */
    public function sendKeys(string $sessionId, string $keys): bool
    {
        $name = $this->buildSessionName($sessionId);
        return $this->sendKeysByName($name, $keys);
    }

    /**
     * Send keys to a tmux session by exact name
     */
    public function sendKeysByName(string $name, string $keys): bool
    {
        // Handle special keys
        $keys = str_replace("\n", ' Enter', $keys);
        $keys = str_replace("\t", ' Tab', $keys);

        $cmd = sprintf(
            'tmux send-keys -t %s %s 2>/dev/null',
            escapeshellarg($name),
            escapeshellarg($keys)
        );

        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Send literal text (not interpreted as key names)
     */
    public function sendText(string $sessionId, string $text): bool
    {
        $name = $this->buildSessionName($sessionId);
        return $this->sendTextByName($name, $text);
    }

    /**
     * Send literal text by exact session name
     */
    public function sendTextByName(string $name, string $text): bool
    {
        $cmd = sprintf(
            'tmux send-keys -t %s -l %s 2>/dev/null',
            escapeshellarg($name),
            escapeshellarg($text)
        );

        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Send Enter key
     */
    public function sendEnter(string $sessionId): bool
    {
        $name = $this->buildSessionName($sessionId);
        return $this->sendEnterByName($name);
    }

    /**
     * Send Enter key by exact session name
     */
    public function sendEnterByName(string $name): bool
    {
        $cmd = sprintf('tmux send-keys -t %s Enter 2>/dev/null', escapeshellarg($name));
        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Send Ctrl+C to interrupt
     */
    public function sendInterrupt(string $sessionId): bool
    {
        $name = $this->buildSessionName($sessionId);
        $cmd = sprintf('tmux send-keys -t %s C-c 2>/dev/null', escapeshellarg($name));
        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Get pane dimensions
     *
     * @return array{width: int, height: int}|null
     */
    public function getPaneDimensions(string $sessionId): ?array
    {
        $name = $this->buildSessionName($sessionId);
        return $this->getPaneDimensionsByName($name);
    }

    /**
     * Get pane dimensions by exact session name
     */
    public function getPaneDimensionsByName(string $name): ?array
    {
        $cmd = sprintf(
            "tmux display-message -t %s -p '#{pane_width}|#{pane_height}' 2>/dev/null",
            escapeshellarg($name)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        $parts = explode('|', $output[0]);
        if (count($parts) !== 2) {
            return null;
        }

        return [
            'width' => (int) $parts[0],
            'height' => (int) $parts[1],
        ];
    }

    /**
     * Resize pane
     */
    public function resizePane(string $sessionId, int $width, int $height): bool
    {
        $name = $this->buildSessionName($sessionId);
        return $this->resizePaneByName($name, $width, $height);
    }

    /**
     * Resize pane by exact session name
     */
    public function resizePaneByName(string $name, int $width, int $height): bool
    {
        $cmd = sprintf(
            'tmux resize-pane -t %s -x %d -y %d 2>/dev/null',
            escapeshellarg($name),
            $width,
            $height
        );

        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Check if tmux is available
     */
    public static function isAvailable(): bool
    {
        exec('which tmux 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Get tmux version
     */
    public static function getVersion(): ?string
    {
        exec('tmux -V 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0 || empty($output)) {
            return null;
        }
        // Output format: "tmux 3.3a"
        return trim(str_replace('tmux ', '', $output[0]));
    }

    /**
     * Get domain ID from myctobot config URL
     *
     * Extracts tenant/domain from CONFIG_URL environment variable:
     * - footest4.myctobot.ai -> footest4
     * - myctobot.ai -> default
     * - custom domains -> as-is
     */
    public static function getDomainId(): string
    {
        $configUrl = getenv('CONFIG_URL') ?: '';

        // Try to extract subdomain from myctobot.ai URL
        if (preg_match('/^https?:\/\/([^.]+)\.myctobot\./', $configUrl, $matches)) {
            return $matches[1];
        }

        // Check for myctobot.ai without subdomain
        if (preg_match('/^https?:\/\/myctobot\.ai/', $configUrl)) {
            return 'default';
        }

        // For custom domains, try to extract first part
        if (preg_match('/^https?:\/\/([^.\/]+)/', $configUrl, $matches)) {
            return $matches[1];
        }

        return 'default';
    }

    /**
     * Sanitize a string for use in tmux session names or filesystem paths
     *
     * Replaces special characters with hyphens:
     * - SSI-1883 -> SSI-1883
     * - owner/repo#123 -> owner-repo-123
     */
    public static function sanitize(string $input): string
    {
        // Replace non-alphanumeric chars (except hyphen) with hyphen
        $sanitized = preg_replace('/[^a-zA-Z0-9-]/', '-', $input);
        // Remove consecutive hyphens
        $sanitized = preg_replace('/-+/', '-', $sanitized);
        // Trim hyphens from ends
        return trim($sanitized, '-');
    }

    /**
     * Attach to session (for CLI use)
     * This replaces the current process with tmux attach
     */
    public function attachSession(string $sessionId): void
    {
        $name = $this->buildSessionName($sessionId);
        $this->attachSessionByName($name);
    }

    /**
     * Attach to session by exact name (for CLI use)
     * This replaces the current process with tmux attach
     */
    public function attachSessionByName(string $name): void
    {
        // Use passthru to replace current process
        $cmd = sprintf('tmux attach-session -t %s', escapeshellarg($name));
        passthru($cmd);
    }

    /**
     * Get the tenant ID this service is configured for
     */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }
}
