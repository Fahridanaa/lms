<?php

namespace App\Http\Controllers\Api;

use App\Constants\Messages\AssignmentMessage;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponseTrait;
use App\Http\Controllers\Traits\ResolvesActor;
use App\Http\Requests\GradeSubmissionAssignmentRequest;
use App\Http\Requests\MarkerGradeAssignmentRequest;
use App\Http\Requests\SubmitAssignmentRequest;
use App\Models\Assignment;
use App\Models\Course;
use App\Services\ActorResolver;
use App\Services\AssignmentService;
use App\Services\CourseAccessService;
use App\Services\ModuleAvailabilityService;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    use ApiResponseTrait;
    use ResolvesActor;

    public function __construct(
        protected AssignmentService $assignmentService,
        protected ActorResolver $actorResolver,
        protected CourseAccessService $courseAccessService,
        protected ModuleAvailabilityService $moduleAvailabilityService
    ) {}

    /**
     * GET /api/courses/{courseId}/assignments
     */
    public function index(Request $request, int $courseId)
    {
        $actor = $this->resolveActor($request);
        $course = Course::query()->findOrFail($courseId);

        if (! $this->courseAccessService->canReadCourse($actor, $course)) {
            throw new BusinessException('You do not have permission to view assignments for this course', 403);
        }

        // Actor-shaped list: filtered by what the actor can read
        $assignments = $this->assignmentService->getCourseAssignments($courseId, $actor);

        return $this->success($assignments);
    }

    /**
     * GET /api/assignments/{id}
     */
    public function show(Request $request, int $id)
    {
        $actor = $this->resolveActor($request);
        $assignment = $this->assignmentService->getAssignmentById($id);

        // Centralized access check: course enrolment + module readability + full availability
        $this->courseAccessService->assertActivityAvailableForRead($actor, $assignment);

        return $this->success($assignment);
    }

    /**
     * POST /api/assignments/{id}/submissions
     */
    public function submit(SubmitAssignmentRequest $request, int $id)
    {
        $validated = $request->validated();

        $submission = $this->assignmentService->submitAssignment(
            $id,
            $this->resolveActor($request),
            $validated['file_path']
        );

        return $this->created($submission, AssignmentMessage::SUBMITTED);
    }

    /**
     * GET /api/assignments/{id}/submissions
     */
    public function submissions(Request $request, int $id)
    {
        $actor = $this->resolveActor($request);
        $assignment = Assignment::query()->with('course')->findOrFail($id);

        if (! $this->courseAccessService->isInstructorForCourse($actor, $assignment->course)) {
            throw new BusinessException('You do not have permission to view submissions', 403);
        }

        $submissions = $this->assignmentService->getAssignmentSubmissions($id) ?? [];

        return $this->success($submissions);
    }

    /**
     * GET /api/assignments/{id}/submissions/pending
     */
    public function pendingSubmissions(Request $request, int $id)
    {
        $actor = $this->resolveActor($request);
        $assignment = Assignment::query()->with('course')->findOrFail($id);

        if (! $this->courseAccessService->isInstructorForCourse($actor, $assignment->course)) {
            throw new BusinessException('You do not have permission to view pending submissions', 403);
        }

        $submissions = $this->assignmentService->getPendingSubmissions($id);

        return $this->success($submissions);
    }

    /**
     * PUT /api/submissions/{id}/grade
     */
    public function gradeSubmission(GradeSubmissionAssignmentRequest $request, int $id)
    {
        $validated = $request->validated();

        $submission = $this->assignmentService->gradeSubmission(
            $id,
            $validated['score'],
            $validated['feedback'] ?? null,
            $this->resolveActor($request)
        );

        return $this->success($submission, AssignmentMessage::GRADED);
    }

    /**
     * PUT /api/submissions/{id}/marker-grade
     *
     * Records a marker mark for an allocated marker and finalizes the
     * submission grade using the assignment's multi_mark_method.
     */
    public function markerGrade(MarkerGradeAssignmentRequest $request, int $id)
    {
        $validated = $request->validated();
        $actor = $this->resolveActor($request);

        $result = $this->assignmentService->markerGrade(
            $id,
            $actor->id,
            $validated['score'],
            $validated['feedback'] ?? null,
            $actor
        );

        return $this->success($result, AssignmentMessage::GRADED);
    }

    /**
     * GET /api/assignments/{id}/statistics
     */
    public function statistics(Request $request, int $id)
    {
        $actor = $this->resolveActor($request);
        $assignment = Assignment::query()->with('course')->findOrFail($id);

        if (! $this->courseAccessService->isInstructorForCourse($actor, $assignment->course)) {
            throw new BusinessException('You do not have permission to view assignment statistics', 403);
        }

        $statistics = $this->assignmentService->getAssignmentStatistics($id);

        return $this->success($statistics);
    }
}
