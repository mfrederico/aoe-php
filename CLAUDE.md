# AOE-PHP Development Guide

This is the PHP port of agent-of-empires, a terminal session manager for AI coding agents.
It integrates with myctobot for multi-tenant AI Developer session management.

## Project Purpose

AOE-PHP manages AI agent sessions (Claude Code, OpenCode) running in tmux. It provides:
- **Session lifecycle** - Create, start, stop, restart, remove sessions
- **Multi-tenancy** - Isolated sessions per tenant (aligned with myctobot tenants)
- **Status detection** - Detect if agent is running, waiting, idle, etc.
- **WebSocket streaming** - Real-time terminal output to web clients (planned)

## Quick Reference

### CLI Commands

```bash
# All session commands require --tenant or AOE_TENANT env var
bin/aoe tenant:list                          # List available tenants
bin/aoe --tenant=X add /path                 # Add new session
bin/aoe --tenant=X sessions                  # List sessions
bin/aoe --tenant=X status                    # Status summary
bin/aoe --tenant=X remove <id>               # Remove session

# Tmux session management (Phase 2)
bin/aoe --tenant=X session:start <id>        # Start session (creates tmux)
bin/aoe --tenant=X session:stop <id>         # Stop session (kills tmux)
bin/aoe --tenant=X session:restart <id>      # Restart session
bin/aoe --tenant=X session:attach <id>       # Attach to tmux session
bin/aoe --tenant=X session:status <id>       # Detailed session status
```

### Using in PHP Code

```php
use Aoe\Session\Storage;
use Aoe\Session\Instance;
use Aoe\Tenant\TenantContext;
use Aoe\Config\AoeConfig;

// Set tenant context (required before any operations)
TenantContext::set('gwt');

// Load sessions for tenant
$storage = new Storage('gwt');
$sessions = $storage->loadAll();

// Create a new session
$instance = Instance::create(
    tenantId: 'gwt',
    title: 'My Project',
    projectPath: '/path/to/project',
    tool: 'claude'
);
$storage->save($instance);

// Find session by ID or prefix
$session = $storage->find('abc12345');
$session = $storage->findByPrefix('abc1');
```

## Architecture

```
src/
├── Config/AoeConfig.php      # Config with myctobot INI integration
├── Tenant/
│   ├── TenantContext.php     # Current tenant holder (static)
│   ├── TenantResolver.php    # Resolve from CLI/env
│   └── TenantRequiredException.php
├── Session/
│   ├── Instance.php          # Core session entity
│   ├── Status.php            # Status enum (Running, Waiting, Idle, etc.)
│   ├── Storage.php           # JSON persistence (~/.aoe-php/tenants/{id}/)
│   ├── WorktreeInfo.php      # Git worktree tracking
│   └── Group.php             # Session grouping
├── Tmux/                     # Phase 2 (COMPLETE)
│   ├── TmuxService.php       # tmux command wrapper (tenant-prefixed)
│   └── StatusDetector.php    # Detect status from pane content
├── WebSocket/                # Phase 3-4 (TO BE IMPLEMENTED)
│   ├── Server.php            # OpenSwoole WebSocket
│   └── TerminalProxy.php     # Stream tmux output
└── Cli/Commands/             # CLI commands
```

## Key Classes

### Instance (Session Entity)

```php
class Instance {
    public string $id;           // 16-char UUID
    public string $tenantId;     // Tenant this belongs to
    public string $title;
    public string $projectPath;
    public string $groupPath;    // e.g., "frontend/web"
    public string $command;      // Custom command (optional)
    public string $tool;         // 'claude' or 'opencode'
    public Status $status;
    public Carbon $createdAt;
    public ?Carbon $lastAccessedAt;
    public ?WorktreeInfo $worktreeInfo;

    // Get tmux session name: aoe-{tenant}-{id}
    public function getTmuxName(): string;

    // Tmux integration (Phase 2)
    public function isTmuxRunning(): bool;
    public function start(?string $customCommand = null): bool;
    public function stop(): bool;
    public function restart(?string $customCommand = null): bool;
    public function captureOutput(int $lines = 50): ?string;
    public function sendKeys(string $keys): bool;
    public function refreshStatus(): Status;
}
```

### Status Enum

```php
enum Status: string {
    case Running = 'running';   // Agent actively processing
    case Waiting = 'waiting';   // Waiting for user input/permission
    case Idle = 'idle';         // Session exists but no activity
    case Error = 'error';       // Error state
    case Starting = 'starting'; // Session starting up
    case Stopped = 'stopped';   // No tmux session running

    public function isActive(): bool;  // Running, Waiting, Starting
    public function icon(): string;    // Emoji for display
}
```

### Storage

```php
class Storage {
    // Storage path: ~/.aoe-php/tenants/{tenant}/sessions.json

    public function loadAll(): array;           // Get all sessions
    public function find(string $id): ?Instance;
    public function findByPrefix(string $prefix): ?Instance;
    public function findByGroup(string $group): array;
    public function save(Instance $instance): void;
    public function delete(string $id): bool;
    public function count(): int;
}
```

## Multi-Tenancy

Tenants are discovered from myctobot config files:

```
myctobot/conf/
├── config.gwt.ini
├── config.testcorp.ini
└── config.example.ini
```

Add `[aoe]` section for tenant-specific AOE settings:

```ini
; In myctobot/conf/config.gwt.ini
[aoe]
storage_path = /var/lib/aoe/gwt
default_tool = claude
```

### Tenant Resolution Priority

1. `--tenant=X` CLI argument
2. `AOE_TENANT` environment variable
3. Throws `TenantRequiredException`

## Integration with myctobot

### Relationship to Existing Tmux Code

myctobot has existing tmux management:
- `lib/TmuxManager.php` - Low-level tmux operations (static methods)
- `services/TmuxService.php` - AI Developer session management

AOE-PHP is designed to **complement** these, providing:
- **Persistent session registry** - Sessions tracked in JSON, survive restarts
- **Multi-tenant isolation** - tmux sessions prefixed with tenant ID
- **Web dashboard ready** - WebSocket streaming for browser-based monitoring

### Session Name Conventions

| System | Pattern | Example |
|--------|---------|---------|
| myctobot TmuxService | `aidev-{domain}-{member}-{issue}` | `aidev-gwt-3-PROJ-123` |
| AOE-PHP | `aoe-{tenant}-{session_id}` | `aoe-gwt-49e8e3b01f97` |

### Using AOE-PHP from myctobot

```php
// In a myctobot controller or service
use Aoe\Session\Storage;
use Aoe\Session\Instance;
use Aoe\Tenant\TenantContext;

class AiDevController extends Control {

    public function sessions() {
        $tenantSlug = $_SESSION['tenant_slug'] ?? 'default';
        TenantContext::set($tenantSlug);

        $storage = new Storage($tenantSlug);
        $sessions = $storage->loadAll();

        $this->render('aidev/sessions', ['sessions' => $sessions]);
    }

    public function createSession() {
        $tenantSlug = $_SESSION['tenant_slug'] ?? 'default';
        TenantContext::set($tenantSlug);

        $instance = Instance::create(
            tenantId: $tenantSlug,
            title: $this->getParam('title'),
            projectPath: $this->getParam('path'),
            tool: $this->getParam('tool', 'claude')
        );

        $storage = new Storage($tenantSlug);
        $storage->save($instance);

        Flight::jsonSuccess(['id' => $instance->id]);
    }
}
```

### Migrating from TmuxService

AOE-PHP can work alongside existing TmuxService. For new features:

```php
// OLD: Using TmuxService directly
$tmux = new TmuxService($memberId, $issueKey);
$tmux->spawn($prompt);

// NEW: Using AOE-PHP with persistent tracking
$storage = new Storage($tenantSlug);
$instance = Instance::create($tenantSlug, $title, $projectPath, 'claude');
$storage->save($instance);

// Start the tmux session
$instance->start();  // Creates aoe-{tenant}-{id} tmux session
$storage->save($instance);  // Persist updated status

// Or use TmuxService directly for more control
use Aoe\Tmux\TmuxService;

$tmux = new TmuxService($tenantSlug);
$tmux->createSession($instance->id, $projectPath, 'claude');
$tmux->sendKeys($instance->id, "Hello\n");
$output = $tmux->capturePane($instance->id, 50);
```

## Data Storage

```
~/.aoe-php/
└── tenants/
    ├── gwt/
    │   ├── sessions.json      # Session registry
    │   └── sessions.json.bak  # Backup
    └── testcorp/
        └── sessions.json
```

### sessions.json Format

```json
{
    "version": 1,
    "sessions": [
        {
            "id": "49e8e3b01f9740bd",
            "tenant_id": "gwt",
            "title": "MyCTOBot Project",
            "project_path": "/home/user/myctobot",
            "group_path": "",
            "command": "",
            "tool": "claude",
            "status": "stopped",
            "created_at": "2026-01-13T19:36:01+00:00",
            "last_accessed_at": null,
            "claude_session_id": null,
            "worktree_info": null
        }
    ]
}
```

## Implementation Status

### Phase 1: Core (COMPLETE)
- [x] Multi-tenancy (TenantContext, TenantResolver)
- [x] Configuration (AoeConfig with myctobot INI)
- [x] Session entities (Instance, Status, WorktreeInfo, Group)
- [x] JSON storage (tenant-scoped)
- [x] CLI: tenant:list, add, sessions, remove, status

### Phase 2: Tmux Integration (COMPLETE)
- [x] `Aoe\Tmux\TmuxService` - Wrap tmux commands (tenant-prefixed)
- [x] `Aoe\Tmux\StatusDetector` - Analyze pane content
- [x] CLI: session:start, session:stop, session:restart, session:attach, session:status
- [x] Instance class tmux methods: start(), stop(), restart(), captureOutput(), sendKeys(), refreshStatus()

### Phase 3-4: WebSocket (TODO)
- [ ] OpenSwoole WebSocket server
- [ ] Tenant authentication
- [ ] Real-time terminal streaming
- [ ] CLI: server:start, server:stop

## Development

```bash
# Install dependencies
composer install

# Run CLI
bin/aoe --help
bin/aoe tenant:list

# Test with a tenant
bin/aoe --tenant=gwt add /path/to/project --title="Test"
bin/aoe --tenant=gwt sessions
bin/aoe --tenant=gwt remove <id> --force
```

## See Also

- `README.md` - Full documentation
- `myctobot/lib/TmuxManager.php` - Low-level tmux operations
- `myctobot/services/TmuxService.php` - AI Developer session service
- `myctobot/CLAUDE.md` - myctobot development standards
