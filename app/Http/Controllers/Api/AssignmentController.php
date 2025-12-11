<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
     * Get assignments for a course
     * GET /api/courses/{courseId}/assignments
     */
    public function index(int $courseId)
    {
        $assignments = $this->assignmentService->getCourseAssignments($courseId);

        return $this->success($assignments);
    }

    /**
     * Get assignment detail
     * GET /api/assignments/{id}
     */
    public function show(int $id)
    {
        $assignment = $this->assignmentService->getAssignmentById($id);

        return $this->success($assignment);
    }

    /**
     * Submit assignment
     * POST /api/assignments/{id}/submissions
     */
    public function submit(Request $request, int $id)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'file_path' => 'required|string',
        ]);

        $submission = $this->assignmentService->submitAssignment(
            $id,
            $request->user_id,
            $request->all()
        );

        return $this->created($submission, 'Assignment submitted successfully');
    }

    /**
     * Get all submissions for an assignment
     * GET /api/assignments/{id}/submissions
     */
    public function submissions(int $id)
    {
        $submissions = $this->assignmentService->getAssignmentSubmissions($id);

        return $this->success($submissions);
    }

    /**
     * Get pending (ungraded) submissions
     * GET /api/assignments/{id}/submissions/pending
     */
    public function pendingSubmissions(int $id)
    {
        $submissions = $this->assignmentService->getPendingSubmissions($id);

        return $this->success($submissions);
    }

    /**
     * Grade a submission
     * PUT /api/submissions/{id}/grade
     */
    public function gradeSubmission(Request $request, int $id)
    {
        $request->validate([
            'score' => 'required|numeric|min:0',
            'feedback' => 'nullable|string',
        ]);

        $submission = $this->assignmentService->gradeSubmission(
            $id,
            $request->score,
            $request->feedback
        );

        return $this->success($submission, 'Submission graded successfully');
    }

    /**
     * Get assignment statistics
     * GET /api/assignments/{id}/statistics
     */
    public function statistics(int $id)
    {
        $statistics = $this->assignmentService->getAssignmentStatistics($id);

        return $this->success($statistics);
    }
}
