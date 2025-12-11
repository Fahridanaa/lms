<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AssignmentService;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    public function __construct(
        protected AssignmentService $assignmentService
    ) {}

    /**
     * Get assignments for a course
     * GET /api/courses/{courseId}/assignments
     */
    public function index(int $courseId)
    {
        $assignments = $this->assignmentService->getCourseAssignments($courseId);

        return response()->json([
            'success' => true,
            'data' => $assignments,
        ]);
    }

    /**
     * Get assignment detail
     * GET /api/assignments/{id}
     */
    public function show(int $id)
    {
        $assignment = $this->assignmentService->getAssignmentById($id);

        return response()->json([
            'success' => true,
            'data' => $assignment,
        ]);
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

        return response()->json([
            'success' => true,
            'message' => 'Assignment submitted successfully',
            'data' => $submission,
        ], 201);
    }

    /**
     * Get all submissions for an assignment
     * GET /api/assignments/{id}/submissions
     */
    public function submissions(int $id)
    {
        $submissions = $this->assignmentService->getAssignmentSubmissions($id);

        return response()->json([
            'success' => true,
            'data' => $submissions,
        ]);
    }

    /**
     * Get pending (ungraded) submissions
     * GET /api/assignments/{id}/submissions/pending
     */
    public function pendingSubmissions(int $id)
    {
        $submissions = $this->assignmentService->getPendingSubmissions($id);

        return response()->json([
            'success' => true,
            'data' => $submissions,
        ]);
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

        return response()->json([
            'success' => true,
            'message' => 'Submission graded successfully',
            'data' => $submission,
        ]);
    }

    /**
     * Get assignment statistics
     * GET /api/assignments/{id}/statistics
     */
    public function statistics(int $id)
    {
        $statistics = $this->assignmentService->getAssignmentStatistics($id);

        return response()->json([
            'success' => true,
            'data' => $statistics,
        ]);
    }
}
