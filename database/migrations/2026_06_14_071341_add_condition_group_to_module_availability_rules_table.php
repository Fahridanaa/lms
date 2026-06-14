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
        Schema::table('module_availability_rules', function (Blueprint $table) {
            $table->unsignedInteger('condition_group')->nullable()->after('course_group_id');
            $table->index('condition_group');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('module_availability_rules', function (Blueprint $table) {
            $table->dropColumn('condition_group');
        });
    }
};
