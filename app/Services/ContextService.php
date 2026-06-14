<?php

namespace App\Services;

use App\Models\Context;
use Illuminate\Support\Collection;

class ContextService
{
    /**
     * Resolve a context by level+instance, creating it if it doesn't exist.
     * Generates materialized path based on the parent context.
     *
     * @param  int  $level  Context level constant (10, 50, 70)
     * @param  int  $instanceId  ID in the corresponding table
     * @param  int|null  $parentContextId  For building the materialized path
     */
    public function resolveOrCreate(int $level, int $instanceId, ?int $parentContextId = null): Context
    {
        $context = $this->find($level, $instanceId);

        if ($context !== null) {
            return $context;
        }

        $path = $this->buildPath($level, $instanceId, $parentContextId);
        $depth = $this->calculateDepth($path);

        return Context::query()->create([
            'contextlevel' => $level,
            'instance_id' => $instanceId,
            'path' => $path,
            'depth' => $depth,
        ]);
    }

    /**
     * Find a context by level and instance ID.
     */
    public function find(int $level, int $instanceId): ?Context
    {
        return Context::query()
            ->where('contextlevel', $level)
            ->where('instance_id', $instanceId)
            ->first();
    }

    /**
     * Get ancestor contexts for a given context, ordered from root to parent.
     *
     * @return Collection<int, Context>
     */
    public function ancestors(Context $context): Collection
    {
        return $context->ancestors();
    }

    /**
     * Build a materialized path string.
     *
     * For system level: "/1"
     * For course: "/1/{courseId}"
     * For module: "/1/{courseId}/{moduleId}"
     *
     * Uses the parent context's path to construct the child path.
     */
    public function buildPath(int $level, int $instanceId, ?int $parentContextId = null): string
    {
        if ($level === Context::LEVEL_SYSTEM) {
            return '/1';
        }

        if ($parentContextId === null) {
            // Derive parent from the level
            return match ($level) {
                Context::LEVEL_COURSE => "/1/{$instanceId}",
                Context::LEVEL_MODULE => "/1/{$instanceId}", // fallback without parent
                default => "/1/{$instanceId}",
            };
        }

        /** @var Context|null $parent */
        $parent = Context::find($parentContextId);

        if ($parent === null) {
            return match ($level) {
                Context::LEVEL_COURSE => "/1/{$instanceId}",
                Context::LEVEL_MODULE => "/1/{$instanceId}",
                default => "/1/{$instanceId}",
            };
        }

        return rtrim($parent->path, '/').'/'.$instanceId;
    }

    /**
     * Calculate depth from a path string.
     * Depth is the number of segments minus 1 (since path starts with /1).
     * System ("/1") → depth 0, course ("/1/5") → depth 1, module ("/1/5/23") → depth 2.
     */
    public function calculateDepth(string $path): int
    {
        $segments = array_filter(explode('/', $path));

        return count($segments) - 1;
    }
}
