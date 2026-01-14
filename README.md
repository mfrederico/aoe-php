# AOE-PHP - AI Agent Session Manager

A PHP port of [agent-of-empires](https://github.com/njbrake/agent-of-empires) - a terminal session manager for AI coding agents (Claude Code, OpenCode) using tmux.

## Features

- **Multi-tenant support** - Isolated sessions per tenant, integrated with myctobot config
- **Session management** - Create, list, remove, and manage AI agent sessions
- **tmux integration** - Each session runs in its own tmux session
- **WebSocket server** - Real-time terminal streaming via OpenSwoole (planned)
- **CLI interface** - Full command-line interface using Symfony Console

## Requirements

- PHP 8.1+
- Composer
- tmux
- OpenSwoole extension (for WebSocket features)

## Installation

```bash
composer require myctobot/aoe-php
```

Or clone and install locally:

```bash
git clone <repository>
cd aoe-php
composer install
```

## Configuration

### Default Configuration

The default configuration is in `config/aoe.php`:

```php
return [
    'storage_path' => $_SERVER['HOME'] . '/.aoe-php',
    'default_tool' => 'claude',
    'myctobot_conf_path' => '/path/to/myctobot/conf',

    'tmux' => [
        'prefix' => 'aoe-',  // Session prefix: aoe-{tenant}-{id}
    ],

    'websocket' => [
        'host' => '127.0.0.1',
        'port' => 9502,
        'poll_interval' => 100,
    ],
];
```

### Tenant Configuration

Tenants are discovered from myctobot config files (`conf/config.{tenant}.ini`). Add an `[aoe]` section for tenant-specific overrides:

```ini
; In myctobot/conf/config.acme.ini
[aoe]
storage_path = /var/lib/aoe/acme
default_tool = claude
```

## Usage

All session commands require a tenant specified via `--tenant` flag or `AOE_TENANT` environment variable.

### List Available Tenants

```bash
bin/aoe tenant:list
```

Output:
```
+---------+----------+----------------+
| Tenant  | Sessions | Has AOE Config |
+---------+----------+----------------+
| acme    | 3        | Yes            |
| demo    | 0        | No             |
+---------+----------+----------------+
```

### Add a Session

```bash
# Add current directory
bin/aoe --tenant=acme add

# Add specific path with options
bin/aoe --tenant=acme add /path/to/project \
    --title="My Project" \
    --group="frontend/web" \
    --tool=claude
```

### List Sessions

```bash
bin/aoe --tenant=acme sessions

# Filter by group
bin/aoe --tenant=acme sessions --group="frontend"

# JSON output
bin/aoe --tenant=acme sessions --json
```

Output:
```
+----------+------------------+-----------+--------+-------+---------------------------+
| ID       | Title            | Status    | Tool   | Group | Path                      |
+----------+------------------+-----------+--------+-------+---------------------------+
| 49e8e3b0 | My Project       | ⏹ Stopped | claude | -     | /home/user/projects/myapp |
+----------+------------------+-----------+--------+-------+---------------------------+
```

### Show Status Summary

```bash
bin/aoe --tenant=acme status
```

Output:
```
Session Status for tenant: acme

By Status:
  ⚡ Running:   1
  ⏳ Waiting:   0
  💤 Idle:      2
  ⏹ Stopped:   5

By Tool:
  claude:      6
  opencode:    2

Total: 8 session(s), 1 active
```

### Remove a Session

```bash
# With confirmation
bin/aoe --tenant=acme remove abc12345

# Force (no confirmation)
bin/aoe --tenant=acme remove abc1 --force
```

### Using Environment Variable

```bash
export AOE_TENANT=acme
bin/aoe sessions
bin/aoe add /path/to/project
bin/aoe status
```

### Start a Session

```bash
# Start session (creates tmux session)
bin/aoe --tenant=acme session:start abc12345

# Override command
bin/aoe --tenant=acme session:start abc1 --command="claude --continue"

# Dry run (show what would happen)
bin/aoe --tenant=acme session:start abc1 --dry-run
```

### Stop a Session

```bash
# Stop with confirmation
bin/aoe --tenant=acme session:stop abc12345

# Force stop (no confirmation)
bin/aoe --tenant=acme session:stop abc1 --force
```

### Restart a Session

```bash
bin/aoe --tenant=acme session:restart abc12345
```

### Attach to a Session

```bash
bin/aoe --tenant=acme session:attach abc12345
# Press Ctrl+B then D to detach
```

### Session Status Detail

```bash
# Show detailed status
bin/aoe --tenant=acme session:status abc12345

# Include captured pane content
bin/aoe --tenant=acme session:status abc1 --capture=30

# Update status by analyzing pane content
bin/aoe --tenant=acme session:status abc1 --update

# JSON output
bin/aoe --tenant=acme session:status abc1 --json
```

## Project Structure

```
aoe-php/
├── bin/
│   └── aoe                      # CLI entry point
├── config/
│   └── aoe.php                  # Default configuration
├── src/
│   ├── Aoe.php                  # Main facade (planned)
│   ├── Config/
│   │   └── AoeConfig.php        # Configuration loader
│   ├── Tenant/
│   │   ├── TenantContext.php    # Current tenant holder
│   │   ├── TenantResolver.php   # Resolve tenant from CLI/env
│   │   └── TenantRequiredException.php
│   ├── Session/
│   │   ├── Instance.php         # Session entity
│   │   ├── Status.php           # Status enum
│   │   ├── Storage.php          # JSON persistence
│   │   ├── WorktreeInfo.php     # Git worktree info
│   │   └── Group.php            # Session grouping
│   ├── Tmux/                    # (Phase 2 - Complete)
│   │   ├── TmuxService.php      # tmux wrapper (tenant-prefixed)
│   │   └── StatusDetector.php   # Detect status from output
│   ├── Git/                     # (Phase 5)
│   │   └── WorktreeService.php  # Git worktree operations
│   ├── WebSocket/               # (Phase 3-4)
│   │   ├── Server.php           # OpenSwoole WebSocket
│   │   ├── TerminalProxy.php    # Terminal streaming
│   │   ├── MessageHandler.php   # Command handling
│   │   └── ClientManager.php    # Client tracking
│   └── Cli/
│       ├── Application.php      # CLI application
│       └── Commands/
│           ├── BaseCommand.php
│           ├── TenantCommand.php
│           ├── AddCommand.php
│           ├── ListCommand.php
│           ├── RemoveCommand.php
│           ├── StatusCommand.php
│           ├── SessionStartCommand.php
│           ├── SessionStopCommand.php
│           ├── SessionRestartCommand.php
│           ├── SessionAttachCommand.php
│           └── SessionStatusCommand.php
└── tests/
```

## Data Storage

Sessions are stored in JSON files, isolated per tenant:

```
~/.aoe-php/
└── tenants/
    ├── acme/
    │   ├── sessions.json
    │   └── groups.json
    └── demo/
        ├── sessions.json
        └── groups.json
```

## Implementation Status

### Phase 1: Core Session Management ✅
- [x] Project structure and composer.json
- [x] Multi-tenancy support (TenantContext, TenantResolver)
- [x] Configuration with myctobot INI integration
- [x] Session entities (Instance, Status, WorktreeInfo, Group)
- [x] JSON storage (tenant-scoped)
- [x] CLI commands: `tenant:list`, `add`, `sessions`, `remove`, `status`

### Phase 2: Tmux Integration ✅
- [x] TmuxService wrapper (tenant-prefixed sessions)
- [x] StatusDetector (analyze pane content for Running/Waiting/Idle/Error)
- [x] CLI commands: `session:start`, `session:stop`, `session:restart`, `session:attach`, `session:status`
- [x] Instance class integration: `start()`, `stop()`, `restart()`, `captureOutput()`, `sendKeys()`, `refreshStatus()`

### Phase 3: WebSocket Server (Planned)
- [ ] OpenSwoole WebSocket server
- [ ] ClientManager (tenant-scoped connections)
- [ ] MessageHandler with tenant authentication
- [ ] Commands: `server:start`, `server:stop`

### Phase 4: Terminal Proxy (Planned)
- [ ] TerminalProxy with polling loop
- [ ] Output diffing
- [ ] ANSI handling
- [ ] Real-time streaming to WebSocket clients

### Phase 5: Advanced Features (Planned)
- [ ] Git worktree support
- [ ] Claude session ID detection
- [ ] Session forking
- [ ] Group management commands

## Integration with myctobot

As a Composer package:

```json
{
    "require": {
        "myctobot/aoe-php": "^1.0"
    }
}
```

Usage in controllers:

```php
use Aoe\Session\Storage;
use Aoe\Tenant\TenantContext;
use Aoe\Config\AoeConfig;

// Set tenant from session
$tenantSlug = $_SESSION['tenant_slug'];
TenantContext::set($tenantSlug);

// Load config
$config = AoeConfig::createDefault();
$tenantConfig = $config->forTenant($tenantSlug);

// Get sessions
$storage = new Storage($tenantSlug);
$sessions = $storage->loadAll();
```

## WebSocket Protocol (Planned)

```json
// Client -> Server
{"action": "auth", "tenant": "acme", "token": "optional"}
{"action": "subscribe", "sessionId": "abc123"}
{"action": "command", "sessionId": "abc123", "command": "start"}
{"action": "sendKeys", "sessionId": "abc123", "keys": "y\n"}

// Server -> Client
{"type": "auth", "success": true, "tenant": "acme"}
{"type": "output", "sessionId": "abc123", "data": "terminal output..."}
{"type": "status", "sessionId": "abc123", "status": "running"}
```

## License

MIT
