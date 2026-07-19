<?php

declare(strict_types=1);

namespace CodeVault\Hooks;

/**
 * Action-hook dispatcher — the extensibility backbone (blueprint §3).
 * Modules/addons register listeners against named hook points (see
 * HookPoints); the core fires them at the right moment in each engine.
 *
 * Listeners run in priority order (lower first) and each listener's return
 * value is collected, so hooks can be used both as plain action notifications
 * (ignore the return) and as filters (e.g. a listener that returns extra
 * template variables to merge into a page).
 */
class HookDispatcher
{
    /** @var array<string, array<int, array{priority: int, listener: callable}>> */
    private array $listeners = [];

    public function register(string $hookPoint, callable $listener, int $priority = 10): void
    {
        $this->listeners[$hookPoint][] = compact('priority', 'listener');
    }

    public function has(string $hookPoint): bool
    {
        return !empty($this->listeners[$hookPoint]);
    }

    /**
     * @return array<int, mixed> one entry per listener, in firing order
     */
    public function fire(string $hookPoint, array $params = []): array
    {
        if (!$this->has($hookPoint)) {
            return [];
        }

        $ordered = $this->listeners[$hookPoint];
        usort($ordered, fn (array $a, array $b) => $a['priority'] <=> $b['priority']);

        $results = [];

        foreach ($ordered as $entry) {
            $results[] = ($entry['listener'])($params);
        }

        return $results;
    }

    public function clear(string $hookPoint): void
    {
        unset($this->listeners[$hookPoint]);
    }

    /**
     * @return array<string, int> hook point => listener count, for admin/debug display
     */
    public function registered(): array
    {
        return array_map(static fn (array $entries) => count($entries), $this->listeners);
    }
}
