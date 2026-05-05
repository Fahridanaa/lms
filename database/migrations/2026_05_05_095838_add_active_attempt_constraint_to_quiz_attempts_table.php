<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->tinyInteger('active_attempt')->virtualAs('IF(completed_at IS NULL, 1, NULL)')->nullable()->after('completed_at');
            $table->unique(['user_id', 'quiz_id', 'active_attempt'], 'quiz_attempts_user_quiz_active_unique');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropUnique('quiz_attempts_user_quiz_active_unique');
            $table->dropColumn('active_attempt');
        });
    }
};
