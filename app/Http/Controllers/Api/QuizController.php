<?php

namespace App\Http\Controllers\Api;

use App\Constants\Messages\QuizMessage;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponseTrait;
use App\Http\Requests\StartAttemptQuizRequest;
use App\Http\Requests\SubmitAttemptQuizRequest;
use App\Services\QuizService;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    use ApiResponseTrait;
    public function __construct(
        protected QuizService $quizService
    ) {
    }

    /**
     * GET /api/quizzes
     */
    public function index()
    {
        $quizzes = $this->quizService->getAllQuizzes();

        return $this->success($quizzes);
    }

    /**
     * GET /api/quizzes/{id}
     */
    public function show(int $id)
    {
        $quiz = $this->quizService->getQuizWithQuestions($id);

        return $this->success($quiz);
    }

    /**
     * GET /api/quizzes/{id}/questions
     */
    public function questions(int $id)
    {
        $questions = $this->quizService->getQuizQuestions($id);

        return $this->success($questions);
    }

    /**
     * POST /api/quizzes/{id}/attempts
     */
    public function startAttempt(StartAttemptQuizRequest $request, int $id)
    {
        $attempt = $this->quizService->startQuizAttempt($id, $request->validated()['user_id']);

        return $this->created($attempt, QuizMessage::ATTEMPT_STARTED);
    }

    /**
     * PUT /api/quizzes/{quizId}/attempts/{attemptId}
     */
    public function submitAttempt(SubmitAttemptQuizRequest $request, int $quizId, int $attemptId)
    {
        $attempt = $this->quizService->submitQuizAnswers($attemptId, $request->validated()['answers']);

        return $this->success($attempt, QuizMessage::QUIZ_SUBMITTED);
    }

    /**
     * GET /api/quizzes/{quizId}/attempts/{attemptId}/result
     */
    public function attemptResult(int $quizId, int $attemptId)
    {
        $result = $this->quizService->getAttemptResult($attemptId);

        return $this->success($result);
    }

    /**
     * GET /api/users/{userId}/quiz-attempts?quiz_id={quizId}
     */
    public function userAttempts(Request $request, int $userId)
    {
        $quizId = $request->query('quiz_id');
        $attempts = $this->quizService->getUserQuizAttempts($userId, $quizId);

        return $this->success($attempts);
    }
}
