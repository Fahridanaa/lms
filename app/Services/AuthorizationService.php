<?php

namespace App\Services;

use App\Models\Context;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AuthorizationService
{
    private ContextService $contextService;

    public function __construct(ContextService $contextService)
    {
        $this->contextService = $contextService;
    }

    /**
     * Check if a user has a specific role at a context or any of its ancestors.
     * This provides inherited role resolution (e.g., instructor at course context
     * counts as instructor at module context).
     */
    public function userHasRoleAt(User $user, string $shortname, Context $context): bool
    {
        $cacheKey = "auth:role_check:{$shortname}:{$context->id}:{$user->id}";

        return Cache::remember($cacheKey, 3600, function () use ($user, $shortname, $context) {
            // Check exact context
            if ($this->userHasRoleAtContext($user, $shortname, $context)) {
                return true;
            }

            // Check ancestors
            $ancestors = $this->contextService->ancestors($context);
            foreach ($ancestors as $ancestor) {
                if ($this->userHasRoleAtContext($user, $shortname, $ancestor)) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Check if a user has a specific role at exactly this context (no ancestor walk).
     */
    public function userHasRoleAtContext(User $user, string $shortname, Context $context): bool
    {
        $cacheKey = "auth:role_check_exact:{$shortname}:{$context->id}:{$user->id}";

        return Cache::remember($cacheKey, 3600, function () use ($user, $shortname, $context) {
            /** @var Role|null $role */
            $role = Role::query()->where('shortname', $shortname)->first();

            if ($role === null) {
                return false;
            }

            return RoleAssignment::query()
                ->where('role_id', $role->id)
                ->where('context_id', $context->id)
                ->where('user_id', $user->id)
                ->exists();
        });
    }

    /**
     * Assign a role to a user at a specific context.
     */
    public function assignRole(User $user, Role $role, Context $context): RoleAssignment
    {
        $assignment = RoleAssignment::query()->create([
            'role_id' => $role->id,
            'context_id' => $context->id,
            'user_id' => $user->id,
        ]);

        $this->invalidateRoleCache($context, $user);

        return $assignment;
    }

    /**
     * Remove a role assignment.
     */
    public function removeRole(User $user, Role $role, Context $context): void
    {
        RoleAssignment::query()
            ->where('role_id', $role->id)
            ->where('context_id', $context->id)
            ->where('user_id', $user->id)
            ->delete();

        $this->invalidateRoleCache($context, $user);
    }

    /**
     * Get all users with a given role, optionally filtered to a specific context.
     *
     * @return Collection<int, User>
     */
    public function usersWithRole(string $shortname, ?Context $context = null): Collection
    {
        /** @var Role|null $role */
        $role = Role::query()->where('shortname', $shortname)->first();

        if ($role === null) {
            return new Collection;
        }

        $query = RoleAssignment::query()
            ->where('role_id', $role->id)
            ->with('user');

        if ($context !== null) {
            $query->where('context_id', $context->id);
        }

        return $query->get()->pluck('user');
    }

    /**
     * Invalidate cache entries related to a role check for a user at a context.
     * Also invalidates for descendant contexts that may have inherited the role.
     * Uses explicit key forget instead of tags for broader cache driver support.
     */
    public function invalidateRoleCache(Context $context, User $user): void
    {
        // Build the list of roles to invalidate
        $roleShortnames = Role::pluck('shortname')->all();

        // Flush cache for this context and all descendant contexts
        $contexts = [$context];

        // Find descendant contexts by path prefix
        $descendants = Context::query()
            ->where('path', 'like', $context->path.'/%')
            ->get();

        $contexts = array_merge($contexts, $descendants->all());

        foreach ($roleShortnames as $shortname) {
            /** @var Context $ctx */
            foreach ($contexts as $ctx) {
                Cache::forget("auth:role_check:{$shortname}:{$ctx->id}:{$user->id}");
                Cache::forget("auth:role_check_exact:{$shortname}:{$ctx->id}:{$user->id}");
            }
        }
    }
}
