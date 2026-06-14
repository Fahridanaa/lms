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
        Schema::create('assignment_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_group_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('available_from')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->timestamp('cutoff_date')->nullable();
            $table->unsignedInteger('max_attempts')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['assignment_id', 'user_id']);
            $table->index(['assignment_id', 'course_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assignment_overrides');
    }
};
