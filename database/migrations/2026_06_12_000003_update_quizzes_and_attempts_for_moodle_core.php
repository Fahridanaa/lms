<?php

use App\Models\QuizAttempt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->timestamp('available_from')->nullable()->after('is_active');
            $table->timestamp('available_until')->nullable()->after('available_from');
            $table->unsignedInteger('max_attempts')->default(0)->after('available_until');
            $table->enum('grading_method', ['highest', 'latest', 'average', 'first'])->default('highest')->after('max_attempts');
            $table->boolean('shuffle_questions')->default(false)->after('grading_method');
            $table->boolean('shuffle_answers')->default(false)->after('shuffle_questions');
            $table->index(['course_id', 'is_active'], 'quizzes_course_active_index');
            $table->index(['available_from', 'available_until'], 'quizzes_availability_index');
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->enum('status', ['in_progress', 'submitted', 'finished', 'expired'])->default('in_progress')->after('score');
            $table->unsignedInteger('attempt_number')->default(1)->after('status');
            $table->timestamp('submitted_at')->nullable()->after('completed_at');
            $table->timestamp('expires_at')->nullable()->after('submitted_at');
        });

        QuizAttempt::query()
            ->withTrashed()
            ->orderBy('quiz_id')
            ->orderBy('user_id')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (QuizAttempt $attempt): string => "{$attempt->quiz_id}:{$attempt->user_id}")
            ->each(function ($attempts): void {
                $attempts->values()->each(function (QuizAttempt $attempt, int $index): void {
                    $attempt->forceFill([
                        'attempt_number' => $index + 1,
                        'status' => $attempt->completed_at === null ? 'in_progress' : 'finished',
                        'submitted_at' => $attempt->completed_at,
                    ])->save();
                });
            });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->unique(['quiz_id', 'user_id', 'attempt_number'], 'quiz_attempts_quiz_user_number_unique');
            $table->index(['quiz_id', 'user_id', 'status'], 'quiz_attempts_quiz_user_status_index');
            $table->index('expires_at', 'quiz_attempts_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropUnique('quiz_attempts_quiz_user_number_unique');
            $table->dropIndex('quiz_attempts_quiz_user_status_index');
            $table->dropIndex('quiz_attempts_expires_at_index');
            $table->dropColumn(['status', 'attempt_number', 'submitted_at', 'expires_at']);
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropIndex('quizzes_course_active_index');
            $table->dropIndex('quizzes_availability_index');
            $table->dropColumn([
                'available_from',
                'available_until',
                'max_attempts',
                'grading_method',
                'shuffle_questions',
                'shuffle_answers',
            ]);
        });
    }
};
