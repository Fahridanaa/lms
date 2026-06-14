<?php

namespace App\Http\Controllers\Api;

use App\Constants\Messages\GradeMessage;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponseTrait;
use App\Http\Controllers\Traits\ResolvesActor;
use App\Http\Requests\UpdateGradebookRequest;
use App\Models\Course;
use App\Services\ActorResolver;
use App\Services\CourseAccessService;
use App\Services\GradebookService;
use Illuminate\Http\Request;

class GradebookController extends Controller
{
    use ApiResponseTrait;
    use ResolvesActor;

    public function __construct(
        protected GradebookService $gradebookService,
        protected ActorResolver $actorResolver,
        protected CourseAccessService $courseAccessService
    ) {
    }

    /**
     * GET /api/courses/{courseId}/gradebook
     */
    public function courseGradebook(Request $request, int $courseId)
    {
        $actor = $this->resolveActor($request);
        $course = Course::query()->findOrFail($courseId);

        if (! $this->courseAccessService->canReadGradebook($actor, $course)) {
            throw new BusinessException('You do not have permission to view this gradebook', 403);
        }

        $gradebook = $this->gradebookService->getCourseGradebook($courseId, $actor);

        return $this->success($gradebook);
    }

    /**
     * GET /api/users/{userId}/grades
     */
    public function userGrades(Request $request, int $userId)
    {
        $actor = $this->resolveActor($request);

        if ($actor->id !== $userId) {
            // Allow if actor is instructor for at least one course the user is enrolled in
            $courses = Course::query()
                ->whereIn('id', function ($q) use ($userId) {
                    $q->select('course_id')
                        ->from('course_enrollments')
                        ->where('user_id', $userId)
                        ->where('role', 'student')
                        ->where('status', 'active');
                })
                ->get();

            $isInstructor = $courses->contains(fn ($course) => $this->courseAccessService->isInstructorForCourse($actor, $course));

            if (! $isInstructor) {
                throw new BusinessException('You do not have permission to view these grades', 403);
            }
        }

        $grades = $this->gradebookService->getUserGrades($userId, $actor);

        return $this->success($grades);
    }

    /**
     * GET /api/courses/{courseId}/users/{userId}/grades
     */
    public function userCourseGrades(Request $request, int $courseId, int $userId)
    {
        $actor = $this->resolveActor($request);
        $course = Course::query()->findOrFail($courseId);

        // Actor must be the same student in that course or an instructor for that course
        $isOwner = $actor->id === $userId
            && $this->courseAccessService->isActiveEnrollee($actor, $course);

        $isInstructor = $this->courseAccessService->isInstructorForCourse($actor, $course);

        if (! $isOwner && ! $isInstructor) {
            throw new BusinessException('You do not have permission to view these grades', 403);
        }

        $grades = $this->gradebookService->getUserCourseGrades($courseId, $userId, $actor);

        return $this->success($grades);
    }

    /**
     * PUT /api/grades/{id}
     */
    public function update(UpdateGradebookRequest $request, int $id)
    {
        $validated = $request->validated();

        $grade = $this->gradebookService->updateGrade($id, $validated, $this->resolveActor($request));

        return $this->success($grade, GradeMessage::UPDATED);
    }

    /**
     * GET /api/courses/{courseId}/statistics
     */
    public function courseStatistics(Request $request, int $courseId)
    {
        $actor = $this->resolveActor($request);
        $course = Course::query()->findOrFail($courseId);

        if (! $this->courseAccessService->isInstructorForCourse($actor, $course)) {
            throw new BusinessException('You do not have permission to view course statistics', 403);
        }

        $statistics = $this->gradebookService->getCourseStatistics($courseId);

        return $this->success($statistics);
    }

    /**
     * GET /api/users/{userId}/performance
     */
    public function userPerformance(Request $request, int $userId)
    {
        $actor = $this->resolveActor($request);

        if ($actor->id !== $userId) {
            throw new BusinessException('You do not have permission to view this performance summary', 403);
        }

        $performance = $this->gradebookService->getUserPerformanceSummary($userId);

        return $this->success($performance);
    }

    /**
     * GET /api/courses/{courseId}/top-performers?limit=10
     */
    public function topPerformers(Request $request, int $courseId)
    {
        $actor = $this->resolveActor($request);
        $course = Course::query()->findOrFail($courseId);

        if (! $this->courseAccessService->isInstructorForCourse($actor, $course)) {
            throw new BusinessException('You do not have permission to view top performers', 403);
        }

        $limit = $request->query('limit', 10);
        $topPerformers = $this->gradebookService->getTopPerformers($courseId, $limit);

        return $this->success($topPerformers);
    }
}
