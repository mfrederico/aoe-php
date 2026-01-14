<?php

declare(strict_types=1);

/**
 * Default AOE configuration
 *
 * Tenant-specific settings can be added to myctobot's config files:
 * myctobot/conf/config.{tenant}.ini under [aoe] section
 */
return [
    // Base storage path (tenants stored in subdirs)
    'storage_path' => $_SERVER['HOME'] . '/.aoe-php',

    // Default tool when not specified
    'default_tool' => 'claude',

    // myctobot config path (to read tenant INI files)
    'myctobot_conf_path' => dirname(__DIR__, 2) . '/myctobot/conf',

    'tmux' => [
        'prefix' => 'aoe-',  // Session name prefix: aoe-{tenant}-{id}
    ],

    'websocket' => [
        'host' => '127.0.0.1',
        'port' => 9502,
        'poll_interval' => 100,  // ms
        'output_buffer_lines' => 100,
    ],

    'status_patterns' => [
        'running' => ['/[⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏]/', '/Thinking/'],
        'waiting' => ['/Waiting for Claude/', '/permission/i'],
    ],

    'claude' => [
        'config_dir' => $_SERVER['HOME'] . '/.claude',
    ],
];
