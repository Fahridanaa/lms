<?php

namespace App\Http\Controllers\Traits;

use App\Models\User;
use App\Services\ActorResolver;
use Illuminate\Http\Request;

/**
 * Resolve the benchmark actor from the X-Benchmark-Actor-Id header.
 *
 * Use in controllers that need actor-aware access control.
 *
 * @method \App\Services\ActorResolver actorResolver()
 */
trait ResolvesActor
{
    /**
     * Resolve the actor from the current request.
     */
    protected function resolveActor(Request $request): User
    {
        return $this->actorResolver->resolve($request);
    }
}
