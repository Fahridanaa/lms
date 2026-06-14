<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Http\Request;

class ActorResolver
{
    /**
     * Resolve the actor User from the X-Benchmark-Actor-Id header.
     *
     * @throws BusinessException 401 when the header is missing or the user is not found.
     */
    public function resolve(Request $request): User
    {
        $actorId = $request->header('X-Benchmark-Actor-Id');

        if ($actorId === null || $actorId === '') {
            throw new BusinessException('Missing benchmark actor header', 401);
        }

        /** @var User|null $actor */
        $actor = User::query()->find($actorId);

        if ($actor === null) {
            throw new BusinessException('Unknown benchmark actor', 401);
        }

        return $actor;
    }

    /**
     * Resolve the actor from a raw actor ID (for tests, console, or programmatic use).
     *
     * @throws BusinessException 401 when the user is not found.
     */
    public function resolveFromId(int $actorId): User
    {
        /** @var User|null $actor */
        $actor = User::query()->find($actorId);

        if ($actor === null) {
            throw new BusinessException('Unknown benchmark actor', 401);
        }

        return $actor;
    }
}
