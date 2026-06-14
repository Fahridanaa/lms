<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marker_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('score', 10, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->string('workflow_state')->default('pending');
            $table->timestamps();

            $table->unique(['submission_id', 'marker_id'], 'assignment_marks_sub_marker_unique');
            $table->index('marker_id', 'assignment_marks_marker_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_marks');
    }
};
