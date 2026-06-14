<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $table->string('action'); // created, updated, deleted
            $table->decimal('old_score', 10, 2)->nullable();
            $table->decimal('new_score', 10, 2)->nullable();
            $table->decimal('old_percentage', 10, 2)->nullable();
            $table->decimal('new_percentage', 10, 2)->nullable();
            $table->string('old_status', 50)->nullable();
            $table->string('new_status', 50)->nullable();
            $table->text('old_feedback')->nullable();
            $table->text('new_feedback')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['grade_id', 'action'], 'grade_histories_grade_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_histories');
    }
};
