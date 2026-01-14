<?php

declare(strict_types=1);

namespace Aoe\Session;

/**
 * Session status enum
 *
 * Represents the current state of an AI agent session
 */
enum Status: string
{
    case Running = 'running';
    case Waiting = 'waiting';
    case Idle = 'idle';
    case Error = 'error';
    case Starting = 'starting';
    case Stopped = 'stopped';

    /**
     * Get a human-readable label for the status
     */
    public function label(): string
    {
        return match($this) {
            self::Running => 'Running',
            self::Waiting => 'Waiting',
            self::Idle => 'Idle',
            self::Error => 'Error',
            self::Starting => 'Starting',
            self::Stopped => 'Stopped',
        };
    }

    /**
     * Get an emoji/icon representation of the status
     */
    public function icon(): string
    {
        return match($this) {
            self::Running => '⚡',
            self::Waiting => '⏳',
            self::Idle => '💤',
            self::Error => '❌',
            self::Starting => '🚀',
            self::Stopped => '⏹',
        };
    }

    /**
     * Check if the session is considered active
     */
    public function isActive(): bool
    {
        return match($this) {
            self::Running, self::Waiting, self::Starting => true,
            self::Idle, self::Error, self::Stopped => false,
        };
    }

    /**
     * Create from string value, defaulting to Stopped if unknown
     */
    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower($value)) ?? self::Stopped;
    }
}
