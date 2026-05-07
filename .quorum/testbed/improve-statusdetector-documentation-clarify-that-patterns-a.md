# Testbed for Improve StatusDetector documentation: clarify that patterns are checked in priority order (running → waiting → error → idle) and that idle is the fallback when nothing else matches

Plan: `improve-statusdetector-documentation-clarify-that-patterns-a` (id `d8b17a54-d2f5-45be-975b-b6d5e3b7582e`)
Branch: `plan/improve-statusdetector-documentation-clarify-that-patterns-a`

## Acceptance criteria

_PR 4 stub. The PM agent will replace this with a real integration test._

## Subtasks covered

- **doc-priority**: Document pattern priority in StatusDetector
  - writes: src/Tmux/StatusDetector.php
  - depends_on: none

## Test commands

- `phpunit`: `vendor/bin/phpunit`
- `lint`: `vendor/bin/phpcs --standard=PSR12 src/`
