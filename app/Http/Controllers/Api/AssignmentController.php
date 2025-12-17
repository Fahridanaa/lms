<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GradeSubmissionAssignmentRequest;
use App\Http\Requests\SubmitAssignmentRequest;
use App\Services\AssignmentService;
use Illuminate\Http\Request;
use App\Http\Controllers\Traits\ApiResponseTrait;

class AssignmentController extends Controller
{
    use ApiResponseTrait;
    public function __construct(
        protected AssignmentService $assignmentService
    ) {
    }

    /**
     * GET /api/courses/{courseId}/assignments
     */
    public function index(int $courseId)
    {
        $assignments = $this->assignmentService->getCourseAssignments($courseId);

        return $this->success($assignments);
    }

    /**
     * GET /api/assignments/{id}
     */
    public function show(int $id)
    {
        $assignment = $this->assignmentService->getAssignmentById($id);

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
            $validated['user_id'],
            $validated['file_path']
        );

        return $this->created($submission, 'Tugas berhasil dikumpulkan');
    }

    /**
     * GET /api/assignments/{id}/submissions
     */
    public function submissions(int $id)
    {
        $submissions = $this->assignmentService->getAssignmentSubmissions($id) ?? [];

        return $this->success($submissions);
    }

    /**
     * GET /api/assignments/{id}/submissions/pending
     */
    public function pendingSubmissions(int $id)
    {
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
            $validated['feedback'] ?? null
        );

        return $this->success($submission, 'Pengumpulan tugas berhasil dinilai');
    }

    /**
     * GET /api/assignments/{id}/statistics
     */
    public function statistics(int $id)
    {
        $statistics = $this->assignmentService->getAssignmentStatistics($id);

        return $this->success($statistics);
    }
}
