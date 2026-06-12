<?php

use App\Models\Question;
use App\Models\QuizQuestionSlot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_question_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('slot');
            $table->unsignedInteger('page')->default(1);
            $table->decimal('max_points', 8, 2)->default(1);
            $table->boolean('require_previous')->default(false);
            $table->timestamps();

            $table->unique(['quiz_id', 'slot']);
            $table->unique(['quiz_id', 'question_id']);
            $table->index(['quiz_id', 'page']);
        });

        Question::query()
            ->orderBy('quiz_id')
            ->orderBy('id')
            ->get()
            ->groupBy('quiz_id')
            ->each(function ($questions): void {
                $questions->values()->each(function (Question $question, int $index): void {
                    QuizQuestionSlot::query()->create([
                        'quiz_id' => $question->quiz_id,
                        'question_id' => $question->id,
                        'slot' => $index + 1,
                        'page' => 1,
                        'max_points' => $question->points,
                        'created_at' => $question->created_at,
                        'updated_at' => $question->updated_at,
                    ]);
                });
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_question_slots');
    }
};
