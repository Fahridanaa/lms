<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->index(['user_id', 'quiz_id']);
            $table->index('completed_at');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->index(['assignment_id', 'user_id']);
            $table->index('graded_at');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->index('due_date');
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'quiz_id']);
            $table->dropIndex(['completed_at']);
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['assignment_id', 'user_id']);
            $table->dropIndex(['graded_at']);
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropIndex(['due_date']);
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->dropIndex(['type']);
        });
    }
};
