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
        Schema::table('submissions', function (Blueprint $table) {
            $table->timestamp('returned_at')->nullable()->after('graded_at');
            $table->timestamp('reopened_at')->nullable()->after('returned_at');
            $table->boolean('late')->default(false)->after('reopened_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn(['returned_at', 'reopened_at', 'late']);
        });
    }
};
