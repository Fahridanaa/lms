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
        Schema::table('quizzes', function (Blueprint $table) {
            $table->unsignedInteger('grace_period')->default(0)->after('available_until');
            $table->string('overdue_handling', 32)->default('auto_submit')->after('grace_period');
            $table->unsignedInteger('delay_between_attempts')->default(0)->after('overdue_handling');
            $table->string('review_visibility', 32)->default('after_submission')->after('delay_between_attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn([
                'grace_period',
                'overdue_handling',
                'delay_between_attempts',
                'review_visibility',
            ]);
        });
    }
};
