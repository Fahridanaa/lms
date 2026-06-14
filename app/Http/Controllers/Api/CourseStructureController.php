<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponseTrait;
use App\Http\Controllers\Traits\ResolvesActor;
use App\Services\ActorResolver;
use App\Services\CourseStructureService;
use Illuminate\Http\Request;

class CourseStructureController extends Controller
{
    use ApiResponseTrait;
    use ResolvesActor;

    public function __construct(
        protected CourseStructureService $courseStructureService,
        protected ActorResolver $actorResolver
    ) {}

    /**
     * GET /api/courses/{course}/structure
     */
    public function show(Request $request, int $courseId)
    {
        $actor = $this->resolveActor($request);

        $structure = $this->courseStructureService->getStructure($courseId, $actor);

        return $this->success($structure);
    }
}
