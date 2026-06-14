<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quiz_attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_question_slot_id')->constrained();
            $table->foreignId('question_id')->constrained();
            $table->unsignedInteger('slot');
            $table->decimal('max_points', 10, 2)->default(0);
            $table->decimal('score', 10, 2)->nullable();
            $table->string('state')->default('not_answered');
            $table->timestamps();

            $table->unique(['quiz_attempt_id', 'slot'], 'qa_questions_attempt_slot_unique');
        });

        Schema::create('quiz_attempt_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_question_id')->constrained('quiz_attempt_questions')->cascadeOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('state');
            $table->decimal('score', 10, 2)->nullable();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->timestamps();

            $table->unique(['quiz_attempt_question_id', 'sequence_number'], 'qa_steps_qaq_seq_unique');
        });

        Schema::create('quiz_attempt_step_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_step_id')->constrained('quiz_attempt_steps')->cascadeOnDelete();
            $table->string('name');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->index(['quiz_attempt_step_id', 'name'], 'qa_step_data_step_name_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_step_data');
        Schema::dropIfExists('quiz_attempt_steps');
        Schema::dropIfExists('quiz_attempt_questions');
    }
};
