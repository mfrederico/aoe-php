<?php

declare(strict_types=1);

namespace Aoe\Tmux;

use Aoe\Session\Status;

/**
 * StatusDetector - Analyze tmux pane content to determine session status
 *
 * Detects patterns in terminal output to determine if an AI agent is:
 * - Running (processing, thinking)
 * - Waiting (for user input, permission)
 * - Idle (shell prompt, no activity)
 * - Error (error messages)
 *
 * Pattern priority: detect() evaluates pattern categories in a fixed priority
 * order — running, then waiting, then error, then idle — and returns as soon
 * as a category matches. Running is checked first because error/waiting
 * patterns can incidentally match on code OUTPUT the agent is reviewing (test
 * failures, error logs in scrollback, etc.); a confirmed "running" signal must
 * win over those false positives. Idle is the fallback returned when no other
 * category matches.
 */
class StatusDetector
{
    /**
     * Spinner characters used by Claude Code and similar tools
     */
    private const SPINNER_CHARS = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /**
     * Patterns indicating the agent is actively processing
     */
    private array $runningPatterns = [
        '/[⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏]/',        // Braille spinner
        '/Thinking\.\.\./i',            // Claude "Thinking..."
        '/Processing\.\.\./i',          // Generic processing
        '/Analyzing/i',                 // Analysis in progress
        '/Reading files/i',             // File operations
        '/Writing to/i',                // Write operations
        '/Searching/i',                 // Search in progress
        '/⏳/',                         // Hourglass emoji
        '/🔄/',                         // Refresh emoji
        '/ctrl\+c to interrupt/i',      // Claude Code status bar (actively working)
        '/tokens\)$/m',                 // Claude Code status line ending
        '/╠═.*\.\.\./u',                // Claude Code status with ellipsis (working)
        '/Read \d+ lines/i',            // Claude read operation
        '/Found \d+/i',                 // Claude search results
        '/Edit\(/i',                    // Claude editing
        '/Write\(/i',                   // Claude writing
        '/Bash\(/i',                    // Claude bash command
    ];

    /**
     * Patterns indicating the agent is waiting for user input
     */
    private array $waitingPatterns = [
        '/Do you want to proceed\?/i',
        '/\[Y\/n\]/i',
        '/\[y\/N\]/i',
        '/Press Enter to continue/i',
        '/Waiting for user/i',          // Specific waiting pattern
        '/Allow\?/i',
        '/\bapprove\b/i',               // Word boundary to avoid false matches
        '/\bconfirm\b/i',               // Word boundary
        '/\(y\/n\)\s*$/i',              // y/n at end of line
        '/\[yes\/no\]\s*$/i',           // yes/no at end
        '/Enter your choice/i',
        '/Select an option/i',
        '/^>\s*$/m',                    // Just a > prompt on its own line
    ];

    /**
     * Patterns indicating an error state
     */
    private array $errorPatterns = [
        '/Error:/i',
        '/Exception:/i',
        '/Failed:/i',
        '/fatal error/i',
        '/panic:/i',
        '/FAILED/i',
        '/❌/',                         // X emoji
        '/🚫/',                         // Prohibited emoji
    ];

    /**
     * Patterns indicating idle/shell state
     */
    private array $idlePatterns = [
        '/\$\s*$/',                     // Bash prompt
        '/>\s*$/',                      // Generic prompt
        '/#\s*$/',                      // Root prompt
        '/❯\s*$/',                      // Starship/custom prompt
    ];

    /**
     * Detect status from pane content
     *
     * @param string $content Captured pane content
     * @param int $recentLines Number of recent lines to analyze (0 = all)
     * @return Status Detected status
     */
    public function detect(string $content, int $recentLines = 10): Status
    {
        if (empty(trim($content))) {
            return Status::Stopped;
        }

        // Focus on recent lines for more accurate detection
        if ($recentLines > 0) {
            $lines = explode("\n", $content);
            $lines = array_slice($lines, -$recentLines);
            $content = implode("\n", $lines);
        }

        // Running has highest priority — "(ctrl+c to interrupt)" is the
        // definitive Claude Code status bar indicator. Must be checked before
        // error/waiting patterns, which can match on code OUTPUT (test failures,
        // error messages the agent is reviewing, etc.)
        if (preg_match('/\(ctrl\+c to interrupt/i', $content)) {
            return Status::Running;
        }

        // Check for braille spinners (active processing)
        if ($this->hasSpinner($content)) {
            return Status::Running;
        }

        // Check for waiting state (permission prompts, y/n questions)
        if ($this->matchesAny($content, $this->waitingPatterns)) {
            return Status::Waiting;
        }

        // If no active indicators, Claude is idle (waiting for input)
        return Status::Idle;
    }

    /**
     * Check if content matches any pattern in the list
     */
    private function matchesAny(string $content, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Add custom running pattern
     */
    public function addRunningPattern(string $pattern): self
    {
        $this->runningPatterns[] = $pattern;
        return $this;
    }

    /**
     * Add custom waiting pattern
     */
    public function addWaitingPattern(string $pattern): self
    {
        $this->waitingPatterns[] = $pattern;
        return $this;
    }

    /**
     * Add custom error pattern
     */
    public function addErrorPattern(string $pattern): self
    {
        $this->errorPatterns[] = $pattern;
        return $this;
    }

    /**
     * Add custom idle pattern
     */
    public function addIdlePattern(string $pattern): self
    {
        $this->idlePatterns[] = $pattern;
        return $this;
    }

    /**
     * Get detailed detection result with matched patterns
     *
     * @param string $content Captured pane content
     * @param int $recentLines Number of recent lines to analyze
     * @return array{status: Status, matches: array<string, array>}
     */
    public function detectWithDetails(string $content, int $recentLines = 10): array
    {
        if (empty(trim($content))) {
            return [
                'status' => Status::Stopped,
                'matches' => [],
            ];
        }

        // Focus on recent lines
        if ($recentLines > 0) {
            $lines = explode("\n", $content);
            $lines = array_slice($lines, -$recentLines);
            $content = implode("\n", $lines);
        }

        $matches = [
            'error' => $this->findMatches($content, $this->errorPatterns),
            'waiting' => $this->findMatches($content, $this->waitingPatterns),
            'running' => $this->findMatches($content, $this->runningPatterns),
            'idle' => $this->findMatches($content, $this->idlePatterns),
        ];

        // Determine status based on priority — Running is highest because
        // error/waiting patterns can match on code OUTPUT the agent is reviewing
        $status = Status::Idle;
        if (!empty($matches['running'])) {
            $status = Status::Running;
        } elseif (!empty($matches['waiting'])) {
            $status = Status::Waiting;
        } elseif (!empty($matches['idle'])) {
            $status = Status::Idle;
        }

        return [
            'status' => $status,
            'matches' => $matches,
        ];
    }

    /**
     * Find all matching patterns and what they matched
     */
    private function findMatches(string $content, array $patterns): array
    {
        $found = [];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $found[] = [
                    'pattern' => $pattern,
                    'match' => $matches[0],
                ];
            }
        }
        return $found;
    }

    /**
     * Check if spinner is present (common indicator of activity)
     */
    public function hasSpinner(string $content): bool
    {
        foreach (self::SPINNER_CHARS as $char) {
            if (str_contains($content, $char)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract progress percentage if visible
     * Looks for patterns like "50%", "50/100", etc.
     *
     * @return int|null Percentage (0-100) or null if not found
     */
    public function extractProgress(string $content): ?int
    {
        // Match percentage: "50%"
        if (preg_match('/(\d{1,3})%/', $content, $matches)) {
            $percent = (int) $matches[1];
            if ($percent >= 0 && $percent <= 100) {
                return $percent;
            }
        }

        // Match fraction: "5/10" -> 50%
        if (preg_match('/(\d+)\s*\/\s*(\d+)/', $content, $matches)) {
            $current = (int) $matches[1];
            $total = (int) $matches[2];
            if ($total > 0) {
                return (int) round(($current / $total) * 100);
            }
        }

        return null;
    }

    /**
     * Create detector with patterns from config
     */
    public static function fromConfig(array $config): self
    {
        $detector = new self();

        if (isset($config['status_patterns']['running'])) {
            foreach ($config['status_patterns']['running'] as $pattern) {
                $detector->addRunningPattern($pattern);
            }
        }

        if (isset($config['status_patterns']['waiting'])) {
            foreach ($config['status_patterns']['waiting'] as $pattern) {
                $detector->addWaitingPattern($pattern);
            }
        }

        if (isset($config['status_patterns']['error'])) {
            foreach ($config['status_patterns']['error'] as $pattern) {
                $detector->addErrorPattern($pattern);
            }
        }

        if (isset($config['status_patterns']['idle'])) {
            foreach ($config['status_patterns']['idle'] as $pattern) {
                $detector->addIdlePattern($pattern);
            }
        }

        return $detector;
    }
}
