<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GradebookService;
use Illuminate\Http\Request;

class GradebookController extends Controller
{
    public function __construct(
        protected GradebookService $gradebookService
    ) {}

    /**
     * Get full gradebook for a course
     * GET /api/courses/{courseId}/gradebook
     */
    public function courseGradebook(int $courseId)
    {
        $gradebook = $this->gradebookService->getCourseGradebook($courseId);

        return response()->json([
            'success' => true,
            'data' => $gradebook,
        ]);
    }

    /**
     * Get all grades for a user
     * GET /api/users/{userId}/grades
     */
    public function userGrades(int $userId)
    {
        $grades = $this->gradebookService->getUserGrades($userId);

        return response()->json([
            'success' => true,
            'data' => $grades,
        ]);
    }

    /**
     * Get user's grades in a specific course
     * GET /api/courses/{courseId}/users/{userId}/grades
     */
    public function userCourseGrades(int $courseId, int $userId)
    {
        $grades = $this->gradebookService->getUserCourseGrades($courseId, $userId);

        return response()->json([
            'success' => true,
            'data' => $grades,
        ]);
    }

    /**
     * Update a grade
     * PUT /api/grades/{id}
     */
    public function update(Request $request, int $id)
    {
        $request->validate([
            'score' => 'sometimes|numeric|min:0',
            'max_score' => 'sometimes|numeric|min:0',
        ]);

        $grade = $this->gradebookService->updateGrade($id, $request->all());

        return response()->json([
            'success' => true,
            'message' => 'Grade updated successfully',
            'data' => $grade,
        ]);
    }

    /**
     * Get course statistics
     * GET /api/courses/{courseId}/statistics
     */
    public function courseStatistics(int $courseId)
    {
        $statistics = $this->gradebookService->getCourseStatistics($courseId);

        return response()->json([
            'success' => true,
            'data' => $statistics,
        ]);
    }

    /**
     * Get user performance summary
     * GET /api/users/{userId}/performance
     */
    public function userPerformance(int $userId)
    {
        $performance = $this->gradebookService->getUserPerformanceSummary($userId);

        return response()->json([
            'success' => true,
            'data' => $performance,
        ]);
    }

    /**
     * Get top performers in a course
     * GET /api/courses/{courseId}/top-performers?limit=10
     */
    public function topPerformers(Request $request, int $courseId)
    {
        $limit = $request->query('limit', 10);
        $topPerformers = $this->gradebookService->getTopPerformers($courseId, $limit);

        return response()->json([
            'success' => true,
            'data' => $topPerformers,
        ]);
    }
}
