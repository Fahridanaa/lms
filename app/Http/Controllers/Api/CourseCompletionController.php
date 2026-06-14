<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponseTrait;
use App\Http\Controllers\Traits\ResolvesActor;
use App\Models\Course;
use App\Services\ActorResolver;
use App\Services\CourseAccessService;
use App\Services\CourseCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseCompletionController extends Controller
{
    use ApiResponseTrait;
    use ResolvesActor;

    public function __construct(
        protected CourseCompletionService $courseCompletionService,
        protected ActorResolver $actorResolver,
        protected CourseAccessService $courseAccessService
    ) {
    }

    /**
     * GET /api/courses/{courseId}/completion
     *
     * Returns the completion state for the requesting user in a course.
     */
    public function show(Request $request, int $courseId): JsonResponse
    {
        $actor = $this->resolveActor($request);
        $course = Course::query()->findOrFail($courseId);

        if (! $this->courseAccessService->canReadCourse($actor, $course)) {
            throw new BusinessException('Access denied', 403);
        }

        $progress = $this->courseCompletionService->getUserProgress($courseId, $actor->id);

        return response()->json([
            'course_id' => (int) $courseId,
            'completed' => $progress['completed'],
            'completed_at' => $progress['completed_at']?->toIso8601String(),
            'progress' => [
                'criteria_met' => $progress['criteria_met'],
                'criteria_total' => $progress['criteria_total'],
                'criteria' => $progress['criteria'],
            ],
        ]);
    }
}
