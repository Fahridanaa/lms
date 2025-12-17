<?php

namespace App\Http\Controllers\Api;

use App\Constants\Messages\GradeMessage;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponseTrait;
use App\Http\Requests\UpdateGradebookRequest;
use App\Services\GradebookService;
use Illuminate\Http\Request;

class GradebookController extends Controller
{
    use ApiResponseTrait;
    public function __construct(
        protected GradebookService $gradebookService
    ) {
    }

    /**
     * GET /api/courses/{courseId}/gradebook
     */
    public function courseGradebook(int $courseId)
    {
        $gradebook = $this->gradebookService->getCourseGradebook($courseId);

        return $this->success($gradebook);
    }

    /**
     * GET /api/users/{userId}/grades
     */
    public function userGrades(int $userId)
    {
        $grades = $this->gradebookService->getUserGrades($userId);

        return $this->success($grades);
    }

    /**
     * GET /api/courses/{courseId}/users/{userId}/grades
     */
    public function userCourseGrades(int $courseId, int $userId)
    {
        $grades = $this->gradebookService->getUserCourseGrades($courseId, $userId);

        return $this->success($grades);
    }

    /**
     * PUT /api/grades/{id}
     */
    public function update(UpdateGradebookRequest $request, int $id)
    {
        $grade = $this->gradebookService->updateGrade($id, $request->validated());

        return $this->success($grade, GradeMessage::UPDATED);
    }

    /**
     * GET /api/courses/{courseId}/statistics
     */
    public function courseStatistics(int $courseId)
    {
        $statistics = $this->gradebookService->getCourseStatistics($courseId);

        return $this->success($statistics);
    }

    /**
     * GET /api/users/{userId}/performance
     */
    public function userPerformance(int $userId)
    {
        $performance = $this->gradebookService->getUserPerformanceSummary($userId);

        return $this->success($performance);
    }

    /**
     * GET /api/courses/{courseId}/top-performers?limit=10
     */
    public function topPerformers(Request $request, int $courseId)
    {
        $limit = $request->query('limit', 10);
        $topPerformers = $this->gradebookService->getTopPerformers($courseId, $limit);

        return $this->success($topPerformers);
    }
}
