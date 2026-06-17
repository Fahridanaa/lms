<?php

namespace App\Services;

use App\Models\Capability;
use App\Models\Context;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RoleCapability;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AuthorizationService
{
    private ContextService $contextService;

    /**
     * @var array<string, bool> Request-scoped cache for role/capability checks
     */
    private array $checkCache = [];

    /**
     * @var array<int, Collection<int, Context>> Request-scoped cache for ancestors by context id
     */
    private array $ancestorCache = [];

    public function __construct(ContextService $contextService)
    {
        $this->contextService = $contextService;
    }

    /**
     * Get ancestors for a context, using request-scoped cache.
     *
     * @return Collection<int, Context>
     */
    public function ancestors(Context $context): Collection
    {
        if (isset($this->ancestorCache[$context->id])) {
            return $this->ancestorCache[$context->id];
        }

        $ancestors = $this->contextService->ancestors($context);
        $this->ancestorCache[$context->id] = $ancestors;

        return $ancestors;
    }

    /**
     * Check if a user has a specific role at a context or any of its ancestors.
     * This provides inherited role resolution (e.g., instructor at course context
     * counts as instructor at module context).
     */
    public function userHasRoleAt(User $user, string $shortname, Context $context): bool
    {
        $requestCacheKey = "role_at:{$shortname}:{$context->id}:{$user->id}";

        if (isset($this->checkCache[$requestCacheKey])) {
            return $this->checkCache[$requestCacheKey];
        }

        $cacheKey = "auth:role_check:{$shortname}:{$context->id}:{$user->id}";

        $result = Cache::remember($cacheKey, 3600, function () use ($user, $shortname, $context) {
            // Check exact context
            if ($this->userHasRoleAtContext($user, $shortname, $context)) {
                return true;
            }

            // Check ancestors (uses request-scoped ancestor cache)
            $ancestors = $this->ancestors($context);
            foreach ($ancestors as $ancestor) {
                if ($this->userHasRoleAtContext($user, $shortname, $ancestor)) {
                    return true;
                }
            }

            return false;
        });

        $this->checkCache[$requestCacheKey] = $result;

        return $result;
    }

    /**
     * Check if a user has a specific role at exactly this context (no ancestor walk).
     */
    public function userHasRoleAtContext(User $user, string $shortname, Context $context): bool
    {
        $requestCacheKey = "role_at_exact:{$shortname}:{$context->id}:{$user->id}";

        if (isset($this->checkCache[$requestCacheKey])) {
            return $this->checkCache[$requestCacheKey];
        }

        $cacheKey = "auth:role_check_exact:{$shortname}:{$context->id}:{$user->id}";

        $result = Cache::remember($cacheKey, 3600, function () use ($user, $shortname, $context) {
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

        $this->checkCache[$requestCacheKey] = $result;

        return $result;
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
     * Check if a user has a specific capability at a context or any of its ancestors.
     * This provides inherited capability resolution (e.g., instructor at course context
     * means instructor capabilities apply at module context).
     */
    public function userHasCapabilityAt(User $user, string $capability, Context $context): bool
    {
        $requestCacheKey = "cap_at:{$capability}:{$context->id}:{$user->id}";

        if (isset($this->checkCache[$requestCacheKey])) {
            return $this->checkCache[$requestCacheKey];
        }

        $cacheKey = "auth:cap_check:{$capability}:{$context->id}:{$user->id}";

        $result = Cache::remember($cacheKey, 3600, function () use ($user, $capability, $context) {
            // Check exact context
            if ($this->userHasCapabilityAtContext($user, $capability, $context)) {
                return true;
            }

            // Check ancestors (uses request-scoped ancestor cache)
            $ancestors = $this->ancestors($context);
            foreach ($ancestors as $ancestor) {
                if ($this->userHasCapabilityAtContext($user, $capability, $ancestor)) {
                    return true;
                }
            }

            return false;
        });

        $this->checkCache[$requestCacheKey] = $result;

        return $result;
    }

    /**
     * Check if a user has a specific capability at exactly this context (no ancestor walk).
     */
    public function userHasCapabilityAtContext(User $user, string $capability, Context $context): bool
    {
        $requestCacheKey = "cap_at_exact:{$capability}:{$context->id}:{$user->id}";

        if (isset($this->checkCache[$requestCacheKey])) {
            return $this->checkCache[$requestCacheKey];
        }

        $cacheKey = "auth:cap_check_exact:{$capability}:{$context->id}:{$user->id}";

        $result = Cache::remember($cacheKey, 3600, function () use ($user, $capability, $context) {
            // Find the capability
            $cap = Capability::query()->where('shortname', $capability)->first();

            if ($cap === null) {
                return false;
            }

            // Find roles that have this capability
            $roleIds = RoleCapability::query()
                ->where('capability_id', $cap->id)
                ->pluck('role_id');

            if ($roleIds->isEmpty()) {
                return false;
            }

            // Check if user has any of these roles at this exact context
            return RoleAssignment::query()
                ->whereIn('role_id', $roleIds)
                ->where('context_id', $context->id)
                ->where('user_id', $user->id)
                ->exists();
        });

        $this->checkCache[$requestCacheKey] = $result;

        return $result;
    }

    /**
     * Check if a user has a specific role at MULTIPLE contexts at once.
     * Reduces repeated ancestor walk + role assignment queries.
     *
     * @return array<int, bool> context_id => has_role
     */
    public function userHasRoleAtContexts(User $user, string $shortname, Collection $contexts): array
    {
        /** @var Role|null $role */
        $role = Role::query()->where('shortname', $shortname)->first();

        if ($role === null) {
            return $contexts->mapWithKeys(fn ($c) => [$c->id => false])->all();
        }

        $contextIds = $contexts->pluck('id');
        $assignments = RoleAssignment::query()
            ->where('role_id', $role->id)
            ->whereIn('context_id', $contextIds)
            ->where('user_id', $user->id)
            ->pluck('context_id');

        return $contexts->mapWithKeys(
            fn ($c) => [$c->id => $assignments->contains($c->id)]
        )->all();
    }

    /**
     * Invalidate cache entries related to a role check for a user at a context.
     * Also invalidates for descendant contexts that may have inherited the role.
     * Also clears capability cache entries.
     * Uses explicit key forget instead of tags for broader cache driver support.
     */
    public function invalidateRoleCache(Context $context, User $user): void
    {
        // Build the list of roles to invalidate
        $roleShortnames = Role::pluck('shortname')->all();

        // Build the list of capabilities to invalidate
        $capShortnames = Capability::pluck('shortname')->all();

        // Flush cache for this context and all descendant contexts
        $contexts = [$context];

        // Find descendant contexts by path prefix
        $descendants = Context::query()
            ->where('path', 'like', $context->path.'/%')
            ->get();

        $contexts = array_merge($contexts, $descendants->all());

        /** @var Context $ctx */
        foreach ($contexts as $ctx) {
            foreach ($roleShortnames as $shortname) {
                Cache::forget("auth:role_check:{$shortname}:{$ctx->id}:{$user->id}");
                Cache::forget("auth:role_check_exact:{$shortname}:{$ctx->id}:{$user->id}");
            }
            foreach ($capShortnames as $shortname) {
                Cache::forget("auth:cap_check:{$shortname}:{$ctx->id}:{$user->id}");
                Cache::forget("auth:cap_check_exact:{$shortname}:{$ctx->id}:{$user->id}");
            }
        }

        // Clear request-scoped cache entries for this user+context combo
        foreach ($contexts as $ctx) {
            foreach ($roleShortnames as $shortname) {
                unset($this->checkCache["role_at:{$shortname}:{$ctx->id}:{$user->id}"]);
                unset($this->checkCache["role_at_exact:{$shortname}:{$ctx->id}:{$user->id}"]);
            }
            foreach ($capShortnames as $shortname) {
                unset($this->checkCache["cap_at:{$shortname}:{$ctx->id}:{$user->id}"]);
                unset($this->checkCache["cap_at_exact:{$shortname}:{$ctx->id}:{$user->id}"]);
            }
        }
    }
}
