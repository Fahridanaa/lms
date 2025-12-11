<?php

use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\GradebookController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\QuizController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application.
| These routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group.
|
*/

// Quiz Module (Read-Heavy)
Route::prefix('quizzes')->group(function () {
    Route::get('/', [QuizController::class, 'index']);
    Route::get('/{id}', [QuizController::class, 'show']);
    Route::get('/{id}/questions', [QuizController::class, 'questions']);
    Route::post('/{id}/attempts', [QuizController::class, 'startAttempt']);
    Route::put('/{quizId}/attempts/{attemptId}', [QuizController::class, 'submitAttempt']);
    Route::get('/{quizId}/attempts/{attemptId}/result', [QuizController::class, 'attemptResult']);
});

// Material Module (Read-Heavy)
Route::prefix('materials')->group(function () {
    Route::get('/{id}', [MaterialController::class, 'show']);
    Route::get('/{id}/download', [MaterialController::class, 'download']);
    Route::post('/', [MaterialController::class, 'store']);
    Route::put('/{id}', [MaterialController::class, 'update']);
    Route::delete('/{id}', [MaterialController::class, 'destroy']);
});

// Assignment Module (Write-Heavy)
Route::prefix('assignments')->group(function () {
    Route::get('/{id}', [AssignmentController::class, 'show']);
    Route::get('/{id}/submissions', [AssignmentController::class, 'submissions']);
    Route::get('/{id}/submissions/pending', [AssignmentController::class, 'pendingSubmissions']);
    Route::post('/{id}/submissions', [AssignmentController::class, 'submit']);
    Route::get('/{id}/statistics', [AssignmentController::class, 'statistics']);
});

// Submission grading
Route::put('/submissions/{id}/grade', [AssignmentController::class, 'gradeSubmission']);

// Course-specific routes
Route::prefix('courses')->group(function () {
    // Course materials
    Route::get('/{courseId}/materials', [MaterialController::class, 'index']);

    // Course assignments
    Route::get('/{courseId}/assignments', [AssignmentController::class, 'index']);

    // Course gradebook
    Route::get('/{courseId}/gradebook', [GradebookController::class, 'courseGradebook']);
    Route::get('/{courseId}/statistics', [GradebookController::class, 'courseStatistics']);
    Route::get('/{courseId}/top-performers', [GradebookController::class, 'topPerformers']);

    // User grades in course
    Route::get('/{courseId}/users/{userId}/grades', [GradebookController::class, 'userCourseGrades']);
});

// User-specific routes
Route::prefix('users')->group(function () {
    // User quiz attempts
    Route::get('/{userId}/quiz-attempts', [QuizController::class, 'userAttempts']);

    // User grades
    Route::get('/{userId}/grades', [GradebookController::class, 'userGrades']);

    // User performance summary
    Route::get('/{userId}/performance', [GradebookController::class, 'userPerformance']);
});

// Grades management
Route::put('/grades/{id}', [GradebookController::class, 'update']);
