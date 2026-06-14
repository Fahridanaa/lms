<?php

namespace App\Http\Controllers\Api;

use App\Constants\Messages\QuizMessage;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponseTrait;
use App\Http\Controllers\Traits\ResolvesActor;
use App\Http\Requests\StartAttemptQuizRequest;
use App\Http\Requests\SubmitAttemptQuizRequest;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Services\ActorResolver;
use App\Services\CourseAccessService;
use App\Services\ModuleAvailabilityService;
use App\Services\QuizService;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    use ApiResponseTrait;
    use ResolvesActor;

    public function __construct(
        protected QuizService $quizService,
        protected ActorResolver $actorResolver,
        protected CourseAccessService $courseAccessService,
        protected ModuleAvailabilityService $moduleAvailabilityService
    ) {
    }

    /**
     * GET /api/quizzes
     */
    public function index(Request $request)
    {
        $actor = $this->resolveActor($request);
        $quizzes = $this->quizService->getAllQuizzes();

        // Filter to only quizzes the actor has access to
        $quizzes = collect($quizzes)->filter(function ($quiz) use ($actor) {
            $course = Course::query()->find($quiz['course_id'] ?? $quiz->course_id ?? null);
            if (! $course) {
                return false;
            }

            return $this->courseAccessService->canReadCourse($actor, $course);
        })->values();

        return $this->success($quizzes);
    }

    /**
     * GET /api/quizzes/{id}
     */
    public function show(Request $request, int $id)
    {
        $actor = $this->resolveActor($request);
        $quiz = $this->quizService->getQuizWithQuestions($id);

        // Centralized access check: course enrolment + module readability + full availability
        $this->courseAccessService->assertActivityAvailableForRead($actor, $quiz);

        return $this->success($quiz);
    }

    /**
     * GET /api/quizzes/{id}/questions
     */
    public function questions(Request $request, int $id)
    {
        $actor = $this->resolveActor($request);
        $quiz = $this->quizService->getQuizWithQuestions($id);

        // Centralized access check: course enrolment + module readability + full availability
        $this->courseAccessService->assertActivityAvailableForRead($actor, $quiz);

        $questions = $this->quizService->getQuizQuestions($id);

        // Never expose correct answers in the questions list
        if (is_array($questions) || is_object($questions)) {
            $questions = collect($questions)->map(function ($q) {
                if (is_array($q)) {
                    unset($q['correct_answer'], $q['correct_option_id']);
                } elseif (is_object($q)) {
                    unset($q->correct_answer, $q->correct_option_id);
                }

                return $q;
            })->values();
        }

        return $this->success($questions);
    }

    /**
     * POST /api/quizzes/{id}/attempts
     */
    public function startAttempt(StartAttemptQuizRequest $request, int $id)
    {
        $attempt = $this->quizService->startQuizAttempt($id, $this->resolveActor($request));

        return $this->created($attempt, QuizMessage::ATTEMPT_STARTED);
    }

    /**
     * PUT /api/quizzes/{quizId}/attempts/{attemptId}
     */
    public function submitAttempt(SubmitAttemptQuizRequest $request, int $quizId, int $attemptId)
    {
        $actor = $this->resolveActor($request);

        // Verify the attempt exists and belongs to the actor (ownership check)
        $attempt = QuizAttempt::with(['attemptQuestions.question', 'attemptQuestions.steps.stepData'])->findOrFail($attemptId);
        if ($attempt->user_id !== $actor->id) {
            throw new BusinessException('You do not have permission to submit this attempt', 403);
        }

        $attempt = $this->quizService->submitQuizAnswers(
            $attemptId,
            $request->validated()['answers'],
            $quizId  // Pass quizId untuk validasi
        );

        return $this->success($attempt, QuizMessage::QUIZ_SUBMITTED);
    }

    /**
     * GET /api/quizzes/{quizId}/attempts/{attemptId}/result
     *
     * Validates that the attempt belongs to the specified quiz before returning result.
     */
    public function attemptResult(Request $request, int $quizId, int $attemptId)
    {
        $actor = $this->resolveActor($request);
        $result = $this->quizService->getAttemptResult($attemptId);

        // Validasi bahwa attempt milik quiz yang benar
        if (($result['quiz_id'] ?? null) !== $quizId) {
            throw new BusinessException(QuizMessage::NOT_FOUND, 404);
        }

        // Actor must own the attempt or be instructor for the course
        if (($result['user_id'] ?? null) !== $actor->id) {
            $quiz = Quiz::query()->with('course')->findOrFail($quizId);
            if (! $this->courseAccessService->isInstructorForCourse($actor, $quiz->course)) {
                throw new BusinessException('You do not have permission to view this result', 403);
            }
        }

        return $this->success($result);
    }

    /**
     * GET /api/users/{userId}/quiz-attempts?quiz_id={quizId}
     */
    public function userAttempts(Request $request, int $userId)
    {
        $actor = $this->resolveActor($request);

        // Actor must be the same user or an instructor for the quiz's course
        if ($actor->id !== $userId) {
            $quizId = $request->query('quiz_id');
            if ($quizId) {
                $quiz = Quiz::query()->with('course')->findOrFail($quizId);
                if (! $this->courseAccessService->isInstructorForCourse($actor, $quiz->course)) {
                    throw new BusinessException('You do not have permission to view these attempts', 403);
                }
            } else {
                throw new BusinessException('You do not have permission to view these attempts', 403);
            }
        }

        $quizId = $request->query('quiz_id');
        $attempts = $this->quizService->getUserQuizAttempts($userId, $quizId);

        return $this->success($attempts);
    }
}
