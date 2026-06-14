<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_items', function (Blueprint $table) {
            $table->foreignId('grade_category_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sort_order')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('grade_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grade_category_id');
            $table->dropColumn('sort_order');
        });
    }
};
