<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->foreignId('grade_item_id')->nullable()->after('course_id')
                ->constrained()->nullOnDelete();

            $table->index(['grade_item_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropIndex(['grade_item_id', 'user_id']);
            $table->dropConstrainedForeignId('grade_item_id');
        });
    }
};
