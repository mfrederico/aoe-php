<?php

declare(strict_types=1);

namespace Aoe\Session;

use JsonSerializable;

/**
 * Represents a hierarchical group for organizing sessions
 *
 * Groups use path-like notation (e.g., "frontend/web", "backend/api")
 * to create a tree structure.
 */
class Group implements JsonSerializable
{
    /** @var Group[] */
    private array $children = [];

    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public bool $collapsed = false,
    ) {
    }

    /**
     * Create from array (e.g., from JSON)
     */
    public static function fromArray(array $data): self
    {
        $group = new self(
            name: $data['name'] ?? '',
            path: $data['path'] ?? '',
            collapsed: $data['collapsed'] ?? false,
        );

        foreach ($data['children'] ?? [] as $childData) {
            $group->children[] = self::fromArray($childData);
        }

        return $group;
    }

    /**
     * Get child groups
     *
     * @return Group[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Add a child group
     */
    public function addChild(Group $child): void
    {
        $this->children[] = $child;
    }

    /**
     * Find a child by name
     */
    public function findChild(string $name): ?Group
    {
        foreach ($this->children as $child) {
            if ($child->name === $name) {
                return $child;
            }
        }
        return null;
    }

    /**
     * Get the parent path
     */
    public function getParentPath(): string
    {
        $lastSlash = strrpos($this->path, '/');
        if ($lastSlash === false) {
            return '';
        }
        return substr($this->path, 0, $lastSlash);
    }

    /**
     * Get the depth level (0 = root)
     */
    public function getDepth(): int
    {
        if ($this->path === '') {
            return 0;
        }
        return substr_count($this->path, '/') + 1;
    }

    /**
     * Convert to array for JSON serialization
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'collapsed' => $this->collapsed,
            'children' => array_map(fn(Group $g) => $g->toArray(), $this->children),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

/**
 * Manages a tree of groups
 */
class GroupTree
{
    /** @var Group[] */
    private array $roots = [];

    /** @var array<string, Group> */
    private array $groupsByPath = [];

    /**
     * Create from array (e.g., from JSON)
     */
    public static function fromArray(array $data): self
    {
        $tree = new self();

        foreach ($data['roots'] ?? $data as $groupData) {
            $group = Group::fromArray($groupData);
            $tree->roots[] = $group;
            $tree->indexGroup($group);
        }

        return $tree;
    }

    /**
     * Build a tree from a list of sessions
     *
     * @param Instance[] $sessions
     */
    public static function fromSessions(array $sessions): self
    {
        $tree = new self();

        foreach ($sessions as $session) {
            if ($session->groupPath !== '') {
                $tree->ensurePath($session->groupPath);
            }
        }

        return $tree;
    }

    /**
     * Get root groups
     *
     * @return Group[]
     */
    public function getRoots(): array
    {
        return $this->roots;
    }

    /**
     * Find a group by path
     */
    public function find(string $path): ?Group
    {
        return $this->groupsByPath[$path] ?? null;
    }

    /**
     * Ensure a path exists, creating groups as needed
     */
    public function ensurePath(string $path): Group
    {
        if (isset($this->groupsByPath[$path])) {
            return $this->groupsByPath[$path];
        }

        $parts = explode('/', $path);
        $currentPath = '';
        $parent = null;

        foreach ($parts as $part) {
            $currentPath = $currentPath === '' ? $part : "{$currentPath}/{$part}";

            if (!isset($this->groupsByPath[$currentPath])) {
                $group = new Group($part, $currentPath);
                $this->groupsByPath[$currentPath] = $group;

                if ($parent !== null) {
                    $parent->addChild($group);
                } else {
                    $this->roots[] = $group;
                }
            }

            $parent = $this->groupsByPath[$currentPath];
        }

        return $this->groupsByPath[$path];
    }

    /**
     * Get all group paths
     *
     * @return string[]
     */
    public function getAllPaths(): array
    {
        return array_keys($this->groupsByPath);
    }

    /**
     * Convert to array for JSON serialization
     */
    public function toArray(): array
    {
        return [
            'roots' => array_map(fn(Group $g) => $g->toArray(), $this->roots),
        ];
    }

    /**
     * Index a group and its children
     */
    private function indexGroup(Group $group): void
    {
        $this->groupsByPath[$group->path] = $group;
        foreach ($group->getChildren() as $child) {
            $this->indexGroup($child);
        }
    }
}
