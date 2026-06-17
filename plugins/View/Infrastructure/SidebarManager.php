<?php

declare(strict_types=1);

namespace Plugins\View\Infrastructure;

/**
 * SidebarManager — navigation HTML builder.
 *
 * Ported from the legacy HKM\lib\View\SidebarManager. The only behavioural
 * change required by the platform rules: the icon cache is now an INSTANCE
 * property (was `static`), because static request-scoped state leaks between
 * requests under OpenSwoole.
 */
final class SidebarManager
{
    /** @var array<string,array{title:string,items:list<array<string,mixed>>,visible:bool,order:int,permission:?string}> */
    private array $sections = [];

    private string $currentPath;

    /** @var array<string,int> hash set for O(1) permission lookup */
    private array $permissions = [];

    /** @var array<string,string>|null icon cache (instance-scoped — Swoole safe) */
    private ?array $iconCache = null;

    private ?string $renderedCache = null;

    private bool $isDirty = true;

    /**
     * @param list<string> $permissions
     */
    public function __construct(string $currentRoute = '', array $permissions = [])
    {
        $this->currentPath = $currentRoute ? (string) parse_url($currentRoute, PHP_URL_PATH) : '';
        $this->permissions = array_flip($permissions);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function addSection(string $key, string $title, array $options = []): self
    {
        $this->sections[$key] = [
            'title'      => $title,
            'items'      => [],
            'visible'    => $options['visible'] ?? true,
            'order'      => $options['order'] ?? 100,
            'permission' => $options['permission'] ?? null,
        ];

        $this->isDirty = true;

        return $this;
    }

    /**
     * @param array<string,mixed> $item
     */
    public function addItem(string $sectionKey, array $item): self
    {
        if (! isset($this->sections[$sectionKey])) {
            $this->addSection($sectionKey, $sectionKey);
        }

        $this->sections[$sectionKey]['items'][] = [
            'id'         => $item['id'] ?? uniqid('nav_', true),
            'label'      => $item['label'],
            'url'        => $item['url'],
            'icon'       => $item['icon'] ?? null,
            'badge'      => $item['badge'] ?? null,
            'badge_type' => $item['badge_type'] ?? 'default',
            'counter'    => $item['counter'] ?? null,
            'visible'    => $item['visible'] ?? true,
            'permission' => $item['permission'] ?? null,
            'children'   => $item['children'] ?? [],
            'order'      => $item['order'] ?? 100,
        ];

        $this->isDirty = true;

        return $this;
    }

    public function updateBadge(string $itemId, mixed $badge, string $type = 'default'): self
    {
        foreach ($this->sections as &$section) {
            foreach ($section['items'] as &$item) {
                if ($item['id'] === $itemId) {
                    $item['badge'] = $badge;
                    $item['badge_type'] = $type;
                    $this->isDirty = true;
                    break 2;
                }
            }
        }

        return $this;
    }

    public function updateCounter(string $itemId, ?int $counter): self
    {
        foreach ($this->sections as &$section) {
            foreach ($section['items'] as &$item) {
                if ($item['id'] === $itemId) {
                    $item['counter'] = $counter;
                    $this->isDirty = true;
                    break 2;
                }
            }
        }

        return $this;
    }

    public function removeItem(string $itemId): self
    {
        foreach ($this->sections as &$section) {
            $section['items'] = array_values(array_filter(
                $section['items'],
                static fn ($item) => $item['id'] !== $itemId,
            ));
        }

        $this->isDirty = true;

        return $this;
    }

    /**
     * @param array<string,mixed> $item
     */
    public function isActive(array $item): bool
    {
        $itemPath = parse_url($item['url'], PHP_URL_PATH);

        if ($itemPath === $this->currentPath) {
            return true;
        }

        return $itemPath !== '/' && is_string($itemPath) && str_starts_with($this->currentPath, $itemPath);
    }

    private function hasPermission(?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }

        return isset($this->permissions[$permission]) || isset($this->permissions['admin']);
    }

    public function render(bool $forceRender = false): string
    {
        if (! $forceRender && ! $this->isDirty && $this->renderedCache !== null) {
            return $this->renderedCache;
        }

        $buffer = [];

        uasort($this->sections, static fn ($a, $b) => $a['order'] <=> $b['order']);

        foreach ($this->sections as $section) {
            if (! $section['visible'] || ! $this->hasPermission($section['permission'])) {
                continue;
            }

            usort($section['items'], static fn ($a, $b) => $a['order'] <=> $b['order']);

            $buffer[] = '<div class="mb-6">';
            $buffer[] = '<p class="text-xs font-semibold mb-2 px-4" style="color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">';
            $buffer[] = htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8');
            $buffer[] = '</p>';

            foreach ($section['items'] as $item) {
                if (! $item['visible'] || ! $this->hasPermission($item['permission'])) {
                    continue;
                }

                $buffer[] = $this->renderItem($item);
            }

            $buffer[] = '</div>';
        }

        $this->renderedCache = implode('', $buffer);
        $this->isDirty = false;

        return $this->renderedCache;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function renderItem(array $item): string
    {
        $isActive = $this->isActive($item);

        $buffer = [];
        $buffer[] = '<a href="';
        $buffer[] = htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8');
        $buffer[] = '" class="nav-link';
        if ($isActive) {
            $buffer[] = ' active';
        }
        $buffer[] = '" style="position: relative;">';

        if ($item['icon']) {
            $buffer[] = $this->renderIcon($item['icon']);
        }

        $buffer[] = '<span class="sidebar-label">';
        $buffer[] = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
        $buffer[] = '</span>';

        $buffer[] = '<span class="nav-tooltip">';
        $buffer[] = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
        $buffer[] = '</span>';

        if ($item['badge'] !== null) {
            $buffer[] = '<span class="ml-auto badge badge-';
            $buffer[] = $item['badge_type'];
            $buffer[] = '">';
            $buffer[] = htmlspecialchars((string) $item['badge'], ENT_QUOTES, 'UTF-8');
            $buffer[] = '</span>';
        } elseif ($item['counter'] !== null) {
            $buffer[] = '<span class="ml-auto text-xs sidebar-label" style="color: var(--text-muted);">';
            $buffer[] = htmlspecialchars((string) $item['counter'], ENT_QUOTES, 'UTF-8');
            $buffer[] = '</span>';
        }

        $buffer[] = '</a>';

        if (! empty($item['children']) && $isActive) {
            $buffer[] = '<div class="ml-8 mt-2 sidebar-label">';
            foreach ($item['children'] as $child) {
                $buffer[] = $this->renderItem($child);
            }
            $buffer[] = '</div>';
        }

        return implode('', $buffer);
    }

    private function renderIcon(string $iconName): string
    {
        $this->iconCache ??= $this->getIconPaths();

        $svgPath = $this->iconCache[$iconName] ?? $iconName;

        return '<svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">' . $svgPath . '</svg>';
    }

    /** @return array<string,string> */
    private function getIconPaths(): array
    {
        return [
            'dashboard' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>',
            'calendar'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>',
            'grid'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>',
            'users'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>',
            'vote'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            'lightning' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>',
            'money'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            'chart'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
            'settings'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
            'back'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>',
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->sections, JSON_PRETTY_PRINT);
    }

    /** @return array<string,mixed> */
    public function getSections(): array
    {
        return $this->sections;
    }

    /** @return array<string,mixed>|null */
    public function getSection(string $key): ?array
    {
        return $this->sections[$key] ?? null;
    }

    public function clear(): self
    {
        $this->sections = [];

        return $this;
    }
}
