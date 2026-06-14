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
        Schema::create('course_completion_criteria', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('criteriatype', 32);
            $table->unsignedBigInteger('module_instance_id')->nullable();
            $table->unsignedBigInteger('grade_item_id')->nullable();
            $table->decimal('pass_threshold', 10, 2)->nullable();
            $table->timestamp('time_end')->nullable();
            $table->timestamps();

            $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->foreign('module_instance_id')->references('id')->on('learning_modules')->onDelete('set null');
            $table->foreign('grade_item_id')->references('id')->on('grade_items')->onDelete('set null');
            $table->index('course_id');
            $table->index(['course_id', 'criteriatype']);
        });

        Schema::create('course_completion_criterion_completions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_completion_criterion_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['course_completion_criterion_id', 'user_id'], 'cc_criterion_user_unique');
            $table->index('user_id');

            $table->foreign('course_completion_criterion_id', 'cc_criterion_fk')
                ->references('id')->on('course_completion_criteria')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('course_completions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('timeenrolled')->nullable();
            $table->timestamp('timestarted')->nullable();
            $table->timestamp('timecompleted')->nullable();
            $table->boolean('reaggregate')->default(false);
            $table->timestamps();

            $table->unique(['course_id', 'user_id']);
            $table->index(['user_id', 'timecompleted']);

            $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_completions');
        Schema::dropIfExists('course_completion_criterion_completions');
        Schema::dropIfExists('course_completion_criteria');
    }
};
