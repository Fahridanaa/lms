<?php

use App\Models\Assignment;
use App\Models\LearningModule;
use App\Models\Material;
use App\Models\Quiz;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('module_type', 32);
            $table->unsignedBigInteger('module_id');
            $table->boolean('visible')->default(true);
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('completion_enabled')->default(false);
            $table->string('completion_rule')->nullable();
            $table->timestamps();

            $table->unique(['module_type', 'module_id']);
            $table->index(['course_id', 'visible', 'sort_order']);
            $table->index(['available_from', 'available_until']);
        });

        $sortOrder = 1;

        Quiz::query()->select(['id', 'course_id', 'created_at', 'updated_at'])->orderBy('id')->each(function (Quiz $quiz) use (&$sortOrder): void {
            LearningModule::query()->create([
                'course_id' => $quiz->course_id,
                'module_type' => LearningModule::TYPE_QUIZ,
                'module_id' => $quiz->id,
                'sort_order' => $sortOrder++,
                'created_at' => $quiz->created_at,
                'updated_at' => $quiz->updated_at,
            ]);
        });

        Material::query()->select(['id', 'course_id', 'created_at', 'updated_at'])->orderBy('id')->each(function (Material $material) use (&$sortOrder): void {
            LearningModule::query()->create([
                'course_id' => $material->course_id,
                'module_type' => LearningModule::TYPE_MATERIAL,
                'module_id' => $material->id,
                'sort_order' => $sortOrder++,
                'created_at' => $material->created_at,
                'updated_at' => $material->updated_at,
            ]);
        });

        Assignment::query()->select(['id', 'course_id', 'created_at', 'updated_at'])->orderBy('id')->each(function (Assignment $assignment) use (&$sortOrder): void {
            LearningModule::query()->create([
                'course_id' => $assignment->course_id,
                'module_type' => LearningModule::TYPE_ASSIGNMENT,
                'module_id' => $assignment->id,
                'sort_order' => $sortOrder++,
                'created_at' => $assignment->created_at,
                'updated_at' => $assignment->updated_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_modules');
    }
};
